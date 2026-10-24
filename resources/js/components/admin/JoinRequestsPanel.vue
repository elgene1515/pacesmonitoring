<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { adminService } from '../../services';
import { useAuthStore } from '../../stores/auth';
import { useScopeStore } from '../../stores/scope';
import { notify } from '../../stores/notifications';
import { formatDate, timeAgo } from '../../core/format';
import AppField from '../ui/AppField.vue';
import AppModal from '../ui/AppModal.vue';
import EmptyState from '../ui/EmptyState.vue';
import AppIcon from '../ui/AppIcon.vue';
import StatusBadge from '../ui/StatusBadge.vue';

/**
 * The people asking to join the school (teachers, and admin-type accounts
 * that want to join as administrators): a table of requests (pending by
 * default) with view / accept / decline, the full request in a dialog, the
 * accept-as prompt for administrator requests (held to the plan's admin
 * limit) and the decline-with-reason prompt. Loads itself; reloads when the
 * owner's scope changes.
 *
 *  props   schoolName  String   named in the dialog when a request has no branch
 *          organizationId Number|null  only this organization's requests (the owner's organization page); null = the scope bar's / the school's own
 *          searchSchool Boolean   the search also matches the school's name (the owner's home page)
 *          branchFilter Boolean   adds a branch dropdown (the branches of the requests listed; the owner's home page)
 *  emits   decided(JoinRequest)  after an accept or decline (the parent may refresh its lists)
 */
const props = defineProps({
    schoolName: { type: String, default: '' },
    organizationId: { type: Number, default: null },
    searchSchool: { type: Boolean, default: false },
    branchFilter: { type: Boolean, default: false },
});

const emit = defineEmits(['decided']);

const auth = useAuthStore();
const scope = useScopeStore();

const canWrite = computed(() => auth.canWrite);

/** The school's own membership (counts and plan), known for a school admin; the server enforces the limit anyway. */
const organization = computed(() => (auth.isAdmin ? auth.organization : null));
const adminLimitReached = computed(() => Boolean(organization.value?.adminLimitReached));
const adminLimitNote = computed(() => {
    if (!organization.value?.adminLimit) {
        return '';
    }

    const limit = organization.value.adminLimit;

    return adminLimitReached.value
        ? `Your plan allows ${limit === 1 ? '1 administrator' : `up to ${limit} administrators`} and you already have ${organization.value.adminsCount}. Accept them as a teacher, or upgrade the plan.`
        : `Your plan allows ${limit === 1 ? '1 administrator' : `up to ${limit} administrators`}; you have ${organization.value.adminsCount}.`;
});

/** An administrator request waiting for the accept-as choice, and the role picked. */
const acceptingRequest = ref(null);
const acceptAs = ref('admin');

const requests = ref([]);
const requestsLoading = ref(false);
const requestsLoaded = ref(false);
/** Search (name and email, and the school's name on the owner's home page), status and branch filters; pending requests first by default. */
const requestSearch = ref('');
const statusFilter = ref('pending');
const branchFilterId = ref('');

const STATUS_OPTIONS = [
    { value: 'pending', label: 'Pending' },
    { value: 'all', label: 'All statuses' },
    { value: 'accepted', label: 'Accepted' },
    { value: 'declined', label: 'Declined' },
];

/** The platform owner also sees which school a request is for (branch names repeat across schools). */
const showSchool = computed(() => auth.isPlatform);
const deciding = ref(null);
const decliningRequest = ref(null);
const declineReason = ref('');

/** The request whose full details are open in the dialog. */
const viewingRequest = ref(null);

function openView(request) {
    viewingRequest.value = request;
}

/** StatusBadge tone for a request. */
function requestTone(request) {
    if (request.isPending) {
        return 'warn';
    }

    return request.isAccepted ? 'ok' : (request.isDeclined ? 'bad' : 'muted');
}

/** The decision line of a decided request: "Accepted by Maria Santos · Oct 22, 2026". */
function decisionLine(request) {
    if (request.isPending) {
        return '';
    }

    const by = request.decidedBy?.name ? ` by ${request.decidedBy.name}` : '';
    const when = formatDate(request.decidedAt);

    return `${request.statusLabel}${by}${when ? ` · ${when}` : ''}`;
}

const hasRequestFilters = computed(() => requestSearch.value.trim() !== '' || statusFilter.value !== 'pending' || branchFilterId.value !== '');

