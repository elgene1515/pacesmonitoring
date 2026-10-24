<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { adminService, ownerService } from '../../../services';
import { useAuthStore } from '../../../stores/auth';
import { useScopeStore } from '../../../stores/scope';
import { notify } from '../../../stores/notifications';
import { pluralize } from '../../../core/format';
import { Pace } from '../../../models';
import PaceFormModal from '../../paces/PaceFormModal.vue';
import StockActionModal from '../../paces/StockActionModal.vue';
import BatchStockModal from '../../paces/BatchStockModal.vue';
import ConfirmDialog from '../../ui/ConfirmDialog.vue';
import SearchSelect from '../../ui/SearchSelect.vue';
import EmptyState from '../../ui/EmptyState.vue';
import StatusBadge from '../../ui/StatusBadge.vue';
import AppIcon from '../../ui/AppIcon.vue';
import PaceRecordsBar from '../../charts/PaceRecordsBar.vue';
import PaceRecordsLegend from '../../charts/PaceRecordsLegend.vue';

/**
 * The branches' PACE catalogs with their stock: the pool the admin keeps and
 * what the teachers hold. Booklets arrive into the pool and are handed out to
 * teachers from it (directly here, or by giving a teacher's stock request).
 */
const RECORD_ORDER = ['tested', 'issued', 'assigned'];

const RECORDS_OPTIONS = [
    { value: '', label: 'With or without records' },
    { value: 'with', label: 'With PACE records' },
    { value: 'without', label: 'Without PACE records' },
];

const POOL_OPTIONS = [
    { value: '', label: 'Any pool stock' },
    { value: 'in', label: 'In the pool' },
    { value: 'low', label: 'Pool running low' },
    { value: 'out', label: 'Pool empty' },
];

const auth = useAuthStore();
const scope = useScopeStore();

const paces = ref([]);
const loading = ref(true);
const loaded = ref(false);
const search = ref('');
const branches = ref([]);
const branchId = ref('');
const poolFilter = ref('');
const recordsFilter = ref('');

/**
 * The owner can narrow the catalogs to one school here (the scope bar does the same for the whole
 * admin area; while it is set, this filter and the school column are hidden). School admins never see them.
 */
const organizations = ref([]);
const organizationId = ref('');

const formOpen = ref(false);
const editing = ref(null);
const deleting = ref(null);
const removing = ref(false);

const stockOpen = ref(false);
const stockMode = ref('receive');
const stockPace = ref(null);
const stockTeachers = ref([]);

let searchTimer = null;

const showOrganizationFilter = computed(() => auth.isPlatform && !scope.isScoped);
const showOrganizationColumn = computed(() => showOrganizationFilter.value && organizationId.value === '');

/** Branch names repeat across schools, so for the owner the branch filter lists the chosen school's branches only. */
const filterBranches = computed(() => {
    if (!showOrganizationFilter.value) {
        return branches.value;
    }

    return organizationId.value === '' ? [] : branches.value.filter((branch) => String(branch.organizationId) === String(organizationId.value));
});
const showBranch = computed(() => (showOrganizationFilter.value ? true : branches.value.length > 1));

/** The school filter's choices: every school, or none picked. */
const schoolFilterOptions = computed(() => [{ value: '', label: 'All schools' }, ...organizations.value.map((organization) => ({ value: organization.id, label: organization.name }))]);

/** The school picker of the Register PACE form: only the owner, only without a school in the scope bar. */
const formOrganizations = computed(() => (showOrganizationFilter.value ? organizations.value : []));

/** The organization_id the page's own requests carry, when the school filter is in use. */
const organizationParams = () => (showOrganizationFilter.value && organizationId.value !== '' ? { organization_id: organizationId.value } : {});

const hasFilters = computed(() => search.value.trim() !== '' || branchId.value !== '' || poolFilter.value !== '' || recordsFilter.value !== '' || organizationId.value !== '');

const visible = computed(() => paces.value.filter((pace) => {
    if (recordsFilter.value === 'with' && !(pace.assignmentsCount > 0)) {
        return false;
    }

    if (recordsFilter.value === 'without' && pace.assignmentsCount > 0) {
        return false;
    }

    if (poolFilter.value === '') {
        return true;
    }

    const level = pace.poolLevel;

    return poolFilter.value === 'in' ? level !== 'out' : level === poolFilter.value;
}));

/* ---- Selection: change the pool of several PACEs at once ---- */

const selectedIds = ref([]);
const selectAllBox = ref(null);
const batchOpen = ref(false);
const batchMode = ref('receive');

/** The selected PACEs that are still in the list (a search or filter can hide some). */
const selectedPaces = computed(() => visible.value.filter((pace) => selectedIds.value.includes(pace.id)));
const allSelected = computed(() => visible.value.length > 0 && selectedPaces.value.length === visible.value.length);
const someSelected = computed(() => selectedPaces.value.length > 0 && !allSelected.value);

