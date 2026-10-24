<script setup>
import { computed, onMounted, ref } from 'vue';
import { usePaceRequestsStore } from '../../stores/paceRequests';
import { notify } from '../../stores/notifications';
import { formatDate, pluralize, toIsoDate } from '../../core/format';
import { PACE_REQUEST_STATUS_LABELS } from '../../models';
import PaceRequestFormModal from '../paces/PaceRequestFormModal.vue';
import BatchPaceRequestModal from '../paces/BatchPaceRequestModal.vue';
import ConfirmDialog from '../ui/ConfirmDialog.vue';
import EmptyState from '../ui/EmptyState.vue';
import StatusBadge from '../ui/StatusBadge.vue';
import AppIcon from '../ui/AppIcon.vue';

/**
 * The teacher's PACE stock requests: what was asked, what the admin decided,
 * kept as history. New requests (one or several PACEs) go through the
 * Request Stock dialog; pending requests can still be edited or withdrawn.
 */
const STATUS_OPTIONS = [
    { value: '', label: 'All requests' },
    ...Object.entries(PACE_REQUEST_STATUS_LABELS).map(([value, label]) => ({ value, label })),
];

const requests = usePaceRequestsStore();

const statusFilter = ref('');
/** The edit dialog for one pending request. */
const formOpen = ref(false);
const editing = ref(null);
/** The Request Stock dialog for new requests. */
const batchOpen = ref(false);
const withdrawing = ref(null);
const busy = ref(false);

/** Requested date range ("YYYY-MM-DD", either end may be empty): narrows the list by the day each request was sent. */
const dateFrom = ref('');
const dateTo = ref('');

const hasFilters = computed(() => statusFilter.value !== '' || dateFrom.value !== '' || dateTo.value !== '');

/** The range as typed, put in order when the start was typed after the end. */
const range = computed(() => {
    const from = dateFrom.value;
    const to = dateTo.value;

    return from && to && from > to ? { from: to, to: from } : { from, to };
});

const visible = computed(() => requests.items.filter((request) => {
    if (statusFilter.value && request.status !== statusFilter.value) {
        return false;
    }

    if (range.value.from || range.value.to) {
        const sent = toIsoDate(request.createdAt);

        if (!sent || (range.value.from && sent < range.value.from) || (range.value.to && sent > range.value.to)) {
            return false;
        }
    }

    return true;
}));

const emptyMessage = computed(() => {
    if (!hasFilters.value) {
        return 'You have not asked for PACE stock yet.';
    }

    return dateFrom.value || dateTo.value ? 'No requests match your filters.' : 'No requests with that status.';
});

function clearFilters() {
    statusFilter.value = '';
    dateFrom.value = '';
    dateTo.value = '';
}

function openEdit(request) {
    editing.value = request;
    formOpen.value = true;
}

async function confirmWithdraw() {
    const target = withdrawing.value;

    if (!target || busy.value) {
        return;
    }

    busy.value = true;

    try {
        await requests.cancel(target.id);
        notify.info(`Your request for PACE ${target.label} was withdrawn.`);
        withdrawing.value = null;
    } catch (err) {
        notify.error(err?.isValidation ? (err.first('request') ?? err.message) : (err?.message ?? 'Unable to withdraw the request.'));
    } finally {
        busy.value = false;
    }
}

/** "Given by Ana Cruz · Oct 19, 2026", "Declined by …", or "Withdrawn · …". */
function decisionLine(request) {
    if (request.isPending) {
        return '';
    }

    const by = request.deciderName ? ` by ${request.deciderName}` : '';
    const when = formatDate(request.decidedAt);

    return `${request.statusLabel}${by}${when ? ` · ${when}` : ''}`;
}

onMounted(() => {
    requests.fetch();
});
</script>