/** The branches the loaded requests are for (with the school's name when requests of several schools can be here). */
const branchOptions = computed(() => {
    const map = new Map();

    for (const request of requests.value) {
        if (request.branchId && !map.has(request.branchId)) {
            map.set(request.branchId, { id: request.branchId, label: [request.branchName, showSchool.value ? request.organizationName : ''].filter(Boolean).join(' · ') });
        }
    }

    return [...map.values()].sort((a, b) => a.label.localeCompare(b.label));
});

const visibleRequests = computed(() => {
    const term = requestSearch.value.trim().toLowerCase();

    return requests.value.filter((request) => {
        if (statusFilter.value !== 'all' && request.status !== statusFilter.value) {
            return false;
        }

        if (branchFilterId.value !== '' && Number(request.branchId) !== Number(branchFilterId.value)) {
            return false;
        }

        if (term === '') {
            return true;
        }

        return [request.userName, request.user?.email, props.searchSchool ? request.organizationName : '']
            .some((text) => String(text ?? '').toLowerCase().includes(term));
    });
});
const pendingCount = computed(() => requests.value.filter((request) => request.isPending).length);

const requestsEmptyMessage = computed(() => {
    if (requests.value.length > 0 && hasRequestFilters.value) {
        return 'No requests match your filters.';
    }

    return requests.value.length === 0 && statusFilter.value !== 'pending'
        ? 'Nobody has asked to join yet.'
        : 'No pending requests. Teachers (and administrators) ask to join with the school code on the Organization page.';
});

function clearRequestFilters() {
    requestSearch.value = '';
    statusFilter.value = 'pending';
    branchFilterId.value = '';
}

/** "Main Campus · Grace Christian Academy" for the platform owner, the branch alone otherwise. */
function whereLabel(request) {
    return [request.branchName, showSchool.value ? request.organizationName : ''].filter(Boolean).join(' · ');
}

async function loadRequests() {
    requestsLoading.value = true;

    try {
        requests.value = await adminService.listJoinRequests(null, props.organizationId ? { organization_id: props.organizationId } : {});
        requestsLoaded.value = true;
    } catch (err) {
        notify.error(err?.message ?? 'Failed to load join requests.');
    } finally {
        requestsLoading.value = false;
    }
}

/** Accept: a teacher request goes straight through; an administrator request asks which role to give. */
function accept(request) {
    if (request.wantsAdmin) {
        acceptingRequest.value = request;
        acceptAs.value = adminLimitReached.value ? 'teacher' : 'admin';
        return;
    }

    return acceptAs_(request, 'teacher');
}

function confirmAccept() {
    const request = acceptingRequest.value;

    if (request) {
        return acceptAs_(request, acceptAs.value);
    }
}

async function acceptAs_(request, role) {
    deciding.value = request.id;

    try {
        await adminService.acceptJoinRequest(request.id, role);
        notify.success(`${request.userName} now belongs to ${request.branchName || 'your school'} as ${role === 'admin' ? 'an administrator' : 'a teacher'}.`);
        acceptingRequest.value = null;
        viewingRequest.value = null;
        await loadRequests();
        emit('decided', request);
        auth.refresh().catch(() => {});
    } catch (err) {
        notify.error(err?.isValidation ? (err.first('request') ?? err.message) : (err?.message ?? 'Unable to accept the request.'));
    } finally {
        deciding.value = null;
    }
}

function openDecline(request) {
    decliningRequest.value = request;
    declineReason.value = '';
}

async function decline() {
    const request = decliningRequest.value;

    if (!request) {
        return;
    }

    deciding.value = request.id;

    try {
        await adminService.declineJoinRequest(request.id, declineReason.value.trim());
        notify.info(`${request.userName}'s request was declined.`);
        decliningRequest.value = null;
        viewingRequest.value = null;
        await loadRequests();
        emit('decided', request);
        auth.refresh().catch(() => {});
    } catch (err) {
        notify.error(err?.message ?? 'Unable to decline the request.');
    } finally {
        deciding.value = null;
    }
}

watch(() => scope.organizationId, loadRequests);
watch(() => props.organizationId, loadRequests);

onMounted(loadRequests);

defineExpose({ reload: loadRequests, pendingCount });
</script>

