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
 * The teachers' PACE stock requests: adjust them, mark them given (which adds
 * the booklets to the teacher's stock of that PACE) or decline them. Platform
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

/** True when the edit would detach the request from the teacher's registered PACE. */
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

    if (!Number.isInteger(quantity) || quantity < 1) {
        giveErrors.value = { quantity: ['Enter the number of booklets handed over (at least 1).'] };
        return;
    }

    savingGive.value = true;
    giveErrors.value = {};

    try {
        const given = await adminService.givePaceRequest(request.id, {
            quantity,
            decision_note: giveForm.decision_note.trim() || null,
        });
        notify.success(given.isLinked
            ? `${given.quantity} × PACE ${given.label} given to ${given.teacherName}; their stock is now ${given.pace?.stock ?? '—'}.`
            : `${given.quantity} × PACE ${given.label} given to ${given.teacherName}.`);
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
        <div class="page-header">
            <div>
                <h1>PACE requests</h1>
                <p class="muted subtitle">
                    Teachers asking for PACE booklets. Marking a request as given adds the booklets to the teacher's
                    stock of that PACE; you can adjust the quantity first.
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

            <p v-if="loading && !loaded" class="muted state">Loading…</p>

            <EmptyState v-else-if="requests.length === 0" :message="emptyMessage">
                <template v-if="hasFilters" #action>
                    <button type="button" class="btn btn-secondary btn-sm" @click="clearFilters">Clear filters</button>
                </template>
            </EmptyState>

            <div v-else class="table-wrap">
                <table class="table">
                    <colgroup>
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
                        <tr v-for="request in requests" :key="request.id">
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
                                <span v-if="request.isLinked && request.pace" class="muted small">Teacher's stock: {{ request.pace.stock }}</span>
                                <span v-else class="muted small">Not a registered PACE</span>
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
                    Changing the number or name detaches the request from the teacher's registered PACE, so marking it
                    given will no longer top up that PACE's stock.
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
                        The quantity is added to their stock of this PACE (now {{ giving.pace?.stock ?? 0 }}).
                    </template>
                    <template v-else>
                        This PACE is not registered by the teacher yet, so no stock is updated automatically.
                    </template>
                </p>
                <AppField label="Quantity given" for="give-request-quantity" :error="giveErrors.quantity" required>
                    <input id="give-request-quantity" v-model="giveForm.quantity" class="input quantity" type="number" min="1" step="1" :disabled="savingGive" />
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
    </div>
</template>

<style scoped>
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
    min-width: 1040px;
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

    .table td.table-actions .btn + .btn {
        margin-left: 6px;
    }
}
</style>
