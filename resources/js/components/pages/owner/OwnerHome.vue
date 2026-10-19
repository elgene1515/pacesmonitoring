<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { ownerService } from '../../../services';
import OwnerService from '../../../services/OwnerService';
import { ROLE_LABELS } from '../../../models';
import { useScopeStore } from '../../../stores/scope';
import { notify } from '../../../stores/notifications';
import { elapsed, formatDate, pluralize, timeAgo } from '../../../core/format';
import AppField from '../../ui/AppField.vue';
import AppModal from '../../ui/AppModal.vue';
import EmptyState from '../../ui/EmptyState.vue';
import PasswordField from '../../ui/PasswordField.vue';
import AppIcon from '../../ui/AppIcon.vue';
import StatusBadge from '../../ui/StatusBadge.vue';
import BarList from '../../charts/BarList.vue';

/**
 * The owner's home: every organization's subscription state, the accounts on
 * the platform, and what needs attention (expiring subscriptions, pending
 * join requests nobody has answered).
 */
const scope = useScopeStore();

const summary = ref(OwnerService.emptyDashboard());
const loading = ref(true);

const readOnlyCount = computed(() => {
    const s = summary.value.subscriptionsByStatus;
    return s.expired + s.cancelled + s.none;
});

const tiles = computed(() => [
    { key: 'organizations', label: 'Organizations', value: summary.value.totals.organizations, to: { name: 'owner-organizations' } },
    { key: 'active', label: 'Active subscriptions', value: summary.value.subscriptionsByStatus.active, to: { name: 'owner-organizations', query: { status: 'active' } } },
    { key: 'trial', label: 'On trial', value: summary.value.subscriptionsByStatus.trial, to: { name: 'owner-organizations', query: { status: 'trial' } } },
    { key: 'readonly', label: 'Read-only (lapsed)', value: readOnlyCount.value, to: { name: 'owner-organizations', query: { status: 'expired' } }, tone: readOnlyCount.value > 0 ? 'bad' : '' },
    { key: 'admins', label: 'Admins', value: summary.value.totals.admins, to: { name: 'admin-users', query: { role: 'admin' } } },
    { key: 'teachers', label: 'Teachers', value: summary.value.totals.teachers, to: { name: 'admin-users', query: { role: 'teacher' } }, note: summary.value.totals.unassignedUsers > 0 ? `${summary.value.totals.unassignedUsers} not in a school yet` : '' },
    { key: 'students', label: 'Students', value: summary.value.totals.students, to: { name: 'admin-students' } },
    { key: 'plan-requests', label: 'Plan requests waiting', value: summary.value.totals.pendingPlanRequests, to: { name: 'owner-organizations' }, tone: summary.value.totals.pendingPlanRequests > 0 ? 'warn' : '' },
]);

const statusItems = computed(() => {
    const s = summary.value.subscriptionsByStatus;

    return [
        { id: 'active', label: 'Active', value: s.active },
        { id: 'trial', label: 'Trial', value: s.trial },
        { id: 'past_due', label: 'Past due', value: s.pastDue },
        { id: 'expired', label: 'Expired', value: s.expired },
        { id: 'cancelled', label: 'Cancelled', value: s.cancelled },
        { id: 'none', label: 'No subscription', value: s.none },
    ].filter((item) => item.value > 0);
});

function statusTone(status) {
    if (status === 'active') {
        return 'ok';
    }

    if (status === 'trial' || status === 'past_due') {
        return 'warn';
    }

    return 'bad';
}

/** Narrow the admin area to one organization (called when its Analytics link is clicked, never while rendering). */
function pickOrganization(organization) {
    scope.select(organization.id, organization.name);
}

async function load({ silent = false } = {}) {
    if (!silent) {
        loading.value = true;
    }

    try {
        summary.value = await ownerService.getDashboardSummary();
    } catch (err) {
        notify.error(err?.message ?? 'Failed to load the dashboard.');
    } finally {
        loading.value = false;
    }
}

/* ---------- accounts that are not in a school yet ---------- */

const unassigned = ref({ users: [], total: 0, overall: 0, limit: 100 });
const unassignedLoading = ref(false);
const unassignedLoaded = ref(false);

/** search: name or email; role: '' | 'teacher' | 'admin'; from / to: the day the account was created (YYYY-MM-DD, either may be empty). */
const filters = reactive({ search: '', role: '', from: '', to: '' });

