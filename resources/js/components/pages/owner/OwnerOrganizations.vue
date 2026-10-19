<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ownerService } from '../../../services';
import { useScopeStore } from '../../../stores/scope';
import { notify } from '../../../stores/notifications';
import { formatDate, pluralize } from '../../../core/format';
import { STATUS_LABELS } from '../../../models';
import EmptyState from '../../ui/EmptyState.vue';
import AppIcon from '../../ui/AppIcon.vue';
import StatusBadge from '../../ui/StatusBadge.vue';

/**
 * Every registered organization with its subscription state. Opening one
 * narrows the admin area (analytics, users, students) to it.
 */
const route = useRoute();
const router = useRouter();
const scope = useScopeStore();

const organizations = ref([]);
const loading = ref(true);
const loaded = ref(false);
const search = ref('');
const status = ref(typeof route.query.status === 'string' ? route.query.status : '');
/** '' (any) | 'none' (no subscription) | a plan id: narrows the loaded organizations by their current plan. */
const planFilter = ref('');
const plans = ref([]);
/** Date range (YYYY-MM-DD, either end optional) the current subscription ends in; organizations without an end date drop out once a bound is set. */
const endsFrom = ref('');
const endsTo = ref('');
/** Include organizations the owner has deactivated (soft-deleted). */
const showDeleted = ref(route.query.deleted === '1');

const STATUS_OPTIONS = [
    { value: '', label: 'Any status' },
    ...Object.entries(STATUS_LABELS).map(([value, label]) => ({ value, label })),
];

let searchTimer = null;

const hasFilters = computed(() => search.value.trim() !== '' || status.value !== '' || planFilter.value !== '' || endsFrom.value !== '' || endsTo.value !== '');

/** The loaded organizations narrowed by the plan and "ends" filters (search, status and deactivated are done by the server). */
const visibleOrganizations = computed(() => {
    let list = organizations.value;

    if (planFilter.value === 'none') {
        list = list.filter((organization) => !organization.subscription);
    } else if (planFilter.value !== '') {
        list = list.filter((organization) => Number(organization.subscription?.plan?.id) === Number(planFilter.value));
    }

    // A range entered back to front still works.
    let from = endsFrom.value;
    let to = endsTo.value;

    if (from && to && from > to) {
        [from, to] = [to, from];
    }

    if (from || to) {
        list = list.filter((organization) => {
            const ends = organization.subscription?.endsAt || '';

            return ends !== '' && (!from || ends >= from) && (!to || ends <= to);
        });
    }

    return list;
});

const emptyMessage = computed(() => (hasFilters.value ? 'No organizations match your filters.' : 'No organizations registered yet.'));

async function load() {
    loading.value = true;

    try {
        organizations.value = await ownerService.listOrganizations({ search: search.value, status: status.value, trashed: showDeleted.value });
        loaded.value = true;
    } catch (err) {
        notify.error(err?.message ?? 'Failed to load organizations.');
    } finally {
        loading.value = false;
    }
}

async function loadPlans() {
    try {
        plans.value = await ownerService.listPlans();
    } catch {
        plans.value = [];
    }
}

function clearFilters() {
    search.value = '';
    status.value = '';
    planFilter.value = '';
    endsFrom.value = '';
    endsTo.value = '';
}

/** Narrow the admin area to this organization and open its analytics. */
function openAnalytics(organization) {
    scope.select(organization.id, organization.name);
    router.push({ name: 'admin-home' });
}

watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(load, 300);
});

watch([status, showDeleted], () => {
    router.replace({ query: { ...route.query, status: status.value || undefined, deleted: showDeleted.value ? '1' : undefined } });
    load();
});

onMounted(() => {
    load();
    loadPlans();
});

onBeforeUnmount(() => {
    clearTimeout(searchTimer);
});
</script>