watch(someSelected, (partly) => {
    if (selectAllBox.value) {
        selectAllBox.value.indeterminate = partly;
    }
}, { flush: 'post' });

function toggleAll() {
    selectedIds.value = allSelected.value ? [] : visible.value.map((pace) => pace.id);
}

function toggleOne(id) {
    selectedIds.value = selectedIds.value.includes(id)
        ? selectedIds.value.filter((item) => item !== id)
        : [...selectedIds.value, id];
}

function openBatch(mode) {
    batchMode.value = mode;
    batchOpen.value = true;
}

function onBatchSaved() {
    selectedIds.value = [];
    load();
}

const totals = computed(() => visible.value.reduce(
    (sum, pace) => ({ pool: sum.pool + (Number(pace.poolStock) || 0), held: sum.held + (Number(pace.holdingsTotal) || 0) }),
    { pool: 0, held: 0 },
));

const pendingRequests = computed(() => auth.organization?.pendingPaceRequestsCount ?? 0);

const emptyMessage = computed(() => (hasFilters.value
    ? 'No PACEs match your filters.'
    : 'No PACEs in the catalog yet. Register the PACEs your branch uses, with the booklets you received.'));

async function load() {
    loading.value = true;

    try {
        paces.value = (await adminService.listPaces({ ...organizationParams(), search: search.value, branch_id: branchId.value })).sort(Pace.compare);
        loaded.value = true;
    } catch (err) {
        notify.error(err?.message ?? 'Failed to load PACEs.');
    } finally {
        loading.value = false;
    }
}

async function loadBranches() {
    try {
        branches.value = await adminService.listBranches();
    } catch {
        branches.value = [];
    }
}

async function loadOrganizations() {
    if (!auth.isPlatform) {
        return;
    }

    try {
        organizations.value = await ownerService.listOrganizations();
    } catch {
        organizations.value = [];
    }
}

/** Changing the school drops the branch filter, which then offers only that school's branches. */
function onOrganizationChange() {
    branchId.value = '';
    selectedIds.value = [];
    load();
}

function clearFilters() {
    search.value = '';
    branchId.value = '';
    poolFilter.value = '';
    recordsFilter.value = '';
    organizationId.value = '';
    load();
}

function openCreate() {
    editing.value = null;
    formOpen.value = true;
}

function openEdit(pace) {
    editing.value = pace;
    formOpen.value = true;
}

async function openStock(pace, mode) {
    stockPace.value = pace;
    stockMode.value = mode;
    stockTeachers.value = [];
    stockOpen.value = true;

    if (mode === 'distribute') {
        try {
            stockTeachers.value = await adminService.listTeachers({ branch_id: pace.branchId ?? '' });
        } catch (err) {
            notify.error(err?.message ?? 'Failed to load the teachers.');
        }
    }
}

function onStockSaved() {
    load();
}

async function confirmRemove() {
    const target = deleting.value;

    if (removing.value || !target) {
        return;
    }

    removing.value = true;

    try {
        await adminService.removePace(target.id);
        notify.success(`PACE ${target.label} deleted.`);
        deleting.value = null;
        load();
    } catch (err) {
        notify.error(err?.isValidation ? (Object.values(err.errors ?? {})[0]?.[0] ?? err.message) : (err?.message ?? 'Unable to delete the PACE.'));
    } finally {
        removing.value = false;
    }
}

watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(load, 300);
});

watch(() => scope.organizationId, () => {
    branchId.value = '';
    organizationId.value = '';
    loadBranches();
    load();
});

onMounted(() => {
    loadOrganizations();
    loadBranches();
    load();
});

onBeforeUnmount(() => {
    clearTimeout(searchTimer);
});
</script>