const ROLE_OPTIONS = [
    { value: '', label: 'All roles' },
    { value: 'teacher', label: ROLE_LABELS.teacher },
    { value: 'admin', label: ROLE_LABELS.admin },
];

const hasFilters = computed(() => filters.search.trim() !== '' || filters.role !== '' || filters.from !== '' || filters.to !== '');

const unassignedEmpty = computed(() => (hasFilters.value
    ? 'No accounts match your filters.'
    : 'Every account belongs to a school.'));

async function loadUnassigned() {
    unassignedLoading.value = true;

    try {
        unassigned.value = await ownerService.listUnassignedUsers({
            search: filters.search.trim(),
            role: filters.role,
            from: filters.from,
            to: filters.to,
        });
        unassignedLoaded.value = true;
    } catch (err) {
        notify.error(err?.message ?? 'Failed to load the accounts without a school.');
    } finally {
        unassignedLoading.value = false;
    }
}

function clearFilters() {
    filters.search = '';
    filters.role = '';
    filters.from = '';
    filters.to = '';
}

let searchTimer = null;

watch(() => filters.search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(loadUnassigned, 300);
});

watch(() => [filters.role, filters.from, filters.to], loadUnassigned);

/** Tooltip with the exact moment behind a "3 days ago". */
function exactTime(value) {
    return value ? new Date(String(value).replace(/(\.\d{3})\d+/, '$1')).toLocaleString() : '';
}

/* ---------- delete an account ---------- */

const deleting = ref(null);
const deletePassword = ref('');
const deleteErrors = ref({});
const savingDelete = ref(false);

function openDelete(user) {
    deleting.value = user;
    deletePassword.value = '';
    deleteErrors.value = {};
}

async function confirmDelete() {
    const user = deleting.value;

    if (!user || savingDelete.value) {
        return;
    }

    deleteErrors.value = {};

    if (!deletePassword.value) {
        deleteErrors.value = { admin_password: ['Enter your password to confirm.'] };
        return;
    }

    savingDelete.value = true;

    try {
        await ownerService.deleteUnassignedUser(user.id, deletePassword.value);
        notify.success(`${user.name}'s account was deleted.`);
        deleting.value = null;
        deletePassword.value = '';
        await Promise.all([loadUnassigned(), load({ silent: true })]);
    } catch (err) {
        if (err?.isValidation && err.errors.user) {
            notify.error(err.first('user'));
            deleting.value = null;
        } else if (err?.isValidation && err.hasFieldErrors) {
            deleteErrors.value = err.errors;
        } else {
            notify.error(err?.message ?? 'Unable to delete the account.');
        }
    } finally {
        savingDelete.value = false;
    }
}

onMounted(() => {
    load();
    loadUnassigned();
});

onBeforeUnmount(() => {
    clearTimeout(searchTimer);
});
</script>

