<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { usePacesStore } from '../../stores/paces';
import { usePaceRequestsStore } from '../../stores/paceRequests';
import { notify } from '../../stores/notifications';
import { pluralize } from '../../core/format';
import PaceFormModal from '../paces/PaceFormModal.vue';
import PaceRequestFormModal from '../paces/PaceRequestFormModal.vue';
import StockControl from '../paces/StockControl.vue';
import ConfirmDialog from '../ui/ConfirmDialog.vue';
import EmptyState from '../ui/EmptyState.vue';
import AppIcon from '../ui/AppIcon.vue';
import PaceRecordsBar from '../charts/PaceRecordsBar.vue';
import PaceRecordsLegend from '../charts/PaceRecordsLegend.vue';

/** Bar segments and legend read tested / issued / assigned, left to right (same as the Grade levels page). */
const RECORD_ORDER = ['tested', 'issued', 'assigned'];

const RECORDS_OPTIONS = [
    { value: '', label: 'All PACEs' },
    { value: 'with', label: 'With records' },
    { value: 'without', label: 'Without records' },
];

const STOCK_OPTIONS = [
    { value: '', label: 'Any stock' },
    { value: 'in', label: 'In stock' },
    { value: 'low', label: 'Low stock' },
    { value: 'out', label: 'Out of stock' },
];

const paces = usePacesStore();
const paceRequests = usePaceRequestsStore();

const modalOpen = ref(false);
const editing = ref(null);
const deleting = ref(null);
const removing = ref(false);

const requestOpen = ref(false);
const requestFor = ref(null);

/** '' (all) | 'with' | 'without': narrows the loaded list by whether a PACE has records. */
const recordsFilter = ref('');

/** '' (any) | 'in' | 'low' | 'out': narrows the loaded list by stock level. */
const stockFilter = ref('');

let searchTimer = null;

const hasSearch = computed(() => paces.filters.search.trim() !== '');

const hasFilters = computed(() => hasSearch.value || recordsFilter.value !== '' || stockFilter.value !== '');

/** The loaded PACEs after the client-side records and stock filters. */
const visible = computed(() => {
    let list = paces.items;

    if (recordsFilter.value === 'with') {
        list = list.filter((pace) => (pace.assignmentsCount ?? 0) > 0);
    } else if (recordsFilter.value === 'without') {
        list = list.filter((pace) => (pace.assignmentsCount ?? 0) === 0);
    }

    if (stockFilter.value === 'in') {
        list = list.filter((pace) => !pace.isOutOfStock);
    } else if (stockFilter.value === 'low') {
        list = list.filter((pace) => pace.isLowStock);
    } else if (stockFilter.value === 'out') {
        list = list.filter((pace) => pace.isOutOfStock);
    }

    return list;
});

const outOfStockCount = computed(() => paces.items.filter((pace) => pace.isOutOfStock).length);

const emptyMessage = computed(() => (hasFilters.value ? 'No PACEs match your filters.' : 'No PACEs registered yet.'));

function openCreate() {
    editing.value = null;
    modalOpen.value = true;
}

function openEdit(pace) {
    editing.value = pace;
    modalOpen.value = true;
}

function openRequest(pace = null) {
    requestFor.value = pace;
    requestOpen.value = true;
}

function onSaved() {
    paces.fetch();
}

function onRequestSaved() {
    // The PACE's pending-request count comes from the server.
    paces.fetch();
}

function clearFilters() {
    recordsFilter.value = '';
    stockFilter.value = '';
    paces.resetFilters();
    paces.fetch();
}

async function confirmRemove() {
    if (removing.value || !deleting.value) {
        return;
    }

    const target = deleting.value;
    removing.value = true;

    try {
        await paces.remove(target.id);
        notify.success(`PACE ${target.label} deleted.`);
        deleting.value = null;
    } catch (err) {
        notify.error(err?.message ?? 'Unable to delete the PACE.');
    } finally {
        removing.value = false;
    }
}

watch(
    () => paces.filters.search,
    () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => paces.fetch(), 300);
    },
);

onMounted(() => {
    paces.fetch();
    paceRequests.fetch();
});

onBeforeUnmount(() => {
    clearTimeout(searchTimer);
});
</script>

