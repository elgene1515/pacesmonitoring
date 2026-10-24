<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { adminService } from '../../../services';
import { useAuthStore } from '../../../stores/auth';
import { useScopeStore } from '../../../stores/scope';
import { notify } from '../../../stores/notifications';
import { formatDate, pluralize } from '../../../core/format';
import { PACE_REQUEST_STATUS_LABELS } from '../../../models';
import AppField from '../../ui/AppField.vue';
import AppModal from '../../ui/AppModal.vue';
import EmptyState from '../../ui/EmptyState.vue';
import StatusBadge from '../../ui/StatusBadge.vue';
import AppIcon from '../../ui/AppIcon.vue';

/**
 * The teachers' PACE stock requests: adjust them, give them (which moves the
 * booklets from the branch pool to the teacher, adding a new PACE to the
 * catalog first when needed) or decline them, one by one or several at once
 * (tick the pending rows: the oldest are given first, and any the pool cannot
 * cover stay pending with the reason shown). Platform
 * staff see the organization picked in the scope bar, or every organization.
 */
const STATUS_OPTIONS = [
    { value: '', label: 'All statuses' },
    ...Object.entries(PACE_REQUEST_STATUS_LABELS).map(([value, label]) => ({ value, label })),
];

const auth = useAuthStore();
const scope = useScopeStore();

const requests = ref([]);
const teachers = ref([]);
const branches = ref([]);
const loading = ref(false);
const loaded = ref(false);
/**
 * search: the teacher's name or email, or the PACE number or name;
 * needed_from / needed_to: the "needed by" date range (YYYY-MM-DD, either end may be empty).
 */
const filters = reactive({ status: 'pending', branch_id: '', teacher_id: '', search: '', needed_from: '', needed_to: '' });

const canWrite = computed(() => auth.canWrite);

/* ---------- selection: give or decline several requests at once ---------- */

const selectedIds = ref([]);
const selectAllBox = ref(null);

const pendingRows = computed(() => requests.value.filter((request) => request.isPending));
/** The ticked requests that are still in the list and still pending. */
const selectedRequests = computed(() => pendingRows.value.filter((request) => selectedIds.value.includes(request.id)));
const allSelected = computed(() => pendingRows.value.length > 0 && selectedRequests.value.length === pendingRows.value.length);
const someSelected = computed(() => selectedRequests.value.length > 0 && !allSelected.value);

watch(someSelected, (partly) => {
    if (selectAllBox.value) {
        selectAllBox.value.indeterminate = partly;
    }
}, { flush: 'post' });

function toggleAll() {
    selectedIds.value = allSelected.value ? [] : pendingRows.value.map((request) => request.id);
}

function toggleOne(id) {
    selectedIds.value = selectedIds.value.includes(id)
        ? selectedIds.value.filter((item) => item !== id)
        : [...selectedIds.value, id];
}

/** A filter or a decision can remove rows: keep only the ticks that are still pending rows. */
watch(requests, () => {
    selectedIds.value = selectedIds.value.filter((id) => pendingRows.value.some((request) => request.id === id));
});

/**
 * What giving the ticked requests would do, in the order the server uses (oldest first), each
 * against the pool of its PACE after the ones before it: given, not covered by the pool, or not
 * in the catalog yet (those are given one by one). The server decides for real; this is the preview.
 */
const batchPlan = computed(() => {
    const running = new Map();
    const oldestFirst = [...selectedRequests.value]
        .sort((a, b) => String(a.createdAt ?? '').localeCompare(String(b.createdAt ?? '')) || a.id - b.id);

    return oldestFirst.map((request) => {
        if (!request.isLinked || !request.pace) {
            return { request, state: 'new', note: 'Not in the catalog yet: give it on its own (it needs stock in the pool first).' };
        }

        const pool = running.has(request.paceId) ? running.get(request.paceId) : request.pace.poolStock;

        if (request.quantity <= pool) {
            running.set(request.paceId, pool - request.quantity);

            return { request, state: 'ok', note: `Pool ${pool} → ${pool - request.quantity}` };
        }

        return { request, state: 'short', note: `The pool has ${pool}; ${request.quantity} asked for` };
    });
});

const plannedCount = computed(() => batchPlan.value.filter((row) => row.state === 'ok').length);
const plannedBooklets = computed(() => batchPlan.value.filter((row) => row.state === 'ok').reduce((sum, row) => sum + row.request.quantity, 0));

