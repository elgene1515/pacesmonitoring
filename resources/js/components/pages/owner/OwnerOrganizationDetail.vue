<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { ownerService } from '../../../services';
import { useAuthStore } from '../../../stores/auth';
import { useScopeStore } from '../../../stores/scope';
import { notify } from '../../../stores/notifications';
import { formatDate, pluralize, todayIso } from '../../../core/format';
import { SUBSCRIPTION_STATUSES } from '../../../models';
import AppField from '../../ui/AppField.vue';
import AppModal from '../../ui/AppModal.vue';
import ConfirmDialog from '../../ui/ConfirmDialog.vue';
import EmptyState from '../../ui/EmptyState.vue';
import PasswordField from '../../ui/PasswordField.vue';
import StatusBadge from '../../ui/StatusBadge.vue';

/**
 * One organization, for the platform staff: details (owner-password
 * confirmed), subscription management, the school's plan requests, branches,
 * members, join requests, and (owner only) deactivation / restoration.
 */
const props = defineProps({
    id: { type: Number, required: true },
});

const router = useRouter();
const auth = useAuthStore();
const scope = useScopeStore();

const organization = ref(null);
const members = ref([]);
const joinRequests = ref([]);
const planRequests = ref([]);
const plans = ref([]);
const loading = ref(true);
const loadError = ref('');

const subscription = computed(() => organization.value?.subscription ?? null);
const activePlans = computed(() => plans.value.filter((plan) => plan.isActive || plan.id === subscription.value?.planId));
const isDeleted = computed(() => Boolean(organization.value?.isDeleted));
const pendingPlanRequests = computed(() => planRequests.value.filter((request) => request.isPending));

/* ---------- details ---------- */

const detailsForm = reactive({ name: '', contact_email: '', contact_phone: '', admin_password: '' });
const detailsErrors = ref({});
const savingDetails = ref(false);

/** The school's address is its main branch's; the school's admins edit it on the branch. */
const mainBranch = computed(() => organization.value?.mainBranch ?? null);

watch(organization, (value) => {
    detailsForm.name = value?.name ?? '';
    detailsForm.contact_email = value?.contactEmail ?? '';
    detailsForm.contact_phone = value?.contactPhone ?? '';
    detailsForm.admin_password = '';
});

async function saveDetails() {
    if (savingDetails.value) {
        return;
    }

    detailsErrors.value = {};

    if (!detailsForm.name.trim()) {
        detailsErrors.value = { name: ['The school name is required.'] };
        return;
    }

    if (!detailsForm.admin_password) {
        detailsErrors.value = { admin_password: ['Enter your password to confirm this change.'] };
        return;
    }

    savingDetails.value = true;

    try {
        await ownerService.updateOrganization(props.id, {
            name: detailsForm.name.trim(),
            contact_email: detailsForm.contact_email.trim() || null,
            contact_phone: detailsForm.contact_phone.trim() || null,
            admin_password: detailsForm.admin_password,
        });
        notify.success('Organization updated.');
        await load();
    } catch (err) {
        if (err?.isValidation && err.hasFieldErrors) {
            detailsErrors.value = err.errors;
        } else {
            notify.error(err?.message ?? 'Unable to update the organization.');
        }
    } finally {
        savingDetails.value = false;
    }
}

/* ---------- deactivate / restore (owner only) ---------- */

const deactivateOpen = ref(false);
const deactivatePassword = ref('');
const deactivateErrors = ref({});
const deactivating = ref(false);
const restoring = ref(false);

async function deactivate() {
    if (deactivating.value) {
        return;
    }

    deactivateErrors.value = {};

    if (!deactivatePassword.value) {
        deactivateErrors.value = { admin_password: ['Enter your password to confirm.'] };
        return;
    }

    deactivating.value = true;

    try {
        await ownerService.deleteOrganization(props.id, deactivatePassword.value);
        notify.success(`${organization.value.name} was deactivated. Nothing was deleted; it can be restored here.`);
        deactivateOpen.value = false;
        deactivatePassword.value = '';

        if (scope.organizationId === props.id) {
            scope.clearScope();
        }

        await load();
    } catch (err) {
        if (err?.isValidation && err.hasFieldErrors) {
            deactivateErrors.value = err.errors;
        } else {
            notify.error(err?.message ?? 'Unable to deactivate the organization.');
        }
    } finally {
        deactivating.value = false;
    }
}

