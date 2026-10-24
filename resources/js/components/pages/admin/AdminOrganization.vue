<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { adminService, tenantService } from '../../../services';
import { useAuthStore } from '../../../stores/auth';
import { useScopeStore } from '../../../stores/scope';
import { notify } from '../../../stores/notifications';
import { formatDate, pluralize } from '../../../core/format';
import { PLAN_REQUEST_ACTIONS } from '../../../models';
import AppField from '../../ui/AppField.vue';
import AppModal from '../../ui/AppModal.vue';
import ConfirmDialog from '../../ui/ConfirmDialog.vue';
import EmptyState from '../../ui/EmptyState.vue';
import AppIcon from '../../ui/AppIcon.vue';
import PasswordField from '../../ui/PasswordField.vue';
import RecentLists from '../../admin/RecentLists.vue';
import StatusBadge from '../../ui/StatusBadge.vue';

/**
 * The admin's organization: details, subscription state (with plan change
 * requests to the provider) and branches. The teachers asking to join are
 * decided on the Users page. The platform staff see the same page for the
 * organization picked in the scope bar.
 */
const auth = useAuthStore();
const scope = useScopeStore();

const organization = ref(null);
const loading = ref(true);
const loadError = ref('');

const canWrite = computed(() => auth.canWrite);
const subscription = computed(() => organization.value?.subscription ?? null);
const branchLimit = computed(() => organization.value?.branchLimit ?? null);
const branchLimitReached = computed(() => branchLimit.value !== null && (organization.value?.branches.length ?? 0) >= branchLimit.value);

/* ---------- details ---------- */

const detailsForm = reactive({ name: '', contact_email: '', contact_phone: '', admin_password: '' });
const detailsErrors = ref({});
const savingDetails = ref(false);
const copied = ref(false);

/** The school's address is its main branch's (edit it on the branch). */
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
        organization.value = await adminService.updateOrganization({
            name: detailsForm.name.trim(),
            contact_email: detailsForm.contact_email.trim() || null,
            contact_phone: detailsForm.contact_phone.trim() || null,
            admin_password: detailsForm.admin_password,
        });
        notify.success('Organization updated.');
        auth.refresh().catch(() => {});
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

async function copyCode() {
    try {
        await navigator.clipboard.writeText(organization.value.code);
        copied.value = true;
        setTimeout(() => {
            copied.value = false;
        }, 2000);
    } catch {
        notify.info(`School code: ${organization.value.code}`);
    }
}

/* ---------- plan requests ---------- */

const planRequests = ref([]);
const plans = ref([]);
const planRequestsLoaded = ref(false);
const showAllPlanRequests = ref(false);
const planModalOpen = ref(false);
const planForm = reactive({ action: PLAN_REQUEST_ACTIONS.CHANGE, plan_id: '', message: '' });
const planErrors = ref({});
const savingPlanRequest = ref(false);
const withdrawing = ref(false);
const confirmingWithdraw = ref(false);

const pendingPlanRequest = computed(() => planRequests.value.find((request) => request.isPending) ?? null);
const decidedPlanRequests = computed(() => planRequests.value.filter((request) => !request.isPending));

/** Plans other than the current one, so a "change" is always a change. */
const planOptions = computed(() => plans.value.filter((plan) => plan.id !== subscription.value?.planId));

async function loadPlanRequests() {
    try {
        [planRequests.value, plans.value] = await Promise.all([adminService.listPlanRequests(), tenantService.listPlans()]);
        planRequestsLoaded.value = true;
    } catch (err) {
        notify.error(err?.message ?? 'Failed to load plan requests.');
    }
}

function openPlanRequest(action = PLAN_REQUEST_ACTIONS.CHANGE) {
    planForm.action = action;
    planForm.plan_id = '';
    planForm.message = '';
    planErrors.value = {};
    planModalOpen.value = true;
}