const batchGiveOpen = ref(false);
const batchDeclineOpen = ref(false);
const batchNote = ref('');
const batchBusy = ref(false);
/** What the last batch could not do: {action, skipped: [{id, teacherName, label, quantity, reason}]} */
const batchResult = ref(null);

function openBatch(action) {
    batchNote.value = '';

    if (action === 'give') {
        batchGiveOpen.value = true;
    } else {
        batchDeclineOpen.value = true;
    }
}

async function runBatch(action) {
    if (batchBusy.value || selectedRequests.value.length === 0) {
        return;
    }

    batchBusy.value = true;

    try {
        const result = await adminService.decidePaceRequests(action, selectedRequests.value.map((request) => request.id), batchNote.value.trim());
        const verb = action === 'give' ? 'given' : 'declined';

        batchGiveOpen.value = false;
        batchDeclineOpen.value = false;

        if (result.done.length > 0) {
            notify.success(`${pluralize(result.done.length, 'request')} ${verb}.`);
        } else {
            notify.error(`No request could be ${verb}.`);
        }

        // Whatever was skipped stays ticked (it is still pending), and the reasons are shown.
        selectedIds.value = result.skipped.map((row) => row.id);
        batchResult.value = result.skipped.length > 0 ? { action, skipped: result.skipped } : null;

        await refresh();
    } catch (err) {
        notify.error(err?.isValidation ? (err.first('ids') ?? err.message) : (err?.message ?? 'Unable to decide the requests.'));
    } finally {
        batchBusy.value = false;
    }
}
const hasFilters = computed(() => filters.status !== 'pending'
    || filters.branch_id !== ''
    || filters.teacher_id !== ''
    || filters.search.trim() !== ''
    || filters.needed_from !== ''
    || filters.needed_to !== '');

/**
 * Branch names repeat across schools (every school has a "Main"), so the branch
 * filter is only offered when one school is in view: a school admin's own, or
 * the school the owner picked in the scope bar.
 */
const showBranchFilter = computed(() => (!auth.isPlatform || scope.isScoped) && branches.value.length > 1);

const emptyMessage = computed(() => {
    if (!hasFilters.value) {
        return 'No pending requests. Teachers ask for PACE stock from their PACEs page.';
    }

    return 'No requests match the filters.';
});

async function load() {
    loading.value = true;

    try {
        requests.value = await adminService.listPaceRequests({
            status: filters.status,
            branch_id: filters.branch_id,
            teacher_id: filters.teacher_id,
            search: filters.search.trim(),
            needed_from: filters.needed_from,
            needed_to: filters.needed_to,
        });
        loaded.value = true;
    } catch (err) {
        notify.error(err?.message ?? 'Failed to load the PACE requests.');
    } finally {
        loading.value = false;
    }
}

/** The teachers offered in the filter follow the selected branch. */
async function loadTeachers() {
    try {
        teachers.value = await adminService.listTeachers({ branch_id: filters.branch_id });

        if (filters.teacher_id !== '' && !teachers.value.some((teacher) => teacher.id === Number(filters.teacher_id))) {
            filters.teacher_id = '';
        }
    } catch {
        teachers.value = [];
    }
}

async function loadBranches() {
    try {
        branches.value = await adminService.listBranches();
    } catch {
        branches.value = [];
    }
}

function clearFilters() {
    filters.status = 'pending';
    filters.branch_id = '';
    filters.teacher_id = '';
    filters.search = '';
    filters.needed_from = '';
    filters.needed_to = '';
}

/** Reload the list and the badge count in the top bar after a decision. */
async function refresh() {
    await load();
    auth.refresh().catch(() => {});
}

/* ---------- edit ---------- */

const editing = ref(null);
const editForm = reactive({ pace_number: '', pace_name: '', quantity: 1, needed_by: '' });
const editErrors = ref({});
const savingEdit = ref(false);

function openEdit(request) {
    editing.value = request;
    editForm.pace_number = request.paceNumber;
    editForm.pace_name = request.paceName;
    editForm.quantity = request.quantity;
    editForm.needed_by = request.neededBy ?? '';
    editErrors.value = {};
}