<template>
    <div class="paces">
        <div class="page-header">
            <h1>PACEs</h1>
            <div class="page-actions">
                <RouterLink :to="{ name: 'pace-requests' }" class="btn btn-secondary">
                    Stock requests
                    <span
                        v-if="paceRequests.pendingCount > 0"
                        class="count-badge"
                        :title="pluralize(paceRequests.pendingCount, 'pending request')"
                    >
                        {{ paceRequests.pendingCount }}
                    </span>
                </RouterLink>
                <button type="button" class="btn btn-secondary" @click="openRequest()">Request stock</button>
                <button type="button" class="btn" @click="openCreate">Register PACE</button>
            </div>
        </div>

        <div class="card">
            <!-- Filters on the left; the PACE count and the colour key for the bars on the right. -->
            <div class="toolbar">
                <div class="toolbar-controls">
                    <input
                        v-model="paces.filters.search"
                        class="input search"
                        type="search"
                        placeholder="Search by number or name"
                        aria-label="Search PACEs"
                    />
                    <select v-model="recordsFilter" class="input select" aria-label="Filter by PACE records">
                        <option v-for="option in RECORDS_OPTIONS" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                    <select v-model="stockFilter" class="input select" aria-label="Filter by stock">
                        <option v-for="option in STOCK_OPTIONS" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                    <button v-if="hasFilters" type="button" class="btn btn-link btn-sm" @click="clearFilters">
                        Clear
                    </button>
                </div>
                <div class="toolbar-meta">
                    <span class="muted count nowrap">
                        <template v-if="paces.loading">Loading…</template>
                        <template v-else>
                            {{ pluralize(visible.length, 'PACE') }}
                            <template v-if="outOfStockCount > 0"> · <span class="text-danger">{{ outOfStockCount }} out of stock</span></template>
                        </template>
                    </span>
                    <PaceRecordsLegend v-if="visible.length > 0" :order="RECORD_ORDER" />
                </div>
            </div>

            <p v-if="paces.loading && !paces.loaded" class="muted state">Loading…</p>

            <EmptyState v-else-if="visible.length === 0" :message="emptyMessage">
                <template #action>
                    <button v-if="hasFilters" type="button" class="btn btn-secondary btn-sm" @click="clearFilters">
                        Clear filters
                    </button>
                    <button v-else type="button" class="btn btn-secondary btn-sm" @click="openCreate">Register PACE</button>
                </template>
            </EmptyState>

            <div v-else class="table-wrap">
                <table class="table">
                    <!-- Fixed column widths so every column lines up down the page. -->
                    <colgroup>
                        <col class="col-number" />
                        <col class="col-name" />
                        <col class="col-stock" />
                        <col class="col-records" />
                        <col class="col-actions" />
                    </colgroup>
                    <thead>
                        <tr>
                            <th>Number</th>
                            <th>Name</th>
                            <th>Stock</th>
                            <th>PACE records</th>
                            <th class="table-actions"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="pace in visible" :key="pace.id">
                            <td class="mono">
                                <RouterLink :to="{ name: 'pace-detail', params: { id: pace.id } }" class="number">
                                    #{{ pace.number }}
                                </RouterLink>
                            </td>
                            <td class="name">{{ pace.name }}</td>
                            <td class="stock-cell">
                                <StockControl :pace="pace" compact />
                                <RouterLink
                                    v-if="pace.hasPendingRequest"
                                    :to="{ name: 'pace-requests' }"
                                    class="small"
                                    :title="pluralize(pace.pendingRequestsCount, 'stock request') + ' waiting for your admin'"
                                >
                                    Request pending
                                </RouterLink>
                            </td>
                            <td class="records">
                                <PaceRecordsBar
                                    :total="pace.assignmentsCount"
                                    :tested="pace.testedCount"
                                    :issued="pace.issuedCount"
                                    :assigned="pace.assignedCount"
                                    :order="RECORD_ORDER"
                                    tooltip
                                />
                            </td>
                            <td class="table-actions">
                                <RouterLink
                                    :to="{ name: 'pace-detail', params: { id: pace.id } }"
                                    class="btn btn-link btn-sm btn-icon"
                                    :title="`View PACE ${pace.label}`"
                                    :aria-label="`View PACE ${pace.label}`"
                                >
                                    <AppIcon name="eye" />
                                </RouterLink>
                                <button
                                    type="button"
                                    class="btn btn-link btn-sm btn-icon"
                                    :title="`Request stock for PACE ${pace.label}`"
                                    :aria-label="`Request stock for PACE ${pace.label}`"
                                    @click="openRequest(pace)"
                                >
                                    <AppIcon name="box" />
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-link btn-sm btn-icon"
                                    :title="`Edit PACE ${pace.label}`"
                                    :aria-label="`Edit PACE ${pace.label}`"
                                    @click="openEdit(pace)"
                                >
                                    <AppIcon name="pencil" />
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-link btn-sm btn-icon text-danger"
                                    :title="`Delete PACE ${pace.label}`"
                                    :aria-label="`Delete PACE ${pace.label}`"
                                    @click="deleting = pace"
                                >
                                    <AppIcon name="trash" />
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <PaceFormModal v-model="modalOpen" :pace="editing" @saved="onSaved" />

        <PaceRequestFormModal v-model="requestOpen" :pace="requestFor" @saved="onRequestSaved" />

        <ConfirmDialog
            :model-value="deleting !== null"
            title="Delete PACE"
            :busy="removing"
            @update:model-value="deleting = null"
            @confirm="confirmRemove"
        >
            Delete PACE <strong v-if="deleting">{{ deleting.label }}</strong>?
            <template v-if="deleting && deleting.assignmentsCount">
                It is assigned to {{ pluralize(deleting.assignmentsCount, 'student record') }}; those records will be deleted as well.
            </template>
            This cannot be undone.
        </ConfirmDialog>
    </div>
</template>

<style scoped>
.card {
    padding: 0;
    overflow: hidden;
}

.count-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 18px;
    height: 18px;
    padding: 0 5px;
    margin-left: 2px;
    border-radius: 999px;
    background: var(--danger);
    color: var(--on-danger);
    font-size: 0.75rem;
    font-weight: 600;
    line-height: 1;
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
    flex: 1 1 auto;
}

.toolbar-controls .input {
    width: auto;
}

.search {
    flex: 1 1 200px;
    max-width: 280px;
}

.select {
    min-width: 140px;
}

.toolbar-meta {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    margin-left: auto;
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
    min-width: 780px;
    table-layout: fixed;
}

.col-number {
    width: 110px;
}

.col-stock {
    width: 230px;
}

.col-records {
    width: 150px;
}

.col-actions {
    width: 150px;
}

.number {
    font-weight: 500;
}

.name {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.stock-cell .small {
    display: block;
    margin-top: 2px;
    font-size: 0.8rem;
}

.records {
    line-height: 1;
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
</style>