<template>
    <div class="admin-paces">
        <div class="page-header">
            <div>
                <h1>PACEs</h1>
                <p class="muted subtitle">
                    Each branch keeps its own PACE catalog. Booklets you receive go into the branch pool; teachers get
                    them from the pool and use one each time they issue a PACE to a student.
                </p>
            </div>
            <div class="page-actions">
                <RouterLink :to="{ name: 'admin-pace-requests' }" class="btn btn-secondary">
                    Stock requests
                    <span v-if="pendingRequests > 0" class="count-badge" :title="pluralize(pendingRequests, 'pending request')">{{ pendingRequests }}</span>
                </RouterLink>
                <button type="button" class="btn" :disabled="!auth.canWrite || branches.length === 0" @click="openCreate">Register PACE</button>
            </div>
        </div>

        <div class="card">
            <div class="toolbar">
                <div class="toolbar-controls">
                    <input v-model="search" class="input search" type="search" placeholder="Search by number or name" aria-label="Search PACEs" />
                    <SearchSelect
                        v-if="showOrganizationFilter"
                        v-model="organizationId"
                        class="school-select"
                        :options="schoolFilterOptions"
                        aria-label="Filter by school"
                        empty-text="No school matches"
                        @change="onOrganizationChange"
                    />
                    <select v-if="showBranch" v-model="branchId" class="input select" aria-label="Filter by branch" :disabled="showOrganizationFilter && organizationId === ''" @change="load">
                        <option value="">{{ showOrganizationFilter && organizationId === '' ? 'Pick a school first' : 'All branches' }}</option>
                        <option v-for="branch in filterBranches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
                    </select>
                    <select v-model="poolFilter" class="input select" aria-label="Filter by pool stock">
                        <option v-for="option in POOL_OPTIONS" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                    <select v-model="recordsFilter" class="input select" aria-label="Filter by PACE records">
                        <option v-for="option in RECORDS_OPTIONS" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                    <button v-if="hasFilters" type="button" class="btn btn-link btn-sm" @click="clearFilters">Clear</button>
                </div>
                <div class="toolbar-meta">
                    <span class="muted count nowrap">
                        <template v-if="loading && !loaded">Loading…</template>
                        <template v-else>
                            {{ pluralize(visible.length, 'PACE') }} · pool {{ totals.pool }} · held by teachers {{ totals.held }}
                        </template>
                    </span>
                    <PaceRecordsLegend v-if="visible.length > 0" :order="RECORD_ORDER" />
                </div>
            </div>

            <div v-if="selectedPaces.length > 0" class="selection-bar" role="region" aria-label="Selected PACEs">
                <span class="selection-count">{{ pluralize(selectedPaces.length, 'PACE') }} selected</span>
                <div class="selection-actions">
                    <button type="button" class="btn btn-sm" :disabled="!auth.canWrite" @click="openBatch('receive')">Booklets arrived</button>
                    <button type="button" class="btn btn-secondary btn-sm" :disabled="!auth.canWrite" @click="openBatch('adjust')">Add or remove</button>
                    <button type="button" class="btn btn-secondary btn-sm" :disabled="!auth.canWrite" @click="openBatch('set')">Set the pool to…</button>
                    <button type="button" class="btn btn-link btn-sm" @click="selectedIds = []">Clear selection</button>
                </div>
            </div>

            <p v-if="loading && !loaded" class="muted state">Loading…</p>

            <EmptyState v-else-if="visible.length === 0" :message="emptyMessage">
                <template #action>
                    <button v-if="hasFilters" type="button" class="btn btn-secondary btn-sm" @click="clearFilters">Clear filters</button>
                    <button v-else type="button" class="btn btn-secondary btn-sm" :disabled="!auth.canWrite || branches.length === 0" @click="openCreate">Register PACE</button>
                </template>
            </EmptyState>

            <div v-else class="table-wrap">
                <table class="table" :class="{ 'with-organization': showOrganizationColumn }">
                    <colgroup>
                        <col class="col-select" />
                        <col class="col-number" />
                        <col />
                        <col v-if="showOrganizationColumn" class="col-organization" />
                        <col v-if="showBranch" class="col-branch" />
                        <col class="col-pool" />
                        <col class="col-held" />
                        <col class="col-records" />
                        <col class="col-actions" />
                    </colgroup>
                    <thead>
                        <tr>
                            <th class="select-cell">
                                <input
                                    ref="selectAllBox"
                                    type="checkbox"
                                    :checked="allSelected"
                                    aria-label="Select all PACEs in the list"
                                    title="Select all PACEs in the list"
                                    @change="toggleAll"
                                />
                            </th>
                            <th>Number</th>
                            <th>Name</th>
                            <th v-if="showOrganizationColumn">School</th>
                            <th v-if="showBranch">Branch</th>
                            <th>Pool</th>
                            <th class="text-right">Held by teachers</th>
                            <th>PACE records</th>
                            <th class="table-actions"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="pace in visible" :key="pace.id" :class="{ selected: selectedIds.includes(pace.id) }">
                            <td class="select-cell">
                                <input
                                    type="checkbox"
                                    :checked="selectedIds.includes(pace.id)"
                                    :aria-label="`Select PACE ${pace.label}`"
                                    @change="toggleOne(pace.id)"
                                />
                            </td>
                            <td class="mono">
                                <RouterLink :to="{ name: 'admin-pace-detail', params: { id: pace.id } }" class="number">#{{ pace.number }}</RouterLink>
                            </td>
                            <td class="ellipsis">{{ pace.name }}</td>
                            <td v-if="showOrganizationColumn" class="ellipsis" :title="pace.organizationName">{{ pace.organizationName || '—' }}</td>
                            <td v-if="showBranch" class="ellipsis">{{ pace.branchName || '—' }}</td>
                            <td class="nowrap">
                                <span class="mono pool-value" :class="`level-${pace.poolLevel}`">{{ pace.poolStock }}</span>
                                <StatusBadge :tone="pace.poolTone">{{ pace.poolLabel }}</StatusBadge>
                            </td>
                            <td class="mono text-right" :title="pace.holdings.filter((holding) => holding.quantity > 0).map((holding) => `${holding.teacher?.name ?? 'Teacher'}: ${holding.quantity}`).join(', ')">
                                {{ pace.holdingsTotal ?? 0 }}
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
                                    :to="{ name: 'admin-pace-detail', params: { id: pace.id } }"
                                    class="btn btn-link btn-sm btn-icon"
                                    :title="`View PACE ${pace.label}`"
                                    :aria-label="`View PACE ${pace.label}`"
                                >
                                    <AppIcon name="eye" />
                                </RouterLink>
                                <button
                                    type="button"
                                    class="btn btn-link btn-sm btn-icon"
                                    :title="`Receive booklets of PACE ${pace.label}`"
                                    :aria-label="`Receive booklets of PACE ${pace.label}`"
                                    :disabled="!auth.canWrite"
                                    @click="openStock(pace, 'receive')"
                                >
                                    <AppIcon name="plus" />
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-link btn-sm btn-icon"
                                    :title="pace.hasPoolStock ? `Give booklets of PACE ${pace.label} to a teacher` : 'The pool is empty'"
                                    :aria-label="`Give booklets of PACE ${pace.label} to a teacher`"
                                    :disabled="!auth.canWrite || !pace.hasPoolStock"
                                    @click="openStock(pace, 'distribute')"
                                >
                                    <AppIcon name="box" />
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-link btn-sm btn-icon"
                                    :title="`Edit PACE ${pace.label}`"
                                    :aria-label="`Edit PACE ${pace.label}`"
                                    :disabled="!auth.canWrite"
                                    @click="openEdit(pace)"
                                >
                                    <AppIcon name="pencil" />
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-link btn-sm btn-icon text-danger"
                                    :title="`Delete PACE ${pace.label}`"
                                    :aria-label="`Delete PACE ${pace.label}`"
                                    :disabled="!auth.canWrite"
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

        <PaceFormModal
            v-model="formOpen"
            :pace="editing"
            :branches="branches"
            :branch-id="branchId || null"
            :organizations="formOrganizations"
            :organization-id="organizationId || null"
            @saved="load"
        />

        <BatchStockModal v-model="batchOpen" :paces="selectedPaces" :mode="batchMode" @saved="onBatchSaved" />

        <StockActionModal v-model="stockOpen" :mode="stockMode" :pace="stockPace" :teachers="stockTeachers" @saved="onStockSaved" />

        <ConfirmDialog
            :model-value="deleting !== null"
            title="Delete PACE"
            :busy="removing"
            @update:model-value="deleting = null"
            @confirm="confirmRemove"
        >
            Delete PACE <strong v-if="deleting">{{ deleting.label }}</strong> from the catalog?
            <template v-if="deleting && deleting.assignmentsCount">
                It is on {{ pluralize(deleting.assignmentsCount, 'student record') }}; those records will be deleted as well.
            </template>
            Its stock and ledger go with it. This cannot be undone.
        </ConfirmDialog>
    </div>