async function restore() {
    restoring.value = true;

    try {
        await ownerService.restoreOrganization(props.id);
        notify.success(`${organization.value.name} is active again.`);
        await load();
    } catch (err) {
        notify.error(err?.message ?? 'Unable to restore the organization.');
    } finally {
        restoring.value = false;
    }
}

/* ---------- subscription ---------- */

const subscriptionModalOpen = ref(false);
const editingSubscription = ref(null);
/** The plan request being approved through the subscription form, if any. */
const approvingRequest = ref(null);
const subscriptionForm = reactive({ plan_id: '', status: 'active', starts_at: '', ends_at: '', notes: '' });
const subscriptionErrors = ref({});
const savingSubscription = ref(false);

const subscriptionModalTitle = computed(() => {
    if (approvingRequest.value) {
        return `Approve: ${approvingRequest.value.summary}`;
    }

    return editingSubscription.value ? 'Edit subscription period' : 'New subscription period';
});

function shiftDate(iso, { years = 0, days = 0 } = {}) {
    const [year, month, day] = String(iso).split('-').map(Number);
    const date = new Date(year + years, month - 1, day + days);
    const mm = String(date.getMonth() + 1).padStart(2, '0');
    const dd = String(date.getDate()).padStart(2, '0');
    return `${date.getFullYear()}-${mm}-${dd}`;
}

/** A renewal starts the day after the current period ends (or today), for one year. */
function nextStart() {
    const current = subscription.value;
    const today = todayIso();

    if (current?.endsAt && current.endsAt >= today && !current.isTrial) {
        return shiftDate(current.endsAt, { days: 1 });
    }

    return today;
}

function openNewSubscription() {
    editingSubscription.value = null;
    approvingRequest.value = null;
    const start = nextStart();

    subscriptionForm.plan_id = subscription.value?.planId ?? (activePlans.value[0]?.id ?? '');
    subscriptionForm.status = 'active';
    subscriptionForm.starts_at = start;
    subscriptionForm.ends_at = shiftDate(start, { years: 1 });
    subscriptionForm.notes = '';
    subscriptionErrors.value = {};
    subscriptionModalOpen.value = true;
}

function openEditSubscription(period) {
    editingSubscription.value = period;
    approvingRequest.value = null;
    subscriptionForm.plan_id = period.planId ?? '';
    subscriptionForm.status = period.status;
    subscriptionForm.starts_at = period.startsAt;
    subscriptionForm.ends_at = period.endsAt;
    subscriptionForm.notes = period.notes ?? '';
    subscriptionErrors.value = {};
    subscriptionModalOpen.value = true;
}

/** Approving a plan change records the period on the requested plan. */
function openApprove(request) {
    editingSubscription.value = null;
    approvingRequest.value = request;
    const start = todayIso();

    subscriptionForm.plan_id = request.planId ?? '';
    subscriptionForm.status = 'active';
    subscriptionForm.starts_at = start;
    subscriptionForm.ends_at = shiftDate(start, { years: 1 });
    subscriptionForm.notes = `Approved plan request #${request.id}${request.requesterName ? ` from ${request.requesterName}` : ''}.`;
    subscriptionErrors.value = {};
    subscriptionModalOpen.value = true;
}

async function saveSubscription() {
    if (savingSubscription.value) {
        return;
    }

    subscriptionErrors.value = {};

    if (!subscriptionForm.plan_id) {
        subscriptionErrors.value = { plan_id: ['Pick a plan.'] };
        return;
    }

    if (!subscriptionForm.starts_at) {
        subscriptionErrors.value = { starts_at: ['The start date is required.'] };
        return;
    }

    savingSubscription.value = true;

    try {
        if (approvingRequest.value) {
            await ownerService.approvePlanRequest(approvingRequest.value.id, {
                starts_at: subscriptionForm.starts_at,
                ends_at: subscriptionForm.ends_at || null,
                notes: subscriptionForm.notes.trim() || null,
            });
            notify.success('Request approved and the subscription recorded.');
        } else {
            const payload = {
                plan_id: Number(subscriptionForm.plan_id),
                status: subscriptionForm.status,
                starts_at: subscriptionForm.starts_at,
                ends_at: subscriptionForm.ends_at || null,
                notes: subscriptionForm.notes.trim() || null,
            };

            if (editingSubscription.value) {
                await ownerService.updateSubscription(editingSubscription.value.id, payload);
                notify.success('Subscription period updated.');
            } else {
                await ownerService.createSubscription(props.id, payload);
                notify.success('Subscription recorded.');
            }
        }

        subscriptionModalOpen.value = false;
        await load();
    } catch (err) {
        if (err?.isValidation && err.hasFieldErrors) {
            subscriptionErrors.value = err.errors;
        } else {
            notify.error(err?.message ?? 'Unable to save the subscription.');
        }
    } finally {
        savingSubscription.value = false;
    }
}