<template>
    <div class="pace-requests">
        <p class="back">
            <RouterLink :to="{ name: 'paces' }">&larr; PACEs</RouterLink>
        </p>

        <div class="page-header">
            <div>
                <h1>Stock requests</h1>
                <p class="muted subtitle">
                    Ask your school admin for PACE booklets. When a request is given, the booklets move from the
                    branch pool to your own stock; a PACE that is not in the catalog yet is added to it.
                </p>
            </div>
            <div class="page-actions">
                <button type="button" class="btn" @click="batchOpen = true">Request Stock</button>
            </div>
        </div>

        <div class="card">
            <div class="toolbar">
                <div class="toolbar-controls">
                    <select v-model="statusFilter" class="input select" aria-label="Filter by status">
                        <option v-for="option in STATUS_OPTIONS" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                    <label class="date-filter">
                        <span class="muted">Requested from</span>
                        <input v-model="dateFrom" class="input date-input" type="date" :max="dateTo || undefined" aria-label="Requested from date" />
                    </label>
                    <label class="date-filter">
                        <span class="muted">to</span>
                        <input v-model="dateTo" class="input date-input" type="date" :min="dateFrom || undefined" aria-label="Requested to date" />
                    </label>
                    <button v-if="hasFilters" type="button" class="btn btn-link btn-sm" @click="clearFilters">Clear</button>
                </div>
                <span class="muted count nowrap">
                    {{ requests.loading && !requests.loaded ? 'Loading…' : pluralize(visible.length, 'request') }}
                    <template v-if="requests.pendingCount > 0"> · {{ requests.pendingCount }} pending</template>
                </span>
            </div>

            <p v-if="requests.loading && !requests.loaded" class="muted state">Loading…</p>

            <EmptyState v-else-if="visible.length === 0" :message="emptyMessage">
                <template #action>
                    <button v-if="hasFilters" type="button" class="btn btn-secondary btn-sm" @click="clearFilters">Clear filters</button>
                    <button v-else type="button" class="btn btn-secondary btn-sm" @click="batchOpen = true">Request Stock</button>
                </template>
            </EmptyState>

            <div v-else class="table-wrap">
                <table class="table">
                    <colgroup>
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
                        <tr v-for="request in visible" :key="request.id">
                            <td class="ellipsis" data-label="PACE">
                                <RouterLink v-if="request.isLinked" :to="{ name: 'pace-detail', params: { id: request.paceId } }" class="name">
                                    {{ request.label }}
                                </RouterLink>
                                <span v-else class="name">{{ request.label }}</span>
                                <span v-if="!request.isLinked" class="muted small">Not in the catalog yet</span>
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
                                    <button
                                        type="button"
                                        class="btn btn-link btn-sm btn-icon"
                                        :title="`Edit the request for PACE ${request.label}`"
                                        :aria-label="`Edit the request for PACE ${request.label}`"
                                        @click="openEdit(request)"
                                    >
                                        <AppIcon name="pencil" />
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-link btn-sm btn-icon text-danger"
                                        :title="`Withdraw the request for PACE ${request.label}`"
                                        :aria-label="`Withdraw the request for PACE ${request.label}`"
                                        @click="withdrawing = request"
                                    >
                                        <AppIcon name="trash" />
                                    </button>
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <PaceRequestFormModal v-model="formOpen" :request="editing" />
        <BatchPaceRequestModal v-model="batchOpen" />

        <ConfirmDialog
            :model-value="withdrawing !== null"
            title="Withdraw request"
            confirm-text="Withdraw"
            :busy="busy"
            @update:model-value="withdrawing = null"
            @confirm="confirmWithdraw"
        >
            Withdraw your request for <strong v-if="withdrawing">{{ withdrawing.quantity }} × PACE {{ withdrawing.label }}</strong>?
            You can send a new one later.
        </ConfirmDialog>
    </div>
</template>

<style scoped>
.pace-requests {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.back {
    margin: 0;
    font-size: 0.92rem;
}

.page-header {
    margin-bottom: 0;
}

.subtitle {
    margin: 4px 0 0;
    max-width: 640px;
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
    gap: 8px 12px;
    flex-wrap: wrap;
}

.toolbar-controls .input {
    width: auto;
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

.select {
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
    min-width: 980px;
}

.col-pace {
    width: 220px;
}

.col-qty {
    width: 60px;
}

.col-date {
    width: 120px;
}

.col-note {
    width: 160px;
}

.col-status {
    width: 210px;
}

.col-actions {
    width: 90px;
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

.table-actions .btn + .btn {
    margin-left: 2px;
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

/* Phones: each request becomes a labelled card, so Edit / Withdraw never hide off to the right. */
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