</template>

<style scoped>
.admin-paces {
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
    min-width: 150px;
}

.school-select {
    flex: 0 1 220px;
    min-width: 170px;
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

.table {
    min-width: 944px;
    table-layout: fixed;
}

/* The extra School column: trim the fixed columns so the name keeps its room on a laptop screen. */
.table.with-organization {
    min-width: 1040px;
}

.with-organization .col-number {
    width: 80px;
}

.with-organization .col-organization {
    width: 150px;
}

.with-organization .col-branch {
    width: 120px;
}

.with-organization .col-pool {
    width: 140px;
}

.with-organization .col-held {
    width: 110px;
}

.with-organization .col-records {
    width: 100px;
}

.with-organization .col-actions {
    width: 160px;
}

.col-number {
    width: 100px;
}

.col-organization {
    width: 170px;
}

.col-branch {
    width: 140px;
}

.col-pool {
    width: 150px;
}

.col-held {
    width: 130px;
}

.col-records {
    width: 150px;
}

.col-actions {
    width: 180px;
}

.number {
    font-weight: 500;
}

.pool-value {
    display: inline-block;
    min-width: 2.5ch;
    margin-right: 6px;
    font-weight: 600;
}

.pool-value.level-out {
    color: var(--danger);
}

.pool-value.level-low {
    color: var(--warn-text);
}

.ellipsis {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
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