/** True when the edit would detach the request from the catalog PACE it points at. */
const editDetaches = computed(() => Boolean(editing.value?.isLinked)
    && (String(editForm.pace_number).trim() !== editing.value.paceNumber || String(editForm.pace_name).trim() !== editing.value.paceName));

async function saveEdit() {
    const request = editing.value;

    if (!request || savingEdit.value) {
        return;
    }

    const found = {};
    const quantity = Number(editForm.quantity);

    if (!/^[0-9]+$/.test(String(editForm.pace_number).trim())) {
        found.pace_number = ['The PACE number must contain digits only.'];
    }

    if (!String(editForm.pace_name).trim()) {
        found.pace_name = ['Enter the PACE name.'];
    }

    if (!Number.isInteger(quantity) || quantity < 1) {
        found.quantity = ['Enter a quantity of at least 1.'];
    }

    editErrors.value = found;

    if (Object.keys(found).length > 0) {
        return;
    }

    savingEdit.value = true;

    try {
        await adminService.updatePaceRequest(request.id, {
            pace_number: String(editForm.pace_number).trim(),
            pace_name: String(editForm.pace_name).trim(),
            quantity,
            needed_by: editForm.needed_by || null,
        });
        notify.success('Request updated.');
        editing.value = null;
        await load();
    } catch (err) {
        if (err?.isValidation && err.hasFieldErrors) {
            editErrors.value = err.errors;

            if (err.errors.request) {
                notify.error(err.first('request'));
            }
        } else {
            notify.error(err?.message ?? 'Unable to update the request.');
        }
    } finally {
        savingEdit.value = false;
    }
}

/* ---------- give ---------- */

const giving = ref(null);
const giveForm = reactive({ quantity: 1, decision_note: '' });
const giveErrors = ref({});
const savingGive = ref(false);

function openGive(request) {
    giving.value = request;
    giveForm.quantity = request.quantity;
    giveForm.decision_note = '';
    giveErrors.value = {};
}

async function confirmGive() {
    const request = giving.value;

    if (!request || savingGive.value) {
        return;
    }

    const quantity = Number(giveForm.quantity);

    if (!Number.isInteger(quantity) || quantity < 0) {
        giveErrors.value = { quantity: ['Enter the number of booklets handed over (0 or more).'] };
        return;
    }

    if (request.isLinked && request.pace && quantity > request.pace.poolStock) {
        giveErrors.value = { quantity: [`The pool only has ${request.pace.poolStock} of this PACE. Receive more first, or give fewer.`] };
        return;
    }

    savingGive.value = true;
    giveErrors.value = {};

    try {
        const given = await adminService.givePaceRequest(request.id, {
            quantity,
            decision_note: giveForm.decision_note.trim() || null,
        });
        if (quantity === 0) {
            notify.success(`PACE ${given.label} is in the catalog now; no booklets were handed over to ${given.teacherName}.`);
        } else {
            notify.success(`${quantity} × PACE ${given.label} given to ${given.teacherName}; the pool is now ${given.pace?.poolStock ?? '—'}.`);
        }
        giving.value = null;
        await refresh();
    } catch (err) {
        if (err?.isValidation && err.hasFieldErrors) {
            giveErrors.value = err.errors;

            if (err.errors.request) {
                notify.error(err.first('request'));
            }
        } else {
            notify.error(err?.message ?? 'Unable to mark the request as given.');
        }
    } finally {
        savingGive.value = false;
    }
}

/* ---------- decline ---------- */

const declining = ref(null);
const declineNote = ref('');
const savingDecline = ref(false);

function openDecline(request) {
    declining.value = request;
    declineNote.value = '';
}

async function confirmDecline() {
    const request = declining.value;

    if (!request || savingDecline.value) {
        return;
    }

    savingDecline.value = true;

    try {
        await adminService.declinePaceRequest(request.id, declineNote.value.trim());
        notify.info(`${request.teacherName}'s request for PACE ${request.label} was declined.`);
        declining.value = null;
        await refresh();
    } catch (err) {
        notify.error(err?.isValidation ? (err.first('request') ?? err.message) : (err?.message ?? 'Unable to decline the request.'));
    } finally {
        savingDecline.value = false;
    }
}

/** "Given by Ana Cruz · Oct 19, 2026" etc. for decided rows. */
function decisionLine(request) {
    if (request.isPending) {
        return '';
    }

    const by = request.deciderName ? ` by ${request.deciderName}` : '';
    const when = formatDate(request.decidedAt);

    return `${request.statusLabel}${by}${when ? ` · ${when}` : ''}`;
}