async function submitPlanRequest() {
    if (savingPlanRequest.value) {
        return;
    }

    planErrors.value = {};

    if (planForm.action === PLAN_REQUEST_ACTIONS.CHANGE && !planForm.plan_id) {
        planErrors.value = { plan_id: ['Pick the plan you want to move to.'] };
        return;
    }

    savingPlanRequest.value = true;

    try {
        await adminService.createPlanRequest({
            action: planForm.action,
            plan_id: planForm.action === PLAN_REQUEST_ACTIONS.CHANGE ? Number(planForm.plan_id) : null,
            message: planForm.message.trim() || null,
        });
        notify.success('Your request was sent to the provider.');
        planModalOpen.value = false;
        await Promise.all([loadPlanRequests(), load(false)]);
        auth.refresh().catch(() => {});
    } catch (err) {
        if (err?.isValidation && err.hasFieldErrors) {
            planErrors.value = err.errors;

            if (err.errors.action) {
                notify.error(err.first('action'));
            }
        } else {
            notify.error(err?.message ?? 'Unable to send the request.');
        }
    } finally {
        savingPlanRequest.value = false;
    }
}

async function withdrawPlanRequest() {
    if (!pendingPlanRequest.value) {
        return;
    }

    withdrawing.value = true;

    try {
        await adminService.cancelPlanRequest(pendingPlanRequest.value.id);
        notify.info('Your request was withdrawn.');
        confirmingWithdraw.value = false;
        await Promise.all([loadPlanRequests(), load(false)]);
        auth.refresh().catch(() => {});
    } catch (err) {
        notify.error(err?.message ?? 'Unable to withdraw the request.');
    } finally {
        withdrawing.value = false;
    }
}

/* ---------- branches ---------- */

const branchModalOpen = ref(false);
const editingBranch = ref(null);
const branchForm = reactive({ name: '', address: '', is_main: false });
const branchErrors = ref({});
const savingBranch = ref(false);
const deletingBranch = ref(null);
const confirmingBranch = ref(null);

const branchModalTitle = computed(() => (editingBranch.value ? 'Edit branch' : 'Add branch'));

function openBranch(branch = null) {
    editingBranch.value = branch;
    branchForm.name = branch?.name ?? '';
    branchForm.address = branch?.address ?? '';
    branchForm.is_main = Boolean(branch?.isMain);
    branchErrors.value = {};
    branchModalOpen.value = true;
}

async function saveBranch() {
    if (savingBranch.value) {
        return;
    }

    branchErrors.value = {};

    if (!branchForm.name.trim()) {
        branchErrors.value = { name: ['The branch name is required.'] };
        return;
    }

    savingBranch.value = true;

    try {
        const payload = {
            name: branchForm.name.trim(),
            address: branchForm.address.trim() || null,
            is_main: branchForm.is_main,
        };

        if (editingBranch.value) {
            await adminService.updateBranch(editingBranch.value.id, payload);
            notify.success('Branch updated.');
        } else {
            await adminService.createBranch(payload);
            notify.success(`Branch ${payload.name} added.`);
        }

        branchModalOpen.value = false;
        await load(false);
    } catch (err) {
        if (err?.isValidation && err.hasFieldErrors) {
            branchErrors.value = err.errors;
        } else {
            notify.error(err?.message ?? 'Unable to save the branch.');
        }
    } finally {
        savingBranch.value = false;
    }
}

async function makeMain(branch) {
    try {
        await adminService.updateBranch(branch.id, { is_main: true });
        notify.success(`${branch.name} is now the main branch.`);
        await load(false);
    } catch (err) {
        notify.error(err?.message ?? 'Unable to change the main branch.');
    }
}

async function deleteBranch() {
    const branch = confirmingBranch.value;

    if (!branch) {
        return;
    }

    deletingBranch.value = branch.id;

    try {
        await adminService.removeBranch(branch.id);
        notify.success(`Branch ${branch.name} removed.`);
        confirmingBranch.value = null;
        await load(false);
    } catch (err) {
        notify.error(err?.isValidation ? (err.first('branch') ?? err.message) : (err?.message ?? 'Unable to remove the branch.'));
    } finally {
        deletingBranch.value = null;
    }
}