<template>
    <section class="card list-section">
        <div class="toolbar">
            <div>
                <h2 class="card-title">
                    Join requests
                    <span v-if="pendingCount" class="count-badge">{{ pendingCount }}</span>
                </h2>
                <p class="muted card-hint">{{ auth.isPlatform ? 'People who entered a school code and asked to join one of its branches, as a teacher or as an administrator.' : 'People who entered your school code and asked to join a branch, as a teacher or as an administrator.' }}</p>
            </div>
            <div class="request-filters">
                <input v-model="requestSearch" class="input request-search" type="search" :placeholder="searchSchool ? 'Search name, email or school' : 'Search name or email'" aria-label="Search join requests" />
                <select v-if="branchFilter" v-model="branchFilterId" class="input request-branch" aria-label="Filter join requests by branch">
                    <option value="">All branches</option>
                    <option v-for="branch in branchOptions" :key="branch.id" :value="branch.id">{{ branch.label }}</option>
                </select>
                <select v-model="statusFilter" class="input request-status" aria-label="Filter join requests by status">
                    <option v-for="option in STATUS_OPTIONS" :key="option.value" :value="option.value">{{ option.label }}</option>
                </select>
                <button v-if="hasRequestFilters" type="button" class="btn btn-link btn-sm" @click="clearRequestFilters">Clear</button>
            </div>
        </div>

        <p v-if="requestsLoading && !requestsLoaded" class="muted state">Loading…</p>

        <EmptyState v-else-if="visibleRequests.length === 0" :message="requestsEmptyMessage" />

        <div v-else class="table-wrap">
            <table class="table" :class="{ 'with-school': showSchool }">
                <colgroup>
                    <col />
                    <col v-if="showSchool" class="col-school" />
                    <col class="col-branch" />
                    <col class="col-message" />
                    <col class="col-date" />
                    <col class="col-status" />
                    <col class="col-request-actions" />
                </colgroup>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th v-if="showSchool">School</th>
                        <th>Branch</th>
                        <th>Message</th>
                        <th>Requested</th>
                        <th>Status</th>
                        <th class="table-actions"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="request in visibleRequests" :key="request.id">
                        <td class="ellipsis">
                            <span class="name">{{ request.userName }}</span>
                            <span class="muted small">{{ request.user?.email }}</span>
                            <span v-if="request.wantsAdmin" class="small as-admin">Wants to join as administrator</span>
                        </td>
                        <td v-if="showSchool" class="ellipsis" :title="request.organizationName">{{ request.organizationName || '—' }}</td>
                        <td class="ellipsis">{{ request.branchName || '—' }}</td>
                        <td class="message-cell">
                            <button
                                v-if="request.message"
                                type="button"
                                class="message-preview muted"
                                :title="`Read the whole message from ${request.userName}`"
                                @click="openView(request)"
                            >
                                {{ request.message }}
                            </button>
                            <span v-else class="muted">—</span>
                        </td>
                        <td class="nowrap">{{ formatDate(request.createdAt) || '—' }}</td>
                        <td>
                            <StatusBadge :tone="requestTone(request)">{{ request.statusLabel }}</StatusBadge>
                            <span v-if="request.isDeclined && request.declineReason" class="muted small" :title="request.declineReason">
                                {{ request.declineReason }}
                            </span>
                        </td>
                        <td class="table-actions">
                            <button
                                type="button"
                                class="btn btn-link btn-sm btn-icon"
                                :title="`View ${request.userName}'s request`"
                                :aria-label="`View ${request.userName}'s request`"
                                @click="openView(request)"
                            >
                                <AppIcon name="eye" />
                            </button>
                            <template v-if="request.isPending">
                                <button
                                    type="button"
                                    class="btn btn-link btn-sm btn-icon text-success"
                                    :title="deciding === request.id ? 'Saving…' : `Accept ${request.userName} into ${request.branchName || 'the school'}`"
                                    :aria-label="`Accept ${request.userName}'s request`"
                                    :disabled="!canWrite || deciding === request.id"
                                    @click="accept(request)"
                                >
                                    <AppIcon name="check" />
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-link btn-sm btn-icon text-danger"
                                    :title="`Decline ${request.userName}'s request`"
                                    :aria-label="`Decline ${request.userName}'s request`"
                                    :disabled="!canWrite || deciding === request.id"
                                    @click="openDecline(request)"
                                >
                                    <AppIcon name="close" />
                                </button>
                            </template>
                            <span v-else class="muted small">{{ request.decidedBy?.name ? `by ${request.decidedBy.name}` : '' }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- A join request in full -->
        <AppModal :model-value="viewingRequest !== null" title="Join request" width="520px" :closable="deciding === null" @update:model-value="viewingRequest = null">
            <div v-if="viewingRequest" class="request-detail">
                <dl class="request-info">
                    <div class="request-item">
                        <dt>Name</dt>
                        <dd>
                            <span class="name">{{ viewingRequest.userName || '—' }}</span>
                            <span v-if="viewingRequest.user?.email" class="muted small">{{ viewingRequest.user.email }}</span>
                        </dd>
                    </div>
                    <div class="request-item">
                        <dt>Account created</dt>
                        <dd>{{ formatDate(viewingRequest.user?.created_at) || '—' }}</dd>
                    </div>
                    <div class="request-item">
                        <dt>Wants to join</dt>
                        <dd>{{ whereLabel(viewingRequest) || schoolName || '—' }}</dd>
                    </div>
                    <div class="request-item">
                        <dt>As</dt>
                        <dd>
                            {{ viewingRequest.requestedRoleLabel }}
                            <span v-if="viewingRequest.wantsAdmin && viewingRequest.isPending" class="muted small">You pick the role when accepting.</span>
                        </dd>
                    </div>
                    <div class="request-item">
                        <dt>Requested</dt>
                        <dd>
                            {{ formatDate(viewingRequest.createdAt) || '—' }}
                            <span v-if="viewingRequest.createdAt" class="muted small">{{ timeAgo(viewingRequest.createdAt) }}</span>
                        </dd>
                    </div>
                    <div class="request-item">
                        <dt>Status</dt>
                        <dd>
                            <StatusBadge :tone="requestTone(viewingRequest)">{{ viewingRequest.statusLabel }}</StatusBadge>
                            <span v-if="decisionLine(viewingRequest)" class="muted small">{{ decisionLine(viewingRequest) }}</span>
                        </dd>
                    </div>
                    <div v-if="viewingRequest.isDeclined && viewingRequest.declineReason" class="request-item request-wide">
                        <dt>Reason given</dt>
                        <dd class="message-full">{{ viewingRequest.declineReason }}</dd>
                    </div>
                    <div class="request-item request-wide">
                        <dt>Message</dt>
                        <dd v-if="viewingRequest.message" class="message-full">{{ viewingRequest.message }}</dd>
                        <dd v-else class="muted">No message.</dd>
                    </div>
                </dl>

                <div class="actions">
                    <button type="button" class="btn btn-secondary" :disabled="deciding !== null" @click="viewingRequest = null">Close</button>
                    <template v-if="viewingRequest.isPending">
                        <button type="button" class="btn btn-secondary" :disabled="!canWrite || deciding !== null" @click="openDecline(viewingRequest)">Decline</button>
                        <button type="button" class="btn" :disabled="!canWrite || deciding !== null" @click="accept(viewingRequest)">
                            {{ deciding === viewingRequest.id ? 'Saving…' : (viewingRequest.wantsAdmin ? 'Accept as…' : 'Accept') }}
                        </button>
                    </template>
                </div>
            </div>
        </AppModal>

        <!-- An administrator request: accept as an administrator (within the plan) or as a teacher -->
        <AppModal :model-value="acceptingRequest !== null" title="Accept request" width="460px" :closable="deciding === null" @update:model-value="acceptingRequest = null">
            <form v-if="acceptingRequest" class="form" novalidate @submit.prevent="confirmAccept">
                <p class="muted">
                    <strong>{{ acceptingRequest.userName }}</strong> asked to join {{ acceptingRequest.branchName || 'your school' }} as an administrator.
                    Accept them as:
                </p>
                <div class="role-choice" role="radiogroup" aria-label="Accept as">
                    <label class="role-option" :class="{ disabled: adminLimitReached }">
                        <input v-model="acceptAs" type="radio" name="accept-as" value="admin" :disabled="adminLimitReached || deciding !== null" />
                        <span>
                            <span class="role-title">Administrator</span>
                            <span class="muted small">Manages the school with you: students, PACEs, grade levels, users.</span>
                            <span v-if="adminLimitNote" class="small" :class="adminLimitReached ? 'text-danger' : 'muted'">{{ adminLimitNote }}</span>
                        </span>
                    </label>
                    <label class="role-option">
                        <input v-model="acceptAs" type="radio" name="accept-as" value="teacher" :disabled="deciding !== null" />
                        <span>
                            <span class="role-title">Teacher</span>
                            <span class="muted small">Keeps their own students and records; you can make them an administrator later from their page.</span>
                        </span>
                    </label>
                </div>
                <div class="actions">
                    <button type="button" class="btn btn-secondary" :disabled="deciding !== null" @click="acceptingRequest = null">Cancel</button>
                    <button type="submit" class="btn" :disabled="!canWrite || deciding !== null">
                        {{ deciding !== null ? 'Saving…' : (acceptAs === 'admin' ? 'Accept as administrator' : 'Accept as teacher') }}
                    </button>
                </div>
            </form>
        </AppModal>

        <!-- Decline with a reason -->
        <AppModal :model-value="decliningRequest !== null" title="Decline request" width="440px" :closable="deciding === null" @update:model-value="decliningRequest = null">
            <form v-if="decliningRequest" class="form" novalidate @submit.prevent="decline">
                <p class="muted">
                    Decline <strong>{{ decliningRequest.userName }}</strong>'s request to join {{ decliningRequest.branchName || 'your school' }}?
                    They will see the reason and can send a new request later.
                </p>
                <AppField label="Reason" for="decline-reason" hint="Optional.">
                    <input id="decline-reason" v-model="declineReason" class="input" type="text" maxlength="255" :disabled="deciding !== null" />
                </AppField>
                <div class="actions">
                    <button type="button" class="btn btn-secondary" :disabled="deciding !== null" @click="decliningRequest = null">Cancel</button>
                    <button type="submit" class="btn btn-danger" :disabled="deciding !== null">
                        {{ deciding !== null ? 'Declining…' : 'Decline' }}
                    </button>
                </div>
            </form>
        </AppModal>
    </section>
</template>

<style scoped>
.list-section {
    padding: 0;
    overflow: hidden;
}

.toolbar {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px 16px;
    flex-wrap: wrap;
    padding: 12px 16px;
    border-bottom: 1px solid var(--border);
}

.toolbar .card-title {
    margin: 0;
}

.card-hint {
    margin: 2px 0 0;
    font-size: 0.85rem;
}

.count-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 20px;
    height: 20px;
    padding: 0 6px;
    margin-left: 6px;
    border-radius: 999px;
    background: var(--danger);
    color: var(--on-danger);
    font-size: 0.75rem;
    font-weight: 600;
    line-height: 1;
    vertical-align: middle;
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
    min-width: 720px;
}