/** Quick status change on the current period (e.g. mark past due or cancel). */
async function setStatus(status) {
    if (!subscription.value) {
        return;
    }

    try {
        await ownerService.updateSubscription(subscription.value.id, { status });
        notify.success(`Subscription marked ${status.replace('_', ' ')}.`);
        await load();
    } catch (err) {
        notify.error(err?.message ?? 'Unable to change the status.');
    }
}

/* ---------- plan requests: approve cancellations, decline ---------- */

const decidingRequest = ref(null);
const confirmingCancellation = ref(null);
const decliningRequest = ref(null);
const declineNote = ref('');

async function approveCancellation() {
    const request = confirmingCancellation.value;

    if (!request) {
        return;
    }

    decidingRequest.value = request.id;

    try {
        await ownerService.approvePlanRequest(request.id, {});
        notify.success('Cancellation approved; the school is now read-only.');
        confirmingCancellation.value = null;
        await load();
    } catch (err) {
        notify.error(err?.isValidation ? (err.first('request') ?? err.message) : (err?.message ?? 'Unable to approve the request.'));
    } finally {
        decidingRequest.value = null;
    }
}

function openDecline(request) {
    decliningRequest.value = request;
    declineNote.value = '';
}

async function declineRequest() {
    const request = decliningRequest.value;

    if (!request) {
        return;
    }

    decidingRequest.value = request.id;

    try {
        await ownerService.declinePlanRequest(request.id, declineNote.value.trim());
        notify.info('Request declined.');
        decliningRequest.value = null;
        await load();
    } catch (err) {
        notify.error(err?.message ?? 'Unable to decline the request.');
    } finally {
        decidingRequest.value = null;
    }
}

/* ---------- members ---------- */

const changingRole = ref(null);

async function changeRole(member, role) {
    if (member.role === role) {
        return;
    }

    changingRole.value = member.id;

    try {
        await ownerService.updateUserRole(member.id, role);
        notify.success(`${member.name} is now ${role === 'admin' ? 'an administrator' : 'a teacher'}.`);
        await load();
    } catch (err) {
        notify.error(err?.isValidation ? (err.first('role') ?? err.message) : (err?.message ?? 'Unable to change the role.'));
    } finally {
        changingRole.value = null;
    }
}

/* ---------- navigation ---------- */

function openAdminArea(name) {
    scope.select(organization.value.id, organization.value.name);
    router.push({ name });
}

/* ---------- load ---------- */

async function load() {
    loadError.value = '';

    try {
        const [detail, planList] = await Promise.all([
            ownerService.getOrganization(props.id),
            plans.value.length ? Promise.resolve(plans.value) : ownerService.listPlans(),
        ]);

        organization.value = detail.organization;
        members.value = detail.members;
        joinRequests.value = detail.joinRequests;
        planRequests.value = detail.planRequests;
        plans.value = planList;
    } catch (err) {
        organization.value = null;
        loadError.value = err?.isNotFound ? 'This organization could not be found.' : (err?.message ?? 'Failed to load the organization.');
    } finally {
        loading.value = false;
    }
}

watch(() => props.id, () => {
    loading.value = true;
    load();
});

onMounted(load);
</script>