/* ---------- recent students and members (school admins only; platform staff see them on Home) ---------- */

const recentStudents = ref([]);
const recentUsers = ref([]);
const recentLoading = ref(false);

async function loadRecent() {
    if (!auth.isAdmin) {
        return;
    }

    recentLoading.value = true;

    try {
        const summary = await adminService.getDashboardSummary();
        recentStudents.value = summary.recentStudents;
        recentUsers.value = summary.recentUsers;
    } catch (err) {
        notify.error(err?.message ?? 'Failed to load the recent students and members.');
    } finally {
        recentLoading.value = false;
    }
}

/* ---------- load ---------- */

async function load(withLists = true) {
    if (withLists) {
        loading.value = true;
    }

    loadError.value = '';

    try {
        organization.value = await adminService.getOrganization();

        if (withLists) {
            loadPlanRequests();
            loadRecent();
        }
    } catch (err) {
        organization.value = null;
        loadError.value = err?.isNotFound
            ? (auth.isPlatform ? 'Pick an organization from the Organizations page first.' : 'Your organization could not be found.')
            : (err?.message ?? 'Failed to load the organization.');
    } finally {
        loading.value = false;
    }
}

watch(() => scope.organizationId, () => load());

onMounted(load);
</script>

<template>
    <div class="admin-organization">
        <p v-if="loading" class="muted">Loading…</p>

        <EmptyState v-else-if="!organization" :message="loadError">
            <template v-if="auth.isPlatform" #action>
                <RouterLink :to="{ name: 'owner-organizations' }" class="btn btn-secondary btn-sm">Organizations</RouterLink>
            </template>
        </EmptyState>

        <template v-else>
            <div class="page-header">
                <div>
                    <h1>{{ organization.name }}</h1>
                    <p class="muted subtitle">
                        School code
                        <span class="mono code">{{ organization.code }}</span>
                        <button type="button" class="btn btn-link btn-sm" @click="copyCode">{{ copied ? 'Copied' : 'Copy' }}</button>
                        · Teachers enter this code to ask to join.
                    </p>
                </div>
            </div>

            <div class="grid-2 pair">
                <!-- ============ Details ============ -->
                <section class="card">
                    <h2 class="card-title">School details</h2>
                    <form class="form" novalidate @submit.prevent="saveDetails">
                        <AppField label="Name" for="org-name" :error="detailsErrors.name" required>
                            <input id="org-name" v-model="detailsForm.name" class="input" type="text" :disabled="savingDetails || !canWrite" />
                        </AppField>
                        <AppField label="Address" :hint="mainBranch ? `The ${mainBranch.name} branch's address; edit it on the branch below.` : 'Set on the main branch.'">
                            <p class="address">{{ organization.address || '—' }}</p>
                        </AppField>
                        <AppField label="Contact email" for="org-email" :error="detailsErrors.contact_email">
                            <input id="org-email" v-model="detailsForm.contact_email" class="input" type="email" :disabled="savingDetails || !canWrite" />
                        </AppField>
                        <AppField label="Contact phone" for="org-phone" :error="detailsErrors.contact_phone">
                            <input id="org-phone" v-model="detailsForm.contact_phone" class="input" type="tel" :disabled="savingDetails || !canWrite" />
                        </AppField>
                        <AppField label="Your password" for="org-password" :error="detailsErrors.admin_password" hint="Confirm it's you making this change." required>
                            <PasswordField id="org-password" v-model="detailsForm.admin_password" autocomplete="current-password" :disabled="savingDetails || !canWrite" />
                        </AppField>
                        <div class="actions">
                            <button type="submit" class="btn" :disabled="savingDetails || !canWrite">
                                {{ savingDetails ? 'Saving…' : 'Save changes' }}
                            </button>
                        </div>
                    </form>
                </section>

                <!-- ============ Subscription ============ -->
                <section class="card subscription-card">
                    <h2 class="card-title">Subscription</h2>

                    <template v-if="subscription">
                        <dl class="info">
                            <div class="info-item">
                                <dt>Plan</dt>
                                <dd>{{ subscription.plan?.name ?? '—' }}</dd>
                            </div>
                            <div class="info-item">
                                <dt>Status</dt>
                                <dd><StatusBadge :tone="subscription.tone">{{ subscription.statusLabel }}</StatusBadge></dd>
                            </div>
                            <div class="info-item">
                                <dt>Period</dt>
                                <dd>
                                    {{ formatDate(subscription.startsAt) || '—' }} –
                                    {{ subscription.endsAt ? formatDate(subscription.endsAt) : 'open-ended' }}
                                </dd>
                            </div>
                            <div class="info-item">
                                <dt>{{ subscription.daysLeft !== null && subscription.daysLeft < 0 ? 'Ended' : 'Days left' }}</dt>
                                <dd>
                                    <template v-if="subscription.daysLeft === null">—</template>
                                    <template v-else-if="subscription.daysLeft < 0">{{ pluralize(-subscription.daysLeft, 'day') }} ago</template>
                                    <template v-else>{{ subscription.daysLeft }}</template>
                                </dd>
                            </div>
                            <div class="info-item">
                                <dt>Branches</dt>
                                <dd>{{ organization.branches.length }} of {{ branchLimit ?? '∞' }}</dd>
                            </div>
                            <div class="info-item">
                                <dt>Teachers</dt>
                                <dd>{{ organization.teachersCount ?? 0 }} of {{ organization.teacherLimit ?? '∞' }}</dd>
                            </div>
                            <div class="info-item">
                                <dt>Students</dt>
                                <dd>{{ organization.studentsCount ?? 0 }} of {{ organization.studentLimit ?? '∞' }}</dd>
                            </div>
                        </dl>
                        <p v-if="subscription.isReadOnly" class="read-only">
                            The subscription is {{ subscription.statusLabel.toLowerCase() }}: everyone in the school can view records but not
                            change them until it is renewed.
                        </p>
                    </template>

                    <EmptyState v-else compact message="No subscription yet. Choose a plan to have the provider set it up." />

                    <!-- Pending request to the provider, or the outcome of the last one. -->
                    <div v-if="pendingPlanRequest" class="plan-request pending">
                        <div>
                            <p class="plan-request-title">
                                Request sent: {{ pendingPlanRequest.summary }}
                                <StatusBadge tone="warn">Waiting for the provider</StatusBadge>
                            </p>
                            <p class="muted small">
                                Sent {{ formatDate(pendingPlanRequest.createdAt) }}<template v-if="pendingPlanRequest.message"> · “{{ pendingPlanRequest.message }}”</template>.
                                The provider will approve it, decline it or get in touch.
                            </p>
                        </div>
                        <button type="button" class="btn btn-secondary btn-sm" :disabled="withdrawing" @click="confirmingWithdraw = true">Withdraw</button>
                    </div>

                    <div v-if="decidedPlanRequests.length" class="plan-history">
                        <button type="button" class="btn btn-link btn-sm" @click="showAllPlanRequests = !showAllPlanRequests">
                            {{ showAllPlanRequests ? 'Hide' : 'Show' }} previous requests ({{ decidedPlanRequests.length }})
                        </button>
                        <ul v-if="showAllPlanRequests" class="plan-history-list">
                            <li v-for="request in decidedPlanRequests" :key="request.id">
                                <StatusBadge :tone="request.tone">{{ request.statusLabel }}</StatusBadge>
                                {{ request.summary }}
                                <span class="muted small">
                                    · {{ formatDate(request.decidedAt || request.createdAt) }}<template v-if="request.decisionNote"> · “{{ request.decisionNote }}”</template>
                                </span>
                            </li>
                        </ul>
                    </div>

                    <p class="muted hint">
                        Plan changes and renewals are confirmed by the system provider; billing happens outside the app.
                    </p>

                    <!-- Actions at the bottom of the card. -->
                    <div v-if="!pendingPlanRequest" class="subscription-actions">
                        <button type="button" class="btn" :disabled="!canWrite && !subscription?.isReadOnly" @click="openPlanRequest(PLAN_REQUEST_ACTIONS.CHANGE)">
                            {{ subscription?.isTrial || !subscription ? 'Choose a plan' : 'Change plan' }}
                        </button>
                        <button v-if="subscription && !subscription.isReadOnly" type="button" class="btn btn-secondary text-danger" @click="openPlanRequest(PLAN_REQUEST_ACTIONS.CANCEL)">
                            Cancel subscription
                        </button>
                    </div>
                </section>
            </div>

            <!-- ============ Branches ============ -->
            <section class="card list-section">
                <div class="toolbar">
                    <div>
                        <h2 class="card-title">Branches</h2>
                        <p class="muted card-hint">
                            Teachers join a branch. {{ branchLimit === null ? 'Your plan has no branch limit.' : `Your plan allows ${pluralize(branchLimit, 'branch', 'branches')}.` }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="btn btn-sm"
                        :disabled="!canWrite || branchLimitReached"
                        :title="branchLimitReached ? 'Branch limit reached: upgrade the plan to add more' : ''"
                        @click="openBranch()"
                    >
                        Add branch
                    </button>
                </div>

                <div class="table-wrap">
                <table class="table">
                    <colgroup>
                        <col />
                        <col class="col-code" />
                        <col class="col-address" />
                        <col class="col-count" />
                        <col class="col-count" />
                        <col class="col-actions-wide" />
                    </colgroup>
                    <thead>
                        <tr>
                            <th>Branch</th>
                            <th>Code</th>
                            <th>Address</th>
                            <th class="text-right">Teachers</th>
                            <th class="text-right">Students</th>
                            <th class="table-actions"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="branch in organization.branches" :key="branch.id">
                            <td class="branch-cell">
                                <span class="name">{{ branch.name }}</span>
                                <StatusBadge v-if="branch.isMain" tone="info">Main</StatusBadge>
                            </td>
                            <td class="mono">{{ branch.code }}</td>
                            <td class="ellipsis muted">{{ branch.address || '—' }}</td>
                            <td class="mono text-right">{{ branch.teachersCount ?? 0 }}</td>
                            <td class="mono text-right">{{ branch.studentsCount ?? 0 }}</td>
                            <td class="table-actions">
                                <button
                                    v-if="!branch.isMain"
                                    type="button"
                                    class="btn btn-link btn-sm btn-icon"
                                    :disabled="!canWrite"
                                    :title="`Make ${branch.name} the main branch`"
                                    :aria-label="`Make ${branch.name} the main branch`"
                                    @click="makeMain(branch)"
                                >
                                    <AppIcon name="star" />
                                </button>
                                <span v-else class="btn btn-link btn-sm btn-icon is-main" title="Main branch" aria-label="Main branch">
                                    <AppIcon name="star-filled" />
                                </span>
                                <button
                                    type="button"
                                    class="btn btn-link btn-sm btn-icon"
                                    :disabled="!canWrite"
                                    :title="`Edit ${branch.name}`"
                                    :aria-label="`Edit ${branch.name}`"
                                    @click="openBranch(branch)"
                                >
                                    <AppIcon name="pencil" />
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-link btn-sm btn-icon text-danger"
                                    :disabled="!canWrite || branch.isMain || deletingBranch === branch.id"
                                    :title="branch.isMain ? 'The main branch cannot be removed' : `Remove ${branch.name}`"
                                    :aria-label="`Remove ${branch.name}`"
                                    @click="confirmingBranch = branch"
                                >
                                    <AppIcon name="trash" />
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
                </div>
            </section>

            <!-- Recent students and members, last on the page (school admins only) -->
            <RecentLists v-if="auth.isAdmin" :students="recentStudents" :users="recentUsers" :loading="recentLoading" />
        </template>

        <!-- Plan change / cancellation request -->
        <AppModal :model-value="planModalOpen" :title="planForm.action === PLAN_REQUEST_ACTIONS.CANCEL ? 'Cancel subscription' : 'Request a plan change'" width="480px" :closable="!savingPlanRequest" :close-on-backdrop="false" @update:model-value="planModalOpen = false">
            <form v-if="planModalOpen" class="form" novalidate @submit.prevent="submitPlanRequest">
                <AppField label="I want to" :error="planErrors.action" required>
                    <div class="choices">
                        <label class="choice" :class="{ selected: planForm.action === PLAN_REQUEST_ACTIONS.CHANGE }">
                            <input v-model="planForm.action" type="radio" name="plan-action" :value="PLAN_REQUEST_ACTIONS.CHANGE" :disabled="savingPlanRequest" />
                            <span>{{ subscription?.isTrial || !subscription ? 'Subscribe to a plan' : 'Move to another plan' }}</span>
                        </label>
                        <label v-if="subscription && !subscription.isReadOnly" class="choice" :class="{ selected: planForm.action === PLAN_REQUEST_ACTIONS.CANCEL }">
                            <input v-model="planForm.action" type="radio" name="plan-action" :value="PLAN_REQUEST_ACTIONS.CANCEL" :disabled="savingPlanRequest" />
                            <span>Cancel the subscription</span>
                        </label>
                    </div>
                </AppField>

                <AppField v-if="planForm.action === PLAN_REQUEST_ACTIONS.CHANGE" label="Plan" for="plan-request-plan" :error="planErrors.plan_id" required>
                    <select id="plan-request-plan" v-model="planForm.plan_id" class="input" :disabled="savingPlanRequest">
                        <option value="">Select a plan</option>
                        <option v-for="plan in planOptions" :key="plan.id" :value="plan.id">
                            {{ plan.name }} · {{ plan.branchesLabel }} · {{ plan.teachersLabel }} · {{ plan.studentsLabel }} · {{ plan.priceLabel }}
                        </option>
                    </select>
                </AppField>

                <p v-else class="muted">
                    Once the provider approves, the school becomes read-only at the end of the current period: records can still be
                    viewed but not changed.
                </p>

                <AppField label="Message to the provider" for="plan-request-message" :error="planErrors.message" hint="Optional.">
                    <input id="plan-request-message" v-model="planForm.message" class="input" type="text" maxlength="255" :disabled="savingPlanRequest" />
                </AppField>

                <div class="actions">
                    <button type="button" class="btn btn-secondary" :disabled="savingPlanRequest" @click="planModalOpen = false">Close</button>
                    <button type="submit" class="btn" :class="{ 'btn-danger': planForm.action === PLAN_REQUEST_ACTIONS.CANCEL }" :disabled="savingPlanRequest">
                        {{ savingPlanRequest ? 'Sending…' : 'Send request' }}
                    </button>
                </div>
            </form>
        </AppModal>

        <ConfirmDialog
            v-model="confirmingWithdraw"
            title="Withdraw request"
            confirm-text="Withdraw"
            :danger="false"
            :busy="withdrawing"
            @confirm="withdrawPlanRequest"
        >
            Withdraw your pending request ({{ pendingPlanRequest?.summary }})?
        </ConfirmDialog>

        <!-- Branch add / edit -->
        <AppModal :model-value="branchModalOpen" :title="branchModalTitle" width="440px" :closable="!savingBranch" :close-on-backdrop="false" @update:model-value="branchModalOpen = false">
            <form v-if="branchModalOpen" class="form" novalidate @submit.prevent="saveBranch">
                <AppField label="Branch name" for="branch-name" :error="branchErrors.name" required>
                    <input id="branch-name" v-model="branchForm.name" class="input" type="text" maxlength="100" :disabled="savingBranch" />
                </AppField>
                <AppField label="Address" for="branch-address" :error="branchErrors.address">
                    <input id="branch-address" v-model="branchForm.address" class="input" type="text" :disabled="savingBranch" />
                </AppField>
                <AppField :error="branchErrors.is_main">
                    <label class="checkbox">
                        <input v-model="branchForm.is_main" type="checkbox" :disabled="savingBranch || editingBranch?.isMain" />
                        Main branch
                    </label>
                </AppField>
                <div class="actions">
                    <button type="button" class="btn btn-secondary" :disabled="savingBranch" @click="branchModalOpen = false">Cancel</button>
                    <button type="submit" class="btn" :disabled="savingBranch">
                        {{ savingBranch ? 'Saving…' : (editingBranch ? 'Save changes' : 'Add branch') }}
                    </button>
                </div>
            </form>
        </AppModal>

        <!-- Branch removal -->
        <ConfirmDialog
            :model-value="confirmingBranch !== null"
            title="Remove branch"
            confirm-text="Remove"
            :busy="deletingBranch !== null"
            @update:model-value="confirmingBranch = null"
            @confirm="deleteBranch"
        >
            Remove the branch <strong>{{ confirmingBranch?.name }}</strong>? Only empty branches can be removed.
        </ConfirmDialog>

    </div>
</template>

<style scoped>
.admin-organization {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.page-header {
    margin-bottom: 0;
}

.subtitle {
    margin: 4px 0 0;
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}

.code {
    font-weight: 600;
    color: var(--text);
    letter-spacing: 0.04em;
}

.pair > .card {
    min-width: 0;
}

.card-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}

.subscription-card {
    display: flex;
    flex-direction: column;
}

.subscription-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: auto;
    padding-top: 16px;
}