.col-branch {
    width: 150px;
}

.col-school {
    width: 150px;
}

/* The extra School column: trim the others so the name keeps its room. */
.table.with-school {
    min-width: 940px;
}

.with-school .col-message {
    width: 22%;
}

.with-school .col-status {
    width: 130px;
}

.request-filters {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.request-filters .input {
    width: auto;
}

.request-search {
    min-width: 240px;
}

.request-branch {
    max-width: 260px;
}

.col-message {
    width: 30%;
}

.col-date {
    width: 120px;
}

.col-status {
    width: 150px;
}

/* View, accept and decline as icons, so a pending row never overflows. */
.col-request-actions {
    width: 110px;
}

.btn-link.text-success {
    color: var(--success);
}

.btn-link.text-success:hover {
    background: rgba(var(--success-rgb), 0.08);
}

.table-actions .btn + .btn {
    margin-left: 2px;
}

.name {
    font-weight: 500;
    display: block;
}

/* Marks a request for the administrator role in the row. */
.as-admin {
    color: var(--primary);
    font-weight: 500;
}

.role-choice {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.role-option {
    display: flex;
    gap: 10px;
    align-items: flex-start;
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    cursor: pointer;
}

.role-option:has(input:checked) {
    border-color: var(--primary);
    background: rgba(var(--primary-rgb), 0.05);
}

.role-option.disabled {
    cursor: not-allowed;
    opacity: 0.75;
}

.role-option input {
    margin-top: 3px;
}

.role-option > span {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}

.role-option .small {
    display: block;
    font-size: 0.82rem;
    white-space: normal;
}

.role-title {
    font-weight: 500;
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

/* The message is clipped to two lines in the row; clicking it opens the whole request. */
.message-cell {
    vertical-align: top;
}

.message-preview {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    max-width: 100%;
    padding: 0;
    border: none;
    background: transparent;
    font: inherit;
    line-height: 1.4;
    text-align: left;
    white-space: normal;
    overflow-wrap: anywhere;
    cursor: pointer;
}

.message-preview:hover {
    color: var(--text);
    text-decoration: underline;
}

.message-preview:focus-visible {
    outline: 2px solid var(--primary);
    outline-offset: 2px;
    border-radius: 4px;
}

.form {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.form .muted {
    margin: 0;
}

.actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
}

.request-detail {
    display: flex;
    flex-direction: column;
    gap: 18px;
}

.request-info {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px 24px;
    margin: 0;
}

.request-item dt {
    font-size: 0.85rem;
    color: var(--muted);
    margin-bottom: 2px;
}

.request-item dd {
    margin: 0;
    min-width: 0;
}

.request-item .small {
    display: block;
    font-size: 0.82rem;
}

.request-wide {
    grid-column: 1 / -1;
}

/* The whole message, as the teacher typed it. */
.message-full {
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--bg);
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    line-height: 1.5;
}

@media (max-width: 560px) {
    .request-info {
        grid-template-columns: 1fr;
    }
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
</style>