watch(() => [filters.status, filters.branch_id, filters.teacher_id, filters.needed_from, filters.needed_to], load);

let searchTimer = null;

watch(() => filters.search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(load, 300);
});

onBeforeUnmount(() => {
    clearTimeout(searchTimer);
});

watch(() => filters.branch_id, loadTeachers);

watch(() => scope.organizationId, () => {
    filters.branch_id = '';
    filters.teacher_id = '';
    loadBranches();
    loadTeachers();
    load();
});

onMounted(() => {
    loadBranches();
    loadTeachers();
    load();
});
</script>

<template>
    <div class="admin-pace-requests">
        <p class="back">
            <RouterLink :to="{ name: 'admin-paces' }">&larr; PACEs</RouterLink>
        </p>

        <div class="page-header">
            <div>
                <h1>Stock requests</h1>
                <p class="muted subtitle">
                    Teachers asking for PACE booklets. Giving a request moves the booklets from the branch pool to the
                    teacher; you can adjust the quantity first.
                </p>
            </div>
        </div>

        <div class="card">
            <div class="toolbar">
                <div class="toolbar-controls">
                    <input
                        v-model="filters.search"
                        class="input search"
                        type="search"
                        placeholder="Search teacher or PACE"
                        aria-label="Search by teacher name or PACE number or name"
                    />
                    <select v-model="filters.status" class="input select" aria-label="Filter by status">
                        <option v-for="option in STATUS_OPTIONS" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                    <select v-if="showBranchFilter" v-model="filters.branch_id" class="input select" aria-label="Filter by branch">
                        <option value="">All branches</option>
                        <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
                    </select>
                    <select v-if="teachers.length" v-model="filters.teacher_id" class="input select" aria-label="Filter by teacher">
                        <option value="">All teachers</option>
                        <option v-for="teacher in teachers" :key="teacher.id" :value="teacher.id">{{ teacher.name }}</option>
                    </select>
                    <label class="date-filter" title="Only requests with a needed-by date inside the range">
                        <span class="muted">Needed from</span>
                        <input v-model="filters.needed_from" class="input date-input" type="date" :max="filters.needed_to || undefined" aria-label="Needed by from date" />
                    </label>
                    <label class="date-filter">
                        <span class="muted">to</span>
                        <input v-model="filters.needed_to" class="input date-input" type="date" :min="filters.needed_from || undefined" aria-label="Needed by to date" />
                    </label>
                    <button v-if="hasFilters" type="button" class="btn btn-link btn-sm" @click="clearFilters">Clear</button>
                </div>
                <span class="muted count nowrap">{{ loading && !loaded ? 'Loading…' : pluralize(requests.length, 'request') }}</span>
            </div>

            <div v-if="selectedRequests.length > 0" class="selection-bar" role="region" aria-label="Selected requests">
                <span class="selection-count">{{ pluralize(selectedRequests.length, 'request') }} selected</span>
                <div class="selection-actions">
                    <button type="button" class="btn btn-sm" :disabled="!canWrite" @click="openBatch('give')">Give selected</button>
                    <button type="button" class="btn btn-secondary btn-sm text-danger" :disabled="!canWrite" @click="openBatch('decline')">Decline selected</button>
                    <button type="button" class="btn btn-link btn-sm" @click="selectedIds = []">Clear selection</button>
                </div>
            </div>

            <p v-if="loading && !loaded" class="muted state">Loading…</p>

            <EmptyState v-else-if="requests.length === 0" :message="emptyMessage">
                <template v-if="hasFilters" #action>
                    <button type="button" class="btn btn-secondary btn-sm" @click="clearFilters">Clear filters</button>
                </template>
            </EmptyState>

            <div v-else class="table-wrap">
                <table class="table">
                    <colgroup>
                        <col class="col-select" />
                        <col class="col-teacher" />
                        <col class="col-pace" />
                        <col class="col-qty" />
                        <col class="col-date" />
                        <col class="col-note" />
                        <col class="col-date" />
                        <col class="col-status" />
                        <col class="col-actions" />
                    </colgroup>
                    <thead>
                        <tr>
                            <th class="select-cell">
                                <input
                                    ref="selectAllBox"
                                    type="checkbox"
                                    :checked="allSelected"
                                    :disabled="pendingRows.length === 0 || !canWrite"
                                    aria-label="Select all pending requests in the list"
                                    title="Select all pending requests in the list"
                                    @change="toggleAll"
                                />
                            </th>
                            <th>Teacher</th>
                            <th>PACE</th>
                            <th class="text-right">Qty</th>
                            <th>Needed by</th>
                            <th>Note</th>
                            <th>Requested</th>
                            <th>Status</th>
                            <th class="table-actions"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="request in requests" :key="request.id" :class="{ selected: selectedIds.includes(request.id) }">
                            <td class="select-cell">
                                <input
                                    v-if="request.isPending"
                                    type="checkbox"
                                    :checked="selectedIds.includes(request.id)"
                                    :disabled="!canWrite"
                                    :aria-label="`Select ${request.teacherName}'s request for PACE ${request.label}`"
                                    @change="toggleOne(request.id)"
                                />
                            </td>
                            <td class="ellipsis" data-label="Teacher">
                                <RouterLink v-if="request.userId" :to="{ name: 'admin-user-detail', params: { id: request.userId } }" class="name">
                                    {{ request.teacherName || '—' }}
                                </RouterLink>
                                <span v-else class="name">{{ request.teacherName || '—' }}</span>
                                <span class="muted small">
                                    {{ [auth.isPlatform ? request.organizationName : '', request.teacherBranch].filter(Boolean).join(' · ') || request.teacherEmail }}
                                </span>
                            </td>
                            <td class="ellipsis" data-label="PACE">
                                <RouterLink v-if="request.isLinked" :to="{ name: 'admin-pace-detail', params: { id: request.paceId } }" class="name">
                                    {{ request.label }}
                                </RouterLink>
                                <span v-else class="name">{{ request.label }}</span>
                                <span v-if="request.isLinked && request.pace" class="muted small">Pool: {{ request.pace.poolStock }}</span>
                                <span v-else class="muted small">Not in the catalog yet</span>
                            </td>
                            <td class="mono text-right" data-label="Quantity">{{ request.quantity }}</td>
                            <td class="nowrap" data-label="Needed by">{{ formatDate(request.neededBy) || '—' }}</td>
                            <td class="ellipsis muted" data-label="Note" :title="request.message">{{ request.message || '—' }}</td>
                            <td class="nowrap" data-label="Requested">{{ formatDate(request.createdAt) || '—' }}</td>
                            <td data-label="Status">
                                <StatusBadge :tone="request.tone">{{ request.statusLabel }}</StatusBadge>
                                <span v-if="decisionLine(request)" class="muted small">{{ decisionLine(request) }}</span>
                                <span v-if="request.decisionNote" class="muted small" :title="request.decisionNote">“{{ request.decisionNote }}”</span>
                            </td>
                            <td class="table-actions">
                                <template v-if="request.isPending">
                                    <button type="button" class="btn btn-sm" :disabled="!canWrite" @click="openGive(request)">Give</button>
                                    <button
                                        type="button"
                                        class="btn btn-link btn-sm btn-icon"
                                        :disabled="!canWrite"
                                        :title="`Edit ${request.teacherName}'s request`"
                                        :aria-label="`Edit ${request.teacherName}'s request`"
                                        @click="openEdit(request)"
                                    >
                                        <AppIcon name="pencil" />
                                    </button>
                                    <button type="button" class="btn btn-link btn-sm text-danger" :disabled="!canWrite" @click="openDecline(request)">
                                        Decline
                                    </button>
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Edit a pending request -->
        <AppModal :model-value="editing !== null" title="Edit request" width="480px" :closable="!savingEdit" :close-on-backdrop="false" @update:model-value="editing = null">
            <form v-if="editing" class="form" novalidate @submit.prevent="saveEdit">
                <p class="muted">
                    <strong>{{ editing.teacherName }}</strong> asked for {{ editing.quantity }} × PACE {{ editing.label }}.
                </p>
                <div class="grid-2">
                    <AppField label="PACE number" for="edit-request-number" :error="editErrors.pace_number" required>
                        <input id="edit-request-number" v-model="editForm.pace_number" class="input" type="text" inputmode="numeric" maxlength="50" :disabled="savingEdit" />
                    </AppField>
                    <AppField label="PACE name" for="edit-request-name" :error="editErrors.pace_name" required>
                        <input id="edit-request-name" v-model="editForm.pace_name" class="input" type="text" :disabled="savingEdit" />
                    </AppField>
                </div>
                <div class="grid-2">
                    <AppField label="Quantity" for="edit-request-quantity" :error="editErrors.quantity" required>
                        <input id="edit-request-quantity" v-model="editForm.quantity" class="input" type="number" min="1" step="1" :disabled="savingEdit" />
                    </AppField>
                    <AppField label="Needed by" for="edit-request-needed" :error="editErrors.needed_by" hint="Optional.">
                        <input id="edit-request-needed" v-model="editForm.needed_by" class="input" type="date" :disabled="savingEdit" />
                    </AppField>
                </div>
                <p v-if="editDetaches" class="muted small-note">
                    Changing the number or name detaches the request from the catalog PACE it points at; giving it then
                    uses (or adds) the PACE with the new number and name.
                </p>
                <div class="actions">
                    <button type="button" class="btn btn-secondary" :disabled="savingEdit" @click="editing = null">Cancel</button>
                    <button type="submit" class="btn" :disabled="savingEdit">{{ savingEdit ? 'Saving…' : 'Save changes' }}</button>
                </div>
            </form>
        </AppModal>

        <!-- Mark as given -->
        <AppModal :model-value="giving !== null" title="Mark as given" width="440px" :closable="!savingGive" :close-on-backdrop="false" @update:model-value="giving = null">
            <form v-if="giving" class="form" novalidate @submit.prevent="confirmGive">
                <p class="muted">
                    Hand over PACE <strong>{{ giving.label }}</strong> to <strong>{{ giving.teacherName }}</strong>.
                    <template v-if="giving.isLinked">
                        The booklets move from the branch pool (now {{ giving.pace?.poolStock ?? 0 }}) to the teacher.
                    </template>
                    <template v-else>
                        This PACE is not in the branch catalog yet: giving adds it (with no pool stock), then hands the booklets
                        over. Receive the booklets into the pool first, or give 0 to only add the PACE to the catalog.
                    </template>
                </p>
                <AppField label="Quantity given" for="give-request-quantity" :error="giveErrors.quantity" required>
                    <input id="give-request-quantity" v-model="giveForm.quantity" class="input quantity" type="number" min="0" step="1" :disabled="savingGive" />
                </AppField>
                <AppField label="Note to the teacher" for="give-request-note" :error="giveErrors.decision_note" hint="Optional.">
                    <input id="give-request-note" v-model="giveForm.decision_note" class="input" type="text" maxlength="255" :disabled="savingGive" />
                </AppField>
                <div class="actions">
                    <button type="button" class="btn btn-secondary" :disabled="savingGive" @click="giving = null">Cancel</button>
                    <button type="submit" class="btn" :disabled="savingGive">{{ savingGive ? 'Saving…' : 'Mark as given' }}</button>
                </div>
            </form>
        </AppModal>

        <!-- Decline with a note -->
        <AppModal :model-value="declining !== null" title="Decline request" width="440px" :closable="!savingDecline" @update:model-value="declining = null">
            <form v-if="declining" class="form" novalidate @submit.prevent="confirmDecline">
                <p class="muted">
                    Decline <strong>{{ declining.teacherName }}</strong>'s request for {{ declining.quantity }} × PACE {{ declining.label }}?
                    They will see the note and can ask again later.
                </p>
                <AppField label="Note" for="decline-request-note" hint="Optional.">
                    <input id="decline-request-note" v-model="declineNote" class="input" type="text" maxlength="255" :disabled="savingDecline" />
                </AppField>
                <div class="actions">
                    <button type="button" class="btn btn-secondary" :disabled="savingDecline" @click="declining = null">Cancel</button>
                    <button type="submit" class="btn btn-danger" :disabled="savingDecline">{{ savingDecline ? 'Declining…' : 'Decline' }}</button>
                </div>
            </form>
        </AppModal>

        <!-- Give the selected requests -->
        <AppModal :model-value="batchGiveOpen" title="Give the selected requests" width="680px" :closable="!batchBusy" :close-on-backdrop="false" @update:model-value="batchGiveOpen = false">
            <form v-if="batchGiveOpen" class="form" novalidate @submit.prevent="runBatch('give')">
                <p class="muted">
                    Each request is given the quantity that was asked for, from its branch pool, oldest first. A request the pool cannot cover
                    stays pending.
                </p>

                <div class="plan-wrap">
                    <table class="table plan">
                        <thead>
                            <tr>
                                <th>Teacher</th>
                                <th>PACE</th>
                                <th class="text-right">Qty</th>
                                <th>What happens</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in batchPlan" :key="row.request.id" :class="`plan-${row.state}`">
                                <td class="ellipsis">{{ row.request.teacherName || '—' }}</td>
                                <td class="ellipsis">{{ row.request.label }}</td>
                                <td class="mono text-right">{{ row.request.quantity }}</td>
                                <td>
                                    <StatusBadge :tone="row.state === 'ok' ? 'ok' : 'warn'">{{ row.state === 'ok' ? 'Will be given' : 'Stays pending' }}</StatusBadge>
                                    <span class="muted small">{{ row.note }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p class="plan-summary">
                    <strong>{{ pluralize(plannedCount, 'request') }}</strong> will be given ({{ pluralize(plannedBooklets, 'booklet') }}<template v-if="plannedCount < batchPlan.length">,
                    {{ batchPlan.length - plannedCount }} will stay pending</template>).
                </p>

                <AppField label="Note to the teachers" for="batch-give-note" hint="Optional; every teacher sees it.">
                    <input id="batch-give-note" v-model="batchNote" class="input" type="text" maxlength="255" :disabled="batchBusy" />
                </AppField>

                <div class="actions">
                    <button type="button" class="btn btn-secondary" :disabled="batchBusy" @click="batchGiveOpen = false">Cancel</button>
                    <button type="submit" class="btn" :disabled="batchBusy || plannedCount === 0">
                        {{ batchBusy ? 'Giving…' : `Give ${pluralize(plannedCount, 'request')}` }}
                    </button>
                </div>
            </form>
        </AppModal>

        <!-- Decline the selected requests -->
        <AppModal :model-value="batchDeclineOpen" title="Decline the selected requests" width="520px" :closable="!batchBusy" @update:model-value="batchDeclineOpen = false">
            <form v-if="batchDeclineOpen" class="form" novalidate @submit.prevent="runBatch('decline')">
                <p class="muted">
                    Decline <strong>{{ pluralize(selectedRequests.length, 'request') }}</strong> from
                    {{ pluralize(new Set(selectedRequests.map((request) => request.userId)).size, 'teacher') }}? They will see the note and can ask again later.
                </p>
                <ul class="decline-list">
                    <li v-for="request in selectedRequests" :key="request.id">
                        <strong>{{ request.teacherName }}</strong>: {{ request.quantity }} × PACE {{ request.label }}
                    </li>
                </ul>
                <AppField label="Note" for="batch-decline-note" hint="Optional; every teacher sees it.">
                    <input id="batch-decline-note" v-model="batchNote" class="input" type="text" maxlength="255" :disabled="batchBusy" />
                </AppField>
                <div class="actions">
                    <button type="button" class="btn btn-secondary" :disabled="batchBusy" @click="batchDeclineOpen = false">Cancel</button>
                    <button type="submit" class="btn btn-danger" :disabled="batchBusy">
                        {{ batchBusy ? 'Declining…' : `Decline ${pluralize(selectedRequests.length, 'request')}` }}
                    </button>
                </div>
            </form>
        </AppModal>

        <!-- What the last batch could not do -->
        <AppModal :model-value="batchResult !== null" title="Some requests were left as they were" width="560px" @update:model-value="batchResult = null">
            <div v-if="batchResult" class="form">
                <p class="muted">
                    {{ pluralize(batchResult.skipped.length, 'request') }} could not be {{ batchResult.action === 'give' ? 'given' : 'declined' }}
                    and {{ batchResult.skipped.length === 1 ? 'is' : 'are' }} still ticked in the list.
                </p>
                <ul class="decline-list">
                    <li v-for="row in batchResult.skipped" :key="row.id">
                        <strong>{{ row.teacherName }}</strong>: {{ row.quantity }} × PACE {{ row.label }}
                        <span class="muted small">{{ row.reason }}</span>
                    </li>
                </ul>
                <div class="actions">
                    <button type="button" class="btn" @click="batchResult = null">OK</button>
                </div>
            </div>
        </AppModal>
    </div>
</template>

<style scoped>
.back {
    margin: 0;
    font-size: 0.92rem;
}

.admin-pace-requests {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.page-header {
    margin-bottom: 0;
}

.subtitle {
    margin: 4px 0 0;
    max-width: 680px;
}

.card {
    padding: 0;
    overflow: hidden;
}

.toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px 16px;
    flex-wrap: wrap;
    padding: 12px 16px;
    border-bottom: 1px solid var(--border);
}

.toolbar-controls {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.toolbar-controls .input {
    width: auto;
}

.select {
    min-width: 150px;
}

.search {
    flex: 1 1 200px;
    max-width: 260px;
}

.date-filter {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 0.92rem;
}

.date-input {
    min-width: 150px;
}

.count {
    font-size: 0.92rem;
}

.state {
    margin: 0;
    padding: 24px 16px;
    text-align: center;
}

.table-wrap {
    overflow-x: auto;
}

.table {
    table-layout: fixed;
    min-width: 1084px;
}

.selection-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px 16px;
    flex-wrap: wrap;
    padding: 10px 16px;
    border-bottom: 1px solid var(--border);
    background: rgba(var(--primary-rgb), 0.08);
}

.selection-count {
    font-weight: 500;
}

.selection-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.select-cell {
    text-align: center;
}

.col-select {
    width: 44px;
}

.table tbody tr.selected {
    background: rgba(var(--primary-rgb), 0.06);
}

/* The preview of what a batch give would do. */
.plan-wrap {
    max-height: 300px;
    overflow: auto;
    border: 1px solid var(--border);
    border-radius: var(--radius);
}

.plan {
    min-width: 0;
    table-layout: auto;
}

.plan td .small {
    display: block;
    font-size: 0.82rem;
}

.plan-summary {
    margin: 0;
}

.decline-list {
    margin: 0;
    padding-left: 20px;
    max-height: 220px;
    overflow-y: auto;
}

.decline-list li {
    margin-bottom: 4px;
}

.decline-list .small {
    display: block;
    font-size: 0.85rem;
}

.col-teacher {
    width: 150px;
}

.col-pace {
    width: 170px;
}

.col-qty {
    width: 50px;
}

.col-date {
    width: 100px;
}

.col-note {
    width: 130px;
}

.col-status {
    width: 170px;
}

.col-actions {
    width: 170px;
}

.name {
    font-weight: 500;
    display: block;
}

.ellipsis {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

td .small {
    display: block;
    font-size: 0.82rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.table-actions {
    white-space: nowrap;
}

.table-actions .btn + .btn {
    margin-left: 4px;
}

.form {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.form p {
    margin: 0;
}

.small-note {
    font-size: 0.85rem;
}

.quantity {
    max-width: 160px;
}

.actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 6px;
}

.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    border: 0;
}

/* Phones: each request becomes a labelled card, so Give / Edit / Decline never hide off to the right. */
@media (max-width: 720px) {
    .table {
        min-width: 0;
    }

    .table thead,
    .table colgroup {
        display: none;
    }

    .table,
    .table tbody,
    .table tr,
    .table td {
        display: block;
        width: 100%;
    }

    .table tr {
        padding: 12px 16px;
        border-bottom: 1px solid var(--border);
    }

    .table tbody tr:last-child {
        border-bottom: none;
    }

    .table tbody tr:hover {
        background: transparent;
    }

    .table td {
        padding: 4px 0;
        border: none;
        text-align: left;
    }

    .table td[data-label]::before {
        content: attr(data-label);
        display: block;
        font-size: 0.78rem;
        color: var(--muted);
    }

    .table td.ellipsis,
    .table td.nowrap {
        white-space: normal;
        overflow: visible;
        text-overflow: clip;
    }

    /* The short fields (quantity, dates) sit side by side. */
    .table td.mono,
    .table td.nowrap {
        display: inline-block;
        width: 32%;
        vertical-align: top;
    }

    .table td.table-actions {
        padding-top: 10px;
        text-align: left;
        white-space: normal;
    }

    .table td.select-cell {
        text-align: left;
    }

    .table th.select-cell,
    .table td.select-cell:empty {
        display: none;
    }

    .table td.table-actions .btn + .btn {
        margin-left: 6px;
    }
}
</style>