.address {
    margin: 0;
    padding: 8px 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--bg);
    color: var(--text);
}

/* Branch name on its own line, the Main pill underneath sized to its text. */
.branch-cell .name {
    display: block;
    margin-bottom: 2px;
}

.form {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 6px;
}

/* Definition grid; cells shrink and wrap instead of overflowing the card. */
dl.info {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: 14px 24px;
    margin: 0;
}

.info-item {
    min-width: 0;
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

.read-only {
    margin: 14px 0 0;
    padding: 8px 10px;
    border: 1px solid rgba(var(--danger-rgb), 0.35);
    border-radius: var(--radius);
    background: rgba(var(--danger-rgb), 0.06);
    color: var(--danger);
    font-size: 0.92rem;
}

.plan-request {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    margin-top: 14px;
    padding: 10px 12px;
    border: 1px solid rgba(var(--warn-rgb), 0.4);
    border-radius: var(--radius);
    background: rgba(var(--warn-rgb), 0.08);
}

.plan-request-title {
    margin: 0 0 2px;
    font-weight: 500;
}

.plan-history {
    margin-top: 10px;
}

.plan-history-list {
    margin: 6px 0 0;
    padding: 0;
    list-style: none;
    display: flex;
    flex-direction: column;
    gap: 6px;
    font-size: 0.92rem;
}

.hint {
    margin: 14px 0 0;
    font-size: 0.85rem;
}

.small {
    font-size: 0.85rem;
}

.choices {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.choice {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    cursor: pointer;
}

.choice.selected {
    border-color: var(--primary);
    background: rgba(var(--primary-rgb), 0.05);
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

.card-hint {
    margin: 2px 0 0;
    font-size: 0.85rem;
}

.table-wrap {
    overflow-x: auto;
}

.table {
    table-layout: fixed;
    min-width: 720px;
}

.col-code {
    width: 140px;
}

.col-address {
    width: 28%;
}

.col-count {
    width: 90px;
}

.col-branch {
    width: 150px;
}

.col-date {
    width: 120px;
}

.col-status {
    width: 150px;
}

.col-actions-wide {
    width: 170px;
}

.name {
    font-weight: 500;
    display: block;
}

td .small {
    display: block;
    font-size: 0.82rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.inline-badge {
    margin-left: 8px;
}

.is-main {
    color: var(--primary);
    cursor: default;
}

.is-main:hover {
    background: transparent;
}

.ellipsis {
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