<template>
    <div class="owner-home">
        <div class="page-header">
            <div>
                <h1>Owner dashboard</h1>
                <p class="muted subtitle">Every organization on the platform, their subscriptions and accounts.</p>
            </div>
            <div class="page-actions">
                <RouterLink :to="{ name: 'owner-plans' }" class="btn btn-secondary btn-sm">Plans</RouterLink>
                <RouterLink :to="{ name: 'owner-organizations' }" class="btn btn-sm">Organizations</RouterLink>
            </div>
        </div>

        <section class="stats" aria-label="Summary">
            <RouterLink v-for="tile in tiles" :key="tile.key" :to="tile.to" class="card tile" :class="tile.tone">
                <span class="tile-label muted">{{ tile.label }}</span>
                <span class="tile-value mono">
                    <span v-if="loading" class="muted">…</span>
                    <template v-else>{{ tile.value }}</template>
                </span>
                <span v-if="tile.note && !loading" class="tile-note muted">{{ tile.note }}</span>
            </RouterLink>
        </section>

        <div class="grid-2 pair">
            <section class="card">
                <h2 class="card-title">Expiring within {{ summary.expiringSoonDays }} days</h2>
                <p class="muted card-hint">Trials and subscriptions that end soon, soonest first. Renew them from the organization's page.</p>
                <p v-if="loading" class="muted">Loading…</p>
                <EmptyState v-else-if="summary.expiringSoon.length === 0" compact message="Nothing is about to expire." />
                <table v-else class="table">
                    <thead>
                        <tr>
                            <th>Organization</th>
                            <th>Plan</th>
                            <th>Ends</th>
                            <th class="text-right">Days left</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in summary.expiringSoon" :key="row.organizationId">
                            <td class="ellipsis">
                                <RouterLink :to="{ name: 'owner-organization-detail', params: { id: row.organizationId } }">{{ row.organizationName }}</RouterLink>
                            </td>
                            <td>{{ row.planName || '—' }} <StatusBadge :tone="statusTone(row.status)">{{ row.status === 'past_due' ? 'Past due' : row.status }}</StatusBadge></td>
                            <td class="nowrap">{{ formatDate(row.endsAt) || '—' }}</td>
                            <td class="mono text-right" :class="{ 'text-danger': row.daysLeft !== null && row.daysLeft <= 7 }">{{ row.daysLeft ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <section class="card">
                <h2 class="card-title">Subscriptions by status</h2>
                <p class="muted card-hint">Each organization counted once, by its current subscription.</p>
                <p v-if="loading" class="muted">Loading…</p>
                <BarList v-else :items="statusItems" color="var(--primary)" empty-message="No organizations yet." />
            </section>
        </div>

        <div class="grid-2 pair">
            <section class="card recent">
                <div class="recent-header">
                    <h2 class="card-title">Recent organizations</h2>
                    <RouterLink :to="{ name: 'owner-organizations' }" class="muted view-all">View all</RouterLink>
                </div>
                <p v-if="loading" class="muted">Loading…</p>
                <EmptyState v-else-if="summary.recentOrganizations.length === 0" compact message="No organizations registered yet." />
                <table v-else class="table recent-orgs">
                    <colgroup>
                        <col class="col-org" />
                        <col class="col-plan" />
                        <col class="col-status" />
                        <col class="col-date" />
                        <col class="col-actions" />
                    </colgroup>
                    <thead>
                        <tr>
                            <th>Organization</th>
                            <th>Plan</th>
                            <th>Status</th>
                            <th>Registered</th>
                            <th class="table-actions"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="organization in summary.recentOrganizations" :key="organization.id">
                            <td class="ellipsis">
                                <RouterLink :to="{ name: 'owner-organization-detail', params: { id: organization.id } }" :title="`${organization.name} · ${organization.code}`">{{ organization.name }}</RouterLink>
                            </td>
                            <td class="ellipsis">{{ organization.planName || '—' }}</td>
                            <td class="ellipsis">
                                <StatusBadge :tone="organization.subscription ? organization.subscription.tone : 'bad'">{{ organization.subscriptionLabel }}</StatusBadge>
                            </td>
                            <td class="ellipsis muted">{{ formatDate(organization.createdAt) || '—' }}</td>
                            <td class="table-actions">
                                <RouterLink
                                    :to="{ name: 'admin-home' }"
                                    @click="pickOrganization(organization)"
                                    class="btn btn-link btn-sm btn-icon"
                                    :title="`Analytics for ${organization.name}`"
                                    :aria-label="`Analytics for ${organization.name}`"
                                >
                                    <AppIcon name="eye" />
                                </RouterLink>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <section class="card recent">
                <div class="recent-header">
                    <h2 class="card-title">Recent users</h2>
                    <RouterLink :to="{ name: 'admin-users' }" class="muted view-all">View all</RouterLink>
                </div>
                <p v-if="loading" class="muted">Loading…</p>
                <EmptyState v-else-if="summary.recentUsers.length === 0" compact message="No users registered yet." />
                <table v-else class="table">
                    <colgroup>
                        <col />
                        <col class="col-role" />
                        <col />
                        <col class="col-date" />
                    </colgroup>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Role</th>
                            <th>Organization</th>
                            <th>Registered</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="user in summary.recentUsers" :key="user.id">
                            <td class="ellipsis">
                                <RouterLink :to="{ name: 'admin-user-detail', params: { id: user.id } }" :title="user.email">{{ user.name }}</RouterLink>
                            </td>
                            <td><StatusBadge :tone="user.isPlatform ? 'bad' : user.isAdmin ? 'info' : 'muted'">{{ user.roleLabel }}</StatusBadge></td>
                            <td class="ellipsis muted">{{ user.organizationName || (user.isPlatform ? '—' : 'Not in a school yet') }}</td>
                            <td class="nowrap muted">{{ formatDate(user.createdAt) || '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>

        <section class="card">
            <h2 class="card-title">
                Plan requests waiting for you
                <span class="muted fails-note">({{ pluralize(summary.totals.pendingPlanRequests, 'request') }})</span>
            </h2>
            <p class="muted card-hint">Schools asking to subscribe, change plan or cancel. Open the organization to approve or decline.</p>
            <p v-if="loading" class="muted">Loading…</p>
            <EmptyState v-else-if="summary.pendingPlanRequests.length === 0" compact message="No plan requests waiting." />
            <table v-else class="table">
                <thead>
                    <tr>
                        <th>Organization</th>
                        <th>Request</th>
                        <th>Current plan</th>
                        <th>From</th>
                        <th>Sent</th>
                        <th class="table-actions"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="request in summary.pendingPlanRequests" :key="request.id">
                        <td class="ellipsis">
                            <RouterLink :to="{ name: 'owner-organization-detail', params: { id: request.organizationId } }">{{ request.organizationName }}</RouterLink>
                        </td>
                        <td class="ellipsis" :title="request.message">{{ request.summary }}<span v-if="request.message" class="muted small">“{{ request.message }}”</span></td>
                        <td class="ellipsis muted">{{ request.organization?.current_plan_name || '—' }}</td>
                        <td class="ellipsis muted">{{ request.requesterName || '—' }}</td>
                        <td class="nowrap muted">{{ formatDate(request.createdAt) || '—' }}</td>
                        <td class="table-actions">
                            <RouterLink :to="{ name: 'owner-organization-detail', params: { id: request.organizationId } }" class="btn btn-sm">Review</RouterLink>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section class="card">
            <h2 class="card-title">
                Pending join requests
                <span class="muted fails-note">({{ pluralize(summary.totals.pendingJoinRequests, 'request') }} waiting across every organization)</span>
            </h2>
            <p class="muted card-hint">Teachers waiting for a school administrator to admit them, oldest first. Admins decide these from their Organization page.</p>
            <p v-if="loading" class="muted">Loading…</p>
            <EmptyState v-else-if="summary.pendingJoinRequests.length === 0" compact message="No pending requests." />
            <table v-else class="table">
                <thead>
                    <tr>
                        <th>Teacher</th>
                        <th>Organization</th>
                        <th>Branch</th>
                        <th>Requested</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="request in summary.pendingJoinRequests" :key="request.id">
                        <td class="ellipsis">{{ request.userName }} <span class="muted small">{{ request.user?.email }}</span></td>
                        <td class="ellipsis">
                            <RouterLink :to="{ name: 'owner-organization-detail', params: { id: request.organizationId } }">{{ request.organizationName }}</RouterLink>
                        </td>
                        <td>{{ request.branchName || '—' }}</td>
                        <td class="nowrap muted">{{ formatDate(request.createdAt) || '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section class="card unassigned" aria-labelledby="unassigned-title">
            <h2 id="unassigned-title" class="card-title">
                Accounts not in a school yet
                <span class="muted fails-note">({{ pluralize(unassigned.overall, 'account') }})</span>
            </h2>
            <p class="muted card-hint">
                People who signed up but belong to no school: teachers waiting to be admitted (or removed by their school) and school admins who have not registered one. The ones waiting longest come first.
            </p>

            <div class="toolbar">
                <div class="toolbar-controls">
                    <input
                        v-model="filters.search"
                        class="input search"
                        type="search"
                        placeholder="Search by name or email"
                        aria-label="Search accounts"
                    />
                    <select v-model="filters.role" class="input role-select" aria-label="Filter by role">
                        <option v-for="option in ROLE_OPTIONS" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                    <label class="date-filter">
                        <span class="muted">Created from</span>
                        <input v-model="filters.from" class="input date-input" type="date" :max="filters.to || undefined" aria-label="Created from date" />
                    </label>
                    <label class="date-filter">
                        <span class="muted">to</span>
                        <input v-model="filters.to" class="input date-input" type="date" :min="filters.from || undefined" aria-label="Created to date" />
                    </label>
                    <button v-if="hasFilters" type="button" class="btn btn-link btn-sm" @click="clearFilters">Clear</button>
                </div>
                <span class="muted count nowrap">
                    <template v-if="unassignedLoading && !unassignedLoaded">Loading…</template>
                    <template v-else-if="hasFilters">{{ unassigned.total }} of {{ pluralize(unassigned.overall, 'account') }}</template>
                    <template v-else>{{ pluralize(unassigned.total, 'account') }}</template>
                </span>
            </div>

            <p v-if="unassignedLoading && !unassignedLoaded" class="muted state">Loading…</p>

            <EmptyState v-else-if="unassigned.users.length === 0" :message="unassignedEmpty">
                <template v-if="hasFilters" #action>
                    <button type="button" class="btn btn-secondary btn-sm" @click="clearFilters">Clear filters</button>
                </template>
            </EmptyState>

            <template v-else>
                <div class="table-wrap">
                    <table class="table unassigned-table">
                        <colgroup>
                            <col class="col-account" />
                            <col class="col-role-wide" />
                            <col class="col-age" />
                            <col class="col-age" />
                            <col class="col-request" />
                            <col class="col-actions-two" />
                        </colgroup>
                        <thead>
                            <tr>
                                <th>Account</th>
                                <th>Role</th>
                                <th>Account created</th>
                                <th>Without a school</th>
                                <th>Join request</th>
                                <th class="table-actions"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="user in unassigned.users" :key="user.id">
                                <td class="ellipsis" data-label="Account">
                                    <RouterLink :to="{ name: 'admin-user-detail', params: { id: user.id } }" class="name">{{ user.name }}</RouterLink>
                                    <span class="muted small" :title="user.email">{{ user.email }}</span>
                                </td>
                                <td data-label="Role">
                                    <StatusBadge :tone="user.isAdmin ? 'info' : 'muted'">{{ user.roleLabel }}</StatusBadge>
                                </td>
                                <td class="ellipsis" data-label="Account created" :title="exactTime(user.createdAt)">
                                    <span class="name">{{ timeAgo(user.createdAt) || '—' }}</span>
                                    <span class="muted small">{{ formatDate(user.createdAt) || '—' }}</span>
                                </td>
                                <td class="ellipsis" data-label="Without a school" :title="exactTime(user.unassignedSince)">
                                    <span class="name">{{ elapsed(user.unassignedSince) || '—' }}</span>
                                    <span class="muted small">{{ user.hasLeftASchool ? 'Since leaving a school' : 'Since signing up' }}</span>
                                </td>
                                <td class="ellipsis" data-label="Join request">
                                    <StatusBadge :tone="user.joinRequestInfo.tone">{{ user.joinRequestInfo.label }}</StatusBadge>
                                    <span v-if="user.joinRequestInfo.detail" class="muted small" :title="user.joinRequestInfo.detail">{{ user.joinRequestInfo.detail }}</span>
                                </td>
                                <td class="table-actions">
                                    <RouterLink
                                        :to="{ name: 'admin-user-detail', params: { id: user.id } }"
                                        class="btn btn-link btn-sm btn-icon"
                                        :title="`View ${user.name}`"
                                        :aria-label="`View ${user.name}`"
                                    >
                                        <AppIcon name="eye" />
                                    </RouterLink>
                                    <button
                                        type="button"
                                        class="btn btn-link btn-sm btn-icon text-danger"
                                        :disabled="user.ownsRecords"
                                        :title="user.ownsRecords ? `Still owns ${user.recordsSummary} from a previous school, so it cannot be deleted` : `Delete ${user.name}'s account`"
                                        :aria-label="`Delete ${user.name}'s account`"
                                        @click="openDelete(user)"
                                    >
                                        <AppIcon name="trash" />
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p v-if="unassigned.total > unassigned.users.length" class="muted truncated">
                    Showing the {{ unassigned.users.length }} accounts that have waited longest of {{ unassigned.total }}. Narrow the search or the dates to see the others.
                </p>
            </template>
        </section>

        <AppModal :model-value="deleting !== null" title="Delete account" width="460px" :closable="!savingDelete" :close-on-backdrop="false" @update:model-value="deleting = null">
            <form v-if="deleting" class="form" novalidate @submit.prevent="confirmDelete">
                <p class="muted">
                    Permanently delete the account of <strong>{{ deleting.name }}</strong> ({{ deleting.email }})?
                    They will no longer be able to sign in, and any request they sent to join a school is removed. This cannot be undone.
                </p>
                <AppField label="Your password" for="delete-account-password" :error="deleteErrors.admin_password" hint="Confirm it's you." required>
                    <PasswordField id="delete-account-password" v-model="deletePassword" autocomplete="current-password" :disabled="savingDelete" />
                </AppField>
                <div class="actions">
                    <button type="button" class="btn btn-secondary" :disabled="savingDelete" @click="deleting = null">Cancel</button>
                    <button type="submit" class="btn btn-danger" :disabled="savingDelete">{{ savingDelete ? 'Deleting…' : 'Delete account' }}</button>
                </div>
            </form>
        </AppModal>
    </div>
</template>

<style scoped>
.owner-home {
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

.stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
}

.tile {
    display: flex;
    flex-direction: column;
    gap: 4px;
    color: var(--text);
}

.tile:hover {
    text-decoration: none;
    border-color: var(--primary);
}

.tile.bad .tile-value {
    color: var(--danger);
}

.tile.warn .tile-value {
    color: var(--warn-text);
}

.tile-label {
    font-size: 0.9rem;
}

.tile-value {
    font-size: 1.8rem;
    font-weight: 600;
    line-height: 1.1;
}

.tile-note {
    font-size: 0.8rem;
}

.pair > .card {
    min-width: 0;
}

.card-hint {
    margin: -4px 0 12px;
    font-size: 0.85rem;
}

.fails-note {
    font-weight: 400;
    font-size: 0.85rem;
}

.recent-header {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 12px;
}

.view-all {
    font-size: 0.92rem;
}

.view-all:hover {
    color: var(--primary);
}

.table {
    table-layout: fixed;
}

/* Recent organizations: fixed, percentage-based columns so nothing can run into its neighbour. */
.recent-orgs {
    width: 100%;
    table-layout: fixed;
}

.recent-orgs .col-org {
    width: 36%;
}

.recent-orgs .col-plan {
    width: 20%;
}

.recent-orgs .col-status {
    width: 20%;
}

.recent-orgs .col-date {
    width: 16%;
}

.recent-orgs .col-actions {
    width: 8%;
}

.recent-orgs th,
.recent-orgs td {
    vertical-align: middle;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.col-count {
    width: 80px;
}

.col-role {
    width: 90px;
}

.col-date {
    width: 115px;
}

.col-actions {
    width: 52px;
}

.ellipsis {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.small {
    display: block;
    font-size: 0.8rem;
}

.state {
    margin: 0;
    padding: 24px 16px;
    text-align: center;
}

.toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px 16px;
    flex-wrap: wrap;
    margin-bottom: 12px;
}

.toolbar-controls {
    display: flex;
    align-items: center;
    gap: 8px 12px;
    flex-wrap: wrap;
    flex: 1 1 auto;
}

.toolbar-controls .input {
    width: auto;
}

.search {
    flex: 1 1 220px;
    max-width: 300px;
}

.role-select {
    min-width: 130px;
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

.unassigned .table-wrap {
    margin: 0 -16px;
}

.unassigned-table {
    min-width: 880px;
}

.unassigned-table td {
    vertical-align: middle;
}

.col-account {
    width: 230px;
}

.col-role-wide {
    width: 110px;
}

.col-age {
    width: 150px;
}

.col-request {
    width: 200px;
}

.col-actions-two {
    width: 84px;
}

.unassigned-table .name {
    display: block;
    font-weight: 500;
    overflow: hidden;
    text-overflow: ellipsis;
}

.truncated {
    margin: 12px 0 0;
    font-size: 0.85rem;
}

.form {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.form p {
    margin: 0;
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

@media (max-width: 900px) {
    .stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

/* Phones: each account becomes a labelled card, so View / Delete never hide off to the right. */
@media (max-width: 720px) {
    .unassigned-table {
        min-width: 0;
    }

    .unassigned-table thead,
    .unassigned-table colgroup {
        display: none;
    }

    .unassigned-table,
    .unassigned-table tbody,
    .unassigned-table tr,
    .unassigned-table td {
        display: block;
        width: 100%;
    }

    .unassigned-table tr {
        padding: 12px 16px;
        border-top: 1px solid var(--border);
    }

    .unassigned-table tbody tr:hover {
        background: transparent;
    }

    .unassigned-table td {
        padding: 4px 0;
        border: none;
        text-align: left;
        white-space: normal;
        overflow: visible;
        text-overflow: clip;
    }

    .unassigned-table td[data-label]::before {
        content: attr(data-label);
        display: block;
        font-size: 0.78rem;
        color: var(--muted);
    }

    .unassigned-table td.table-actions {
        padding-top: 10px;
    }

    .unassigned-table td.table-actions .btn + .btn {
        margin-left: 6px;
    }
}
</style>
