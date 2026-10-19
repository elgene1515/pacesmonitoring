<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { adminService, ownerService } from '../../../services';
import { useAuthStore } from '../../../stores/auth';
import { useScopeStore } from '../../../stores/scope';
import { notify } from '../../../stores/notifications';
import { formatDate, pluralize } from '../../../core/format';
import EmptyState from '../../ui/EmptyState.vue';
import AppIcon from '../../ui/AppIcon.vue';
import StatusBadge from '../../ui/StatusBadge.vue';

/**
 * The members of the organization (teachers and admins). The owner sees every
 * account on the platform, or one organization's when the scope bar is set.
 */
const route = useRoute();
const auth = useAuthStore();
const scope = useScopeStore();

const users = ref([]);
const branches = ref([]);
const loading = ref(true);
const loaded = ref(false);
const search = ref('');
const roleFilter = ref(typeof route.query.role === 'string' ? route.query.role : '');
const branchId = ref('');

/**
 * The owner can narrow the list to one organization here (the scope bar does the same for the
 * whole admin area; while it is set, this filter is hidden). School admins never see it.
 */
const organizations = ref([]);
const organizationId = ref('');

const roleOptions = computed(() => {
    const options = [
        { value: '', label: auth.isPlatform ? 'All roles' : 'All members' },
        { value: 'teacher', label: 'Teachers' },
        { value: 'admin', label: 'Admins' },
    ];

    if (auth.isPlatform) {
        options.push({ value: 'owner', label: 'Owners' });
    }

    return options;
});

const showOrganizationFilter = computed(() => auth.isPlatform && !scope.isScoped);

/** The organization_id the page's own requests carry, when the organization filter is in use. */
const organizationParams = () => (showOrganizationFilter.value && organizationId.value !== '' ? { organization_id: organizationId.value } : {});

/** Branch names repeat across schools, so the branch filter only works within one organization. */
const showBranchFilter = computed(() => auth.isAdmin || auth.isPlatform);
const branchFilterEnabled = computed(() => auth.isAdmin || scope.isScoped || organizationId.value !== '');
const showOrganizationColumn = computed(() => auth.isPlatform && !scope.isScoped && organizationId.value === '');

const title = 'Users';

let searchTimer = null;

const hasFilters = computed(() => search.value.trim() !== '' || roleFilter.value !== '' || branchId.value !== '' || organizationId.value !== '');
const emptyMessage = computed(() => (hasFilters.value ? 'No users match your filters.' : (auth.isPlatform ? 'No users registered yet.' : 'No teachers have joined yet. Share your school code from the Organization page.')));

async function load() {
    loading.value = true;

    try {
        users.value = await adminService.listUsers({ ...organizationParams(), search: search.value, role: roleFilter.value, branch_id: branchId.value });
        loaded.value = true;
    } catch (err) {
        notify.error(err?.message ?? 'Failed to load users.');
    } finally {
        loading.value = false;
    }
}

async function loadBranches() {
    if (!branchFilterEnabled.value) {
        branches.value = [];
        return;
    }

    try {
        branches.value = await adminService.listBranches(organizationParams());
    } catch (err) {
        notify.error(err?.message ?? 'Failed to load branches.');
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

/** Changing the organization drops the branch filter and offers only that organization's branches. */
async function onOrganizationChange() {
    branchId.value = '';
    await loadBranches();
    load();
}

function clearFilters() {
    const hadOrganization = organizationId.value !== '';

    search.value = '';
    roleFilter.value = '';
    branchId.value = '';
    organizationId.value = '';

    if (hadOrganization) {
        branches.value = [];
        load();
    }
}

function roleTone(user) {
    if (user.isOwner) {
        return 'bad';
    }

    return user.isAdmin ? 'info' : 'muted';
}

watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(load, 300);
});

watch([roleFilter, branchId], load);

watch(() => scope.organizationId, () => {
    branchId.value = '';
    organizationId.value = '';
    loadBranches();
    load();
});

onMounted(() => {
    loadOrganizations();
    load();
    loadBranches();
});

onBeforeUnmount(() => {
    clearTimeout(searchTimer);
});
</script>