<template>
    <div class="owner-organizations">
        <div class="page-header">
            <div>
                <h1>Organizations</h1>
                <p class="muted subtitle">Schools registered on the platform. Open one to manage its subscription or to look at its data.</p>
            </div>
        </div>

        <div class="card">
            <div class="toolbar">
                <div class="toolbar-controls">
                    <input v-model="search" class="input search" type="search" placeholder="Search by name or code" aria-label="Search organizations" />
                    <select v-model="status" class="input select" aria-label="Filter by subscription status">
                        <option v-for="option in STATUS_OPTIONS" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                    <select v-model="planFilter" class="input select" aria-label="Filter by plan">
                        <option value="">Any plan</option>
                        <option v-for="plan in plans" :key="plan.id" :value="plan.id">{{ plan.name }}</option>
                        <option value="none">No subscription</option>
                    </select>
                    <label class="date-filter">
                        <span class="muted">Ends from</span>
                        <input v-model="endsFrom" class="input date-input" type="date" :max="endsTo || undefined" aria-label="Subscription ends from date" />
                    </label>
                    <label class="date-filter">
                        <span class="muted">to</span>
                        <input v-model="endsTo" class="input date-input" type="date" :min="endsFrom || undefined" aria-label="Subscription ends to date" />
                    </label>
                    <label class="checkbox">
                        <input v-model="showDeleted" type="checkbox" />
                        Show deactivated
                    </label>
                    <button v-if="hasFilters" type="button" class="btn btn-link btn-sm" @click="clearFilters">Clear</button>
                </div>
                <div class="toolbar-meta">
                    <span class="muted count nowrap">{{ loading ? 'Loading…' : pluralize(visibleOrganizations.length, 'organization') }}</span>
                </div>
            </div>

            <p v-if="loading && !loaded" class="muted state">Loading…</p>

            <EmptyState v-else-if="visibleOrganizations.length === 0" :message="emptyMessage">
                <template v-if="hasFilters" #action>
                    <button type="button" class="btn btn-secondary btn-sm" @click="clearFilters">Clear filters</button>
                </template>
            </EmptyState>

            <div v-else class="table-wrap">
                <table class="table">
                    <colgroup>
                        <col />
                        <col class="col-code" />
                        <col class="col-plan" />
                        <col class="col-status" />
                        <col class="col-count" />
                        <col class="col-count" />
                        <col class="col-count" />
                        <col class="col-date" />
                        <col class="col-actions" />
                    </colgroup>
                    <thead>
                        <tr>
                            <th>Organization</th>
                            <th>Code</th>
                            <th>Plan</th>
                            <th>Status</th>
                            <th class="text-right">Branches</th>
                            <th class="text-right">Teachers</th>
                            <th class="text-right" title="Pending join and plan requests">Pending</th>
                            <th>Ends</th>
                            <th class="table-actions"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="organization in visibleOrganizations" :key="organization.id">
                            <td class="ellipsis">
                                <RouterLink :to="{ name: 'owner-organization-detail', params: { id: organization.id } }" class="name">{{ organization.name }}</RouterLink>
                                <StatusBadge v-if="organization.isDeleted" tone="bad" class="inline-badge">Deactivated</StatusBadge>
                                <span class="muted small">{{ organization.adminsCount ?? 0 }} admin{{ organization.adminsCount === 1 ? '' : 's' }} · {{ organization.creator?.name || 'no creator' }}</span>
                            </td>
                            <td class="mono">{{ organization.code }}</td>
                            <td class="ellipsis">{{ organization.planName || '—' }}</td>
                            <td>
                                <StatusBadge :tone="organization.subscription ? organization.subscription.tone : 'bad'">{{ organization.subscriptionLabel }}</StatusBadge>
                            </td>
                            <td class="mono text-right">{{ organization.branchesCount ?? 0 }}</td>
                            <td class="mono text-right">{{ organization.teachersCount ?? 0 }}</td>
                            <td class="mono text-right" :class="{ 'text-danger': (organization.pendingJoinRequestsCount + organization.pendingPlanRequestsCount) > 0 }" :title="`${organization.pendingJoinRequestsCount ?? 0} join · ${organization.pendingPlanRequestsCount ?? 0} plan`">
                                {{ (organization.pendingJoinRequestsCount ?? 0) + (organization.pendingPlanRequestsCount ?? 0) }}
                            </td>
                            <td class="nowrap muted">{{ organization.subscription?.endsAt ? formatDate(organization.subscription.endsAt) : '—' }}</td>
                            <td class="table-actions">
                                <RouterLink
                                    :to="{ name: 'owner-organization-detail', params: { id: organization.id } }"
                                    class="btn btn-link btn-sm btn-icon"
                                    :title="`Manage ${organization.name}`"
                                    :aria-label="`Manage ${organization.name}`"
                                >
                                    <AppIcon name="pencil" />
                                </RouterLink>
                                <button
                                    type="button"
                                    class="btn btn-link btn-sm btn-icon"
                                    :title="`Analytics for ${organization.name}`"
                                    :aria-label="`Analytics for ${organization.name}`"
                                    @click="openAnalytics(organization)"
                                >
                                    <AppIcon name="eye" />
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>

<style scoped>
.owner-organizations {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.page-header {
    margin-bottom: 0;
}

.subtitle {
    margin: 4px 0 0;
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

.date-filter {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 0.92rem;
}

.toolbar-controls .date-input {
    width: auto;
    min-width: 150px;
}

.toolbar-meta {
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
    table-layout: fixed;
    min-width: 960px;
}

.col-code {
    width: 120px;
}

.col-plan {
    width: 120px;
}

.col-status {
    width: 120px;
}

.col-count {
    width: 80px;
}

.col-date {
    width: 115px;
}

.col-actions {
    width: 84px;
}

.name {
    font-weight: 500;
}

.inline-badge {
    margin-left: 6px;
}

.small {
    display: block;
    font-size: 0.8rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.ellipsis {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
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