<template>
    <div class="owner-organization">
        <p class="back">
            <RouterLink :to="{ name: 'owner-organizations' }">&larr; Organizations</RouterLink>
        </p>

        <p v-if="loading" class="muted">Loading…</p>

        <EmptyState v-else-if="!organization" :message="loadError">
            <template #action>
                <RouterLink :to="{ name: 'owner-organizations' }" class="btn btn-secondary btn-sm">Back to organizations</RouterLink>
            </template>
        </EmptyState>

        <template v-else>
            <div class="page-header">
                <div>
                    <h1>
                        {{ organization.name }}
                        <StatusBadge v-if="isDeleted" tone="bad" class="title-badge">Deactivated</StatusBadge>
                    </h1>
                    <p class="muted subtitle">
                        <span class="mono">{{ organization.code }}</span>
                        · <StatusBadge :tone="subscription ? subscription.tone : 'bad'">{{ organization.subscriptionLabel }}</StatusBadge>
                        · {{ pluralize(organization.branchesCount ?? 0, 'branch', 'branches') }}
                        · {{ pluralize(organization.teachersCount ?? 0, 'teacher') }}
                        · {{ pluralize(organization.studentsCount ?? 0, 'student') }}
                        · {{ pluralize(organization.assignmentsCount ?? 0, 'PACE record') }}
                        · Registered {{ formatDate(organization.createdAt) || '—' }}
                        <template v-if="isDeleted"> · Deactivated {{ formatDate(organization.deletedAt) }}</template>
                    </p>
                </div>
                <div class="page-actions">
                    <button type="button" class="btn btn-secondary btn-sm" @click="openAdminArea('admin-students')">Students</button>
                    <button type="button" class="btn btn-secondary btn-sm" @click="openAdminArea('admin-users')">Members</button>
                    <button type="button" class="btn btn-sm" @click="openAdminArea('admin-home')">Analytics</button>
                </div>
            </div>

            <p v-if="isDeleted" class="deactivated-note">
                This school is deactivated: its members cannot sign in to it and its code cannot be joined. Nothing has been deleted.
                <button v-if="auth.isOwner" type="button" class="btn btn-sm restore" :disabled="restoring" @click="restore">
                    {{ restoring ? 'Restoring…' : 'Restore school' }}
                </button>
            </p>

            <!-- ============ Plan requests ============ -->
            <section v-if="pendingPlanRequests.length" class="card requests">
                <h2 class="card-title">
                    Plan requests
                    <span class="count-badge">{{ pendingPlanRequests.length }}</span>
                </h2>
                <p class="muted card-hint">The school asked for a change. Approving a plan change records the subscription period; approving a cancellation marks the current subscription cancelled.</p>
                <ul class="request-list">
                    <li v-for="request in pendingPlanRequests" :key="request.id" class="request">
                        <div class="request-body">
                            <p class="request-title">{{ request.summary }}</p>
                            <p class="muted small">
                                From {{ request.requesterName || 'the school' }} · {{ formatDate(request.createdAt) }}
                                <template v-if="request.message"> · “{{ request.message }}”</template>
                                <template v-if="request.plan"> · {{ request.plan.branchesLabel }} · {{ request.plan.teachersLabel }} · {{ request.plan.studentsLabel }} · {{ request.plan.priceLabel }}</template>
                            </p>
                        </div>
                        <div class="request-actions">
                            <button
                                v-if="request.isCancellation"
                                type="button"
                                class="btn btn-sm btn-danger"
                                :disabled="decidingRequest === request.id"
                                @click="confirmingCancellation = request"
                            >
                                Approve cancellation
                            </button>
                            <button v-else type="button" class="btn btn-sm" :disabled="decidingRequest === request.id" @click="openApprove(request)">
                                Approve &amp; record
                            </button>
                            <button type="button" class="btn btn-secondary btn-sm" :disabled="decidingRequest === request.id" @click="openDecline(request)">
                                Decline
                            </button>
                        </div>
                    </li>
                </ul>
            </section>

            <div class="grid-2 pair">
                <!-- ============ Subscription ============ -->
                <section class="card">
                    <div class="card-head">
                        <h2 class="card-title">Subscription</h2>
                        <button type="button" class="btn btn-sm" @click="openNewSubscription">
                            {{ subscription ? 'Renew / change plan' : 'Assign a plan' }}
                        </button>
                    </div>

                    <template v-if="subscription">
                        <dl class="info">
                            <div class="info-item">
                                <dt>Plan</dt>
                                <dd>{{ subscription.plan?.name ?? '—' }} <span class="muted small">{{ subscription.plan?.priceLabel }}</span></dd>
                            </div>
                            <div class="info-item">
                                <dt>Status</dt>
                                <dd>
                                    <StatusBadge :tone="subscription.tone">{{ subscription.statusLabel }}</StatusBadge>
                                    <span v-if="subscription.effectiveStatus !== subscription.status" class="muted small">stored as {{ subscription.status }}</span>
                                </dd>
                            </div>
                            <div class="info-item">
                                <dt>Period</dt>
                                <dd>{{ formatDate(subscription.startsAt) || '—' }} – {{ subscription.endsAt ? formatDate(subscription.endsAt) : 'open-ended' }}</dd>
                            </div>
                            <div class="info-item">
                                <dt>Days left</dt>
                                <dd :class="{ 'text-danger': subscription.daysLeft !== null && subscription.daysLeft < 0 }">{{ subscription.daysLeft ?? '—' }}</dd>
                            </div>
                            <div class="info-item">
                                <dt>Branches</dt>
                                <dd>{{ organization.branchesCount ?? 0 }} of {{ organization.branchLimit ?? '∞' }}</dd>
                            </div>
                            <div class="info-item">
                                <dt>Teachers</dt>
                                <dd>{{ organization.teachersCount ?? 0 }} of {{ organization.teacherLimit ?? '∞' }}</dd>
                            </div>
                            <div class="info-item">
                                <dt>Students</dt>
                                <dd>{{ organization.studentsCount ?? 0 }} of {{ organization.studentLimit ?? '∞' }}</dd>
                            </div>
                            <div v-if="subscription.notes" class="info-item wide">
                                <dt>Notes</dt>
                                <dd>{{ subscription.notes }}</dd>
                            </div>
                        </dl>

                        <div class="quick-actions">
                            <span class="muted">Mark as</span>
                            <button
                                v-for="option in SUBSCRIPTION_STATUSES"
                                :key="option.value"
                                type="button"
                                class="btn btn-link btn-sm"
                                :disabled="subscription.status === option.value"
                                @click="setStatus(option.value)"
                            >
                                {{ option.label }}
                            </button>
                        </div>
                    </template>

                    <EmptyState v-else compact message="No subscription yet. Assign a plan to let this school add records." />

                    <h3 class="history-title">History</h3>
                    <table v-if="organization.subscriptions.length" class="table history">
                        <thead>
                            <tr>
                                <th>Plan</th>
                                <th>Status</th>
                                <th>Period</th>
                                <th class="table-actions"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="period in organization.subscriptions" :key="period.id">
                                <td>{{ period.plan?.name ?? '—' }}</td>
                                <td><StatusBadge :tone="period.tone">{{ period.statusLabel }}</StatusBadge></td>
                                <td class="muted">{{ formatDate(period.startsAt) }} – {{ period.endsAt ? formatDate(period.endsAt) : 'open' }}</td>
                                <td class="table-actions">
                                    <button type="button" class="btn btn-link btn-sm" @click="openEditSubscription(period)">Edit</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else class="muted">No subscription periods recorded.</p>

                    <template v-if="planRequests.length">
                        <h3 class="history-title">Plan requests</h3>
                        <ul class="plan-history-list">
                            <li v-for="request in planRequests" :key="request.id">
                                <StatusBadge :tone="request.tone">{{ request.statusLabel }}</StatusBadge>
                                {{ request.summary }}
                                <span class="muted small">
                                    · {{ formatDate(request.decidedAt || request.createdAt) }}
                                    <template v-if="request.requesterName"> · by {{ request.requesterName }}</template>
                                    <template v-if="request.decisionNote"> · “{{ request.decisionNote }}”</template>
                                </span>
                            </li>
                        </ul>
                    </template>
                </section>

                <!-- ============ Details ============ -->
                <section class="card">
                    <h2 class="card-title">School details</h2>
                    <form class="form" novalidate @submit.prevent="saveDetails">
                        <AppField label="Name" for="owner-org-name" :error="detailsErrors.name" required>
                            <input id="owner-org-name" v-model="detailsForm.name" class="input" type="text" :disabled="savingDetails" />
                        </AppField>
                        <AppField label="Address" :hint="mainBranch ? `The ${mainBranch.name} branch's address, set by the school's admins.` : 'Set on the main branch by the school\'s admins.'">
                            <p class="address">{{ organization.address || '—' }}</p>
                        </AppField>
                        <AppField label="Contact email" for="owner-org-email" :error="detailsErrors.contact_email">
                            <input id="owner-org-email" v-model="detailsForm.contact_email" class="input" type="email" :disabled="savingDetails" />
                        </AppField>
                        <AppField label="Contact phone" for="owner-org-phone" :error="detailsErrors.contact_phone">
                            <input id="owner-org-phone" v-model="detailsForm.contact_phone" class="input" type="tel" :disabled="savingDetails" />
                        </AppField>
                        <AppField label="Your password" for="owner-org-password" :error="detailsErrors.admin_password" hint="Confirm it's you making this change." required>
                            <PasswordField id="owner-org-password" v-model="detailsForm.admin_password" autocomplete="current-password" :disabled="savingDetails" />
                        </AppField>
                        <p class="muted small-note">
                            Registered by {{ organization.creator?.name || '—' }}<template v-if="organization.creator?.email"> ({{ organization.creator.email }})</template>.
                        </p>
                        <div class="actions">
                            <button type="submit" class="btn" :disabled="savingDetails">{{ savingDetails ? 'Saving…' : 'Save changes' }}</button>
                        </div>
                    </form>

                    <div v-if="auth.isOwner && !isDeleted" class="danger-zone">
                        <h3 class="danger-title">Deactivate school</h3>
                        <p class="muted small-note">
                            Members lose access and the school code stops working. Nothing is deleted and the school can be restored from
                            this page (Organizations → show deactivated).
                        </p>
                        <button type="button" class="btn btn-danger btn-sm" @click="deactivateOpen = true">Deactivate school</button>
                    </div>
                </section>
            </div>

            <!-- ============ Branches ============ -->
            <section class="card list-section">
                <div class="toolbar">
                    <h2 class="card-title">Branches</h2>
                    <span class="muted">Managed by the school's administrators.</span>
                </div>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Branch</th>
                            <th>Code</th>
                            <th>Address</th>
                            <th class="text-right">Teachers</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="branch in organization.branches" :key="branch.id">
                            <td>{{ branch.name }} <StatusBadge v-if="branch.isMain" tone="info">Main</StatusBadge></td>
                            <td class="mono">{{ branch.code }}</td>
                            <td class="muted ellipsis">{{ branch.address || '—' }}</td>
                            <td class="mono text-right">{{ branch.teachersCount ?? 0 }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <!-- ============ Members ============ -->
            <section class="card list-section">
                <div class="toolbar">
                    <div>
                        <h2 class="card-title">Members</h2>
                        <p class="muted card-hint">Administrators first. Change a member's role here; an organization needs at least one administrator.</p>
                    </div>
                </div>

                <EmptyState v-if="members.length === 0" message="No members yet." />

                <div v-else class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Branch</th>
                                <th>Role</th>
                                <th>Registered</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="member in members" :key="member.id">
                                <td class="ellipsis">
                                    <RouterLink :to="{ name: 'admin-user-detail', params: { id: member.id } }" @click="scope.select(organization.id, organization.name)">{{ member.name }}</RouterLink>
                                </td>
                                <td class="ellipsis muted">{{ member.email }}</td>
                                <td>{{ member.branchLabel || '—' }}</td>
                                <td>
                                    <select
                                        class="input role-select"
                                        :value="member.role"
                                        :disabled="changingRole === member.id"
                                        :aria-label="`Role of ${member.name}`"
                                        @change="changeRole(member, $event.target.value)"
                                    >
                                        <option value="admin">Admin</option>
                                        <option value="teacher">Teacher</option>
                                    </select>
                                </td>
                                <td class="nowrap muted">{{ formatDate(member.createdAt) || '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- ============ Join requests ============ -->
            <section class="card list-section">
                <div class="toolbar">
                    <div>
                        <h2 class="card-title">Join requests</h2>
                        <p class="muted card-hint">The latest requests; the school's administrators accept or decline them.</p>
                    </div>
                </div>

                <EmptyState v-if="joinRequests.length === 0" message="No join requests yet." />

                <table v-else class="table">
                    <thead>
                        <tr>
                            <th>Teacher</th>
                            <th>Branch</th>
                            <th>Status</th>
                            <th>Requested</th>
                            <th>Decided</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="request in joinRequests" :key="request.id">
                            <td class="ellipsis">{{ request.userName }} <span class="muted small">{{ request.user?.email }}</span></td>
                            <td>{{ request.branchName || '—' }}</td>
                            <td>
                                <StatusBadge :tone="request.isPending ? 'warn' : request.isAccepted ? 'ok' : request.isDeclined ? 'bad' : 'muted'">{{ request.statusLabel }}</StatusBadge>
                            </td>
                            <td class="nowrap muted">{{ formatDate(request.createdAt) || '—' }}</td>
                            <td class="nowrap muted">{{ request.decidedAt ? `${formatDate(request.decidedAt)}${request.decidedBy?.name ? ` by ${request.decidedBy.name}` : ''}` : '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </template>

        <!-- Subscription period form (also used to approve a plan change) -->
        <AppModal :model-value="subscriptionModalOpen" :title="subscriptionModalTitle" width="480px" :closable="!savingSubscription" :close-on-backdrop="false" @update:model-value="subscriptionModalOpen = false">
            <form v-if="subscriptionModalOpen" class="form" novalidate @submit.prevent="saveSubscription">
                <AppField label="Plan" for="sub-plan" :error="subscriptionErrors.plan_id" required>
                    <select id="sub-plan" v-model="subscriptionForm.plan_id" class="input" :disabled="savingSubscription || approvingRequest !== null">
                        <option value="">Select a plan</option>
                        <option v-for="plan in activePlans" :key="plan.id" :value="plan.id">
                            {{ plan.name }} · {{ plan.branchesLabel }} · {{ plan.priceLabel }}{{ plan.isActive ? '' : ' (retired)' }}
                        </option>
                    </select>
                </AppField>
                <AppField v-if="!approvingRequest" label="Status" for="sub-status" :error="subscriptionErrors.status" required>
                    <select id="sub-status" v-model="subscriptionForm.status" class="input" :disabled="savingSubscription">
                        <option v-for="option in SUBSCRIPTION_STATUSES" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                </AppField>
                <div class="dates">
                    <AppField label="Starts" for="sub-start" :error="subscriptionErrors.starts_at" required>
                        <input id="sub-start" v-model="subscriptionForm.starts_at" class="input" type="date" :disabled="savingSubscription" />
                    </AppField>
                    <AppField label="Ends" for="sub-end" :error="subscriptionErrors.ends_at" hint="Leave blank for open-ended.">
                        <input id="sub-end" v-model="subscriptionForm.ends_at" class="input" type="date" :disabled="savingSubscription" />
                    </AppField>
                </div>
                <AppField label="Notes" for="sub-notes" :error="subscriptionErrors.notes" hint="Invoice number, founding-client rate, etc.">
                    <textarea id="sub-notes" v-model="subscriptionForm.notes" class="input" rows="2" :disabled="savingSubscription"></textarea>
                </AppField>
                <div class="actions">
                    <button type="button" class="btn btn-secondary" :disabled="savingSubscription" @click="subscriptionModalOpen = false">Cancel</button>
                    <button type="submit" class="btn" :disabled="savingSubscription">
                        {{ savingSubscription ? 'Saving…' : (approvingRequest ? 'Approve &amp; record' : editingSubscription ? 'Save changes' : 'Record subscription') }}
                    </button>
                </div>
            </form>
        </AppModal>

        <!-- Approve a cancellation -->
        <ConfirmDialog
            :model-value="confirmingCancellation !== null"
            title="Approve cancellation"
            confirm-text="Approve cancellation"
            :busy="decidingRequest !== null"
            @update:model-value="confirmingCancellation = null"
            @confirm="approveCancellation"
        >
            Mark {{ organization?.name }}'s current subscription as cancelled? The school keeps read access to its records but cannot add or change anything.
        </ConfirmDialog>

        <!-- Decline a plan request -->
        <AppModal :model-value="decliningRequest !== null" title="Decline request" width="440px" :closable="decidingRequest === null" @update:model-value="decliningRequest = null">
            <form v-if="decliningRequest" class="form" novalidate @submit.prevent="declineRequest">
                <p class="muted">Decline “{{ decliningRequest.summary }}”? The school sees your note and can send a new request.</p>
                <AppField label="Note to the school" for="decline-note" hint="Optional.">
                    <input id="decline-note" v-model="declineNote" class="input" type="text" maxlength="255" :disabled="decidingRequest !== null" />
                </AppField>
                <div class="actions">
                    <button type="button" class="btn btn-secondary" :disabled="decidingRequest !== null" @click="decliningRequest = null">Cancel</button>
                    <button type="submit" class="btn btn-danger" :disabled="decidingRequest !== null">{{ decidingRequest !== null ? 'Declining…' : 'Decline' }}</button>
                </div>
            </form>
        </AppModal>

        <!-- Deactivate (owner only) -->
        <AppModal :model-value="deactivateOpen" title="Deactivate school" width="440px" :closable="!deactivating" :close-on-backdrop="false" @update:model-value="deactivateOpen = false">
            <form v-if="deactivateOpen" class="form" novalidate @submit.prevent="deactivate">
                <p class="muted">
                    Deactivate <strong>{{ organization?.name }}</strong>? Its members will not be able to use the system until it is restored.
                    Pending join and plan requests are withdrawn. Nothing is deleted.
                </p>
                <AppField label="Your password" for="deactivate-password" :error="deactivateErrors.admin_password" hint="Confirm it's you." required>
                    <PasswordField id="deactivate-password" v-model="deactivatePassword" autocomplete="current-password" :disabled="deactivating" />
                </AppField>
                <div class="actions">
                    <button type="button" class="btn btn-secondary" :disabled="deactivating" @click="deactivateOpen = false">Cancel</button>
                    <button type="submit" class="btn btn-danger" :disabled="deactivating">{{ deactivating ? 'Deactivating…' : 'Deactivate' }}</button>
                </div>
            </form>
        </AppModal>
    </div>
</template>

<style scoped>
.owner-organization {
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

.title-badge {
    margin-left: 8px;
    vertical-align: middle;
    font-size: 0.8rem;
}

.subtitle {
    margin: 4px 0 0;
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}

.deactivated-note {
    margin: 0;
    padding: 10px 12px;
    border: 1px solid rgba(var(--danger-rgb), 0.35);
    border-radius: var(--radius);
    background: rgba(var(--danger-rgb), 0.06);
    color: var(--danger);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}

.restore {
    flex-shrink: 0;
}

.requests {
    border-color: rgba(var(--warn-rgb), 0.4);
}

.request-list {
    margin: 0;
    padding: 0;
    list-style: none;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.request {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
}

.request-body {
    min-width: 0;
}

.request-title {
    margin: 0 0 2px;
    font-weight: 500;
}

.request-actions {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
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
    font-size: 0.78rem;
    font-weight: 600;
    vertical-align: middle;
}

.pair > .card {
    min-width: 0;
}

.card-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
}

.card-head .card-title {
    margin-bottom: 12px;
}

.card-hint {
    margin: -4px 0 12px;
    font-size: 0.85rem;
}

/* Definition grid; cells shrink and wrap instead of overflowing the card. */
.info {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: 14px 24px;
    margin: 0;
}

.info-item {
    min-width: 0;
}

.info-item.wide {
    grid-column: 1 / -1;
}

.info-item dt {
    font-size: 0.85rem;
    color: var(--muted);
    margin-bottom: 2px;
}

.info-item dd {
    margin: 0;
    overflow-wrap: anywhere;
}

.quick-actions {
    display: flex;
    align-items: center;
    gap: 2px;
    flex-wrap: wrap;
    margin-top: 12px;
    font-size: 0.9rem;
}

.history-title {
    margin: 18px 0 8px;
    font-size: 0.95rem;
}

.history {
    font-size: 0.92rem;
}

.plan-history-list {
    margin: 0;
    padding: 0;
    list-style: none;
    display: flex;
    flex-direction: column;
    gap: 6px;
    font-size: 0.92rem;
}

.form {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.small-note {
    margin: 0;
    font-size: 0.85rem;
}

.address {
    margin: 0;
    padding: 8px 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--bg);
    color: var(--text);
}

.actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 6px;
}

.danger-zone {
    margin-top: 20px;
    padding-top: 14px;
    border-top: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    gap: 8px;
    align-items: flex-start;
}

.danger-title {
    font-size: 0.95rem;
    color: var(--danger);
}

.dates {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px 16px;
}

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
    padding: 14px 16px;
    border-bottom: 1px solid var(--border);
}

.toolbar .card-title {
    margin-bottom: 0;
}

.toolbar .card-hint {
    margin: 2px 0 0;
}

.table-wrap {
    overflow-x: auto;
}

.role-select {
    width: auto;
    padding-top: 4px;
    padding-bottom: 4px;
}

.small {
    font-size: 0.85rem;
}

td .small {
    display: block;
    font-size: 0.8rem;
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

@media (max-width: 480px) {
    .dates {
        grid-template-columns: 1fr;
    }
}
</style>