<template>
    <div class="admin-users">
        <div class="page-header">
            <div>
                <h1>{{ title }}</h1>
                <p v-if="auth.isAdmin" class="muted subtitle">
                    Everyone in {{ auth.user?.organizationName }}. Teachers join through the school code; admit them from the Organization page.
                </p>
            </div>
            <div v-if="auth.isAdmin" class="page-actions">
                <RouterLink :to="{ name: 'admin-organization' }" class="btn btn-secondary btn-sm">
                    Join requests
                    <span v-if="auth.organization?.pendingJoinRequestsCount" class="count-badge">{{ auth.organization.pendingJoinRequestsCount }}</span>
                </RouterLink>
            </div>
        </div>

        <div class="card">
            <!-- Filters on the left; the user count on the right. -->
            <div class="toolbar">
                <div class="toolbar-controls">
                    <input
                        v-model="search"
                        class="input search"
                        type="search"
                        placeholder="Search by name or email"
                        aria-label="Search users"
                    />
                    <select v-model="roleFilter" class="input select" aria-label="Filter by role">
                        <option v-for="option in roleOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                    <select v-if="showOrganizationFilter" v-model="organizationId" class="input select" aria-label="Filter by organization" @change="onOrganizationChange">
                        <option value="">All organizations</option>
                        <option v-for="organization in organizations" :key="organization.id" :value="organization.id">{{ organization.name }}</option>
                    </select>
                    <select v-if="showBranchFilter" v-model="branchId" class="input select" aria-label="Filter by branch" :disabled="!branchFilterEnabled">
                        <option value="">{{ branchFilterEnabled ? 'All branches' : 'Pick an organization first' }}</option>
                        <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
                    </select>
                    <button v-if="hasFilters" type="button" class="btn btn-link btn-sm" @click="clearFilters">
                        Clear
                    </button>
                </div>
                <div class="toolbar-meta">
                    <span class="muted count nowrap">
                        {{ loading ? 'Loading…' : pluralize(users.length, 'user') }}
                    </span>
                </div>
            </div>

            <p v-if="loading && !loaded" class="muted state">Loading…</p>

            <EmptyState v-else-if="users.length === 0" :message="emptyMessage">
                <template v-if="hasFilters" #action>
                    <button type="button" class="btn btn-secondary btn-sm" @click="clearFilters">Clear filters</button>
                </template>
            </EmptyState>

            <div v-else class="table-wrap">
                <table class="table">
                    <!-- Fixed column widths so every column lines up down the page. -->
                    <colgroup>
                        <col class="col-name" />
                        <col class="col-email" />
                        <col class="col-role" />
                        <col v-if="showOrganizationColumn" class="col-org" />
                        <col class="col-branch" />
                        <col class="col-date" />
                        <col class="col-actions" />
                    </colgroup>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th v-if="showOrganizationColumn">Organization</th>
                            <th>Branch</th>
                            <th>Registered</th>
                            <th class="table-actions"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="user in users" :key="user.id">
                            <td class="ellipsis">
                                <RouterLink :to="{ name: 'admin-user-detail', params: { id: user.id } }" class="name">
                                    {{ user.name }}
                                </RouterLink>
                                <span v-if="user.id === auth.user?.id" class="muted"> (you)</span>
                            </td>
                            <td class="ellipsis" :title="user.email">{{ user.email }}</td>
                            <td><StatusBadge :tone="roleTone(user)">{{ user.roleLabel }}</StatusBadge></td>
                            <td v-if="showOrganizationColumn" class="ellipsis">{{ user.organizationLabel || '—' }}</td>
                            <td class="ellipsis">{{ user.branchLabel || '—' }}</td>
                            <td class="nowrap">{{ formatDate(user.createdAt) || '—' }}</td>
                            <td class="table-actions">
                                <RouterLink
                                    :to="{ name: 'admin-user-detail', params: { id: user.id } }"
                                    class="btn btn-link btn-sm btn-icon"
                                    :title="`View ${user.name}`"
                                    :aria-label="`View ${user.name}`"
                                >
                                    <AppIcon name="eye" />
                                </RouterLink>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>

<style scoped>
.admin-users {
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

.count-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 18px;
    height: 18px;
    padding: 0 5px;
    border-radius: 999px;
    background: var(--danger);
    color: var(--on-danger);
    font-size: 0.75rem;
    font-weight: 600;
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
    min-width: 130px;
}

.toolbar-meta {
    display: flex;
    align-items: center;
    gap: 16px;
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
    min-width: 760px;
    table-layout: fixed;
}

.col-email {
    width: 26%;
}

.col-role {
    width: 100px;
}

.col-org {
    width: 18%;
}

.col-branch {
    width: 14%;
}

.col-date {
    width: 120px;
}

.col-actions {
    width: 60px;
}

.name {
    font-weight: 500;
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
