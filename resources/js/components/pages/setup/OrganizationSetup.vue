<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { RouterLink, useRouter } from 'vue-router';
import { useAuthStore } from '../../../stores/auth';
import { notify } from '../../../stores/notifications';
import { tenantService } from '../../../services';
import { homeRouteFor } from '../../../router';
import AppField from '../../ui/AppField.vue';

/**
 * Second sign-up step for a school administrator: register the school, its
 * branches (naming them and picking the main one) and the plan to start on.
 * The school's address is the main branch's.
 */
const auth = useAuthStore();
const router = useRouter();

const form = reactive({
    name: '',
    contact_email: auth.user?.email ?? '',
    contact_phone: '',
});

/** Branch rows: the admin names them and marks one as main (the only row is main by itself). */
const branches = ref([{ name: '', address: '' }]);
const mainIndex = ref(0);

/** Plan choice: a free trial (default) or a paid plan, which the owner confirms. */
const plans = ref([]);
const planId = ref('');
const loadingPlans = ref(false);

const fieldErrors = ref({});
const formError = ref('');
const submitting = ref(false);

/** The plan the school signs up for: the paid one it picked, else the plan its free trial runs on. */
const chosenPlan = computed(() => (planId.value
    ? plans.value.find((plan) => String(plan.id) === String(planId.value))
    : (plans.value.find((plan) => plan.isTrialDefault) ?? plans.value[0])) ?? null);

/** How many branches that plan allows (null while the plans load). */
const branchLimit = computed(() => chosenPlan.value?.maxBranches ?? null);

const branchLimitReached = computed(() => branchLimit.value !== null && branches.value.length >= branchLimit.value);

const branchHint = computed(() => {
    const base = "Name each branch and tick the main one; the school's address is the main branch's.";

    if (branchLimit.value === null) {
        return `${base} You can add more later, within your plan's limit.`;
    }

    const plan = planId.value ? chosenPlan.value.name : `${chosenPlan.value.name} (the free trial)`;
    const allowed = branchLimit.value === 1 ? '1 branch' : `up to ${branchLimit.value} branches`;

    return `${base} The ${plan} plan allows ${allowed}; pick a bigger plan below for more. You can add more later, within your plan's limit.`;
});

const filledBranches = computed(() => branches.value
    .map((branch, index) => ({ name: branch.name.trim(), address: branch.address.trim() || null, is_main: index === mainIndex.value }))
    .filter((branch) => branch.name !== ''));

function addBranch() {
    branches.value.push({ name: '', address: '' });
}

function removeBranch(index) {
    if (branches.value.length === 1) {
        branches.value[0] = { name: '', address: '' };
        return;
    }

    branches.value.splice(index, 1);

    if (mainIndex.value === index) {
        mainIndex.value = 0;
    } else if (mainIndex.value > index) {
        mainIndex.value -= 1;
    }
}

function branchError(index, field = 'name') {
    return fieldErrors.value[`branches.${index}.${field}`] ?? '';
}

async function loadPlans() {
    loadingPlans.value = true;

    try {
        plans.value = await tenantService.listPlans();
    } catch (err) {
        notify.error(err?.message ?? 'Failed to load the plans.');
    } finally {
        loadingPlans.value = false;
    }
}

function validate() {
    const errors = {};

    if (!form.name.trim()) {
        errors.name = ['The school name is required.'];
    }

    const seen = new Set();
    let named = 0;

    branches.value.forEach((branch, index) => {
        const key = branch.name.trim().toLowerCase();

        if (!key) {
            return;
        }

        named += 1;

        if (seen.has(key)) {
            errors[`branches.${index}.name`] = ['Each branch needs a different name.'];
        }

        seen.add(key);
    });

    if (named === 0) {
        errors.branches = ['Name at least one branch.'];
    } else if (branchLimit.value !== null && named > branchLimit.value) {
        errors.branches = [`The ${chosenPlan.value.name} plan allows up to ${branchLimit.value} ${branchLimit.value === 1 ? 'branch' : 'branches'}, but you named ${named}. Remove some, or pick a plan with more branches.`];
    }

    if (!branches.value[mainIndex.value]?.name.trim()) {
        errors.branches = ['Mark one of the named branches as the main branch.'];
    }

    fieldErrors.value = errors;

    return Object.keys(errors).length === 0;
}

async function submit() {
    formError.value = '';

    if (!validate()) {
        return;
    }

    submitting.value = true;

    try {
        const user = await auth.setupOrganization({
            name: form.name.trim(),
            contact_email: form.contact_email.trim() || null,
            contact_phone: form.contact_phone.trim() || null,
            branches: filledBranches.value,
            plan_id: planId.value ? Number(planId.value) : null,
        });

        notify.success(`${user.organizationName} is registered. Share the code ${user.organization?.code} with your teachers.`);

        if (planId.value) {
            notify.info('Your plan request was sent to the provider; you start on a free trial meanwhile.');
        }

        await router.replace(homeRouteFor(user));
    } catch (err) {
        if (err?.isValidation) {
            fieldErrors.value = err.errors;
            formError.value = err.hasFieldErrors ? '' : err.message;
        } else {
            formError.value = err?.message ?? 'Unable to register your school.';
        }
    } finally {
        submitting.value = false;
    }
}

onMounted(loadPlans);
</script>

<template>
    <div class="setup">
        <div class="card setup-card">
            <p class="muted step">Step 2 of 2</p>
            <h1>Register your school</h1>
            <p class="muted intro">
                Welcome, {{ auth.user?.firstName }}. Tell us about your school and its branches (campuses). Teachers
                will join a branch using the school code you get at the end of this step.
                Is your school already registered? <RouterLink :to="{ name: 'setup-join' }">Enter its code and ask to join as an administrator</RouterLink>.
            </p>

            <form class="form" novalidate @submit.prevent="submit">
                <p v-if="formError" class="form-error" role="alert">{{ formError }}</p>

                <AppField label="School name" for="org-name" :error="fieldErrors.name" required>
                    <input id="org-name" v-model="form.name" class="input" type="text" autocomplete="organization" autofocus :disabled="submitting" />
                </AppField>

                <div class="pair">
                    <AppField label="Contact email" for="org-email" :error="fieldErrors.contact_email">
                        <input id="org-email" v-model="form.contact_email" class="input" type="email" :disabled="submitting" />
                    </AppField>
                    <AppField label="Contact phone" for="org-phone" :error="fieldErrors.contact_phone">
                        <input id="org-phone" v-model="form.contact_phone" class="input" type="tel" :disabled="submitting" />
                    </AppField>
                </div>

                <AppField
                    label="Branches"
                    :error="fieldErrors.branches"
                    :hint="branchHint"
                    required
                >
                    <div class="branches">
                        <div v-for="(branch, index) in branches" :key="index" class="branch-row" :class="{ main: mainIndex === index }">
                            <label class="main-pick" :title="mainIndex === index ? 'Main branch' : 'Make this the main branch'">
                                <input v-model="mainIndex" type="radio" name="main-branch" :value="index" :disabled="submitting" />
                                <span>Main</span>
                            </label>
                            <div class="branch-fields">
                                <input
                                    v-model="branch.name"
                                    class="input"
                                    :class="{ 'input-error': branchError(index) }"
                                    type="text"
                                    placeholder="Branch name, e.g. Main Campus"
                                    :aria-label="`Branch ${index + 1} name`"
                                    :disabled="submitting"
                                />
                                <input
                                    v-model="branch.address"
                                    class="input"
                                    :class="{ 'input-error': branchError(index, 'address') }"
                                    type="text"
                                    placeholder="Address (optional)"
                                    :aria-label="`Branch ${index + 1} address`"
                                    :disabled="submitting"
                                />
                                <small v-if="branchError(index)" class="branch-error">{{ branchError(index) }}</small>
                                <small v-else-if="branchError(index, 'address')" class="branch-error">{{ branchError(index, 'address') }}</small>
                            </div>
                            <button
                                type="button"
                                class="btn btn-link btn-sm text-danger"
                                :disabled="submitting"
                                @click="removeBranch(index)"
                            >
                                Remove
                            </button>
                        </div>
                        <button
                            type="button"
                            class="btn btn-secondary btn-sm add"
                            :disabled="submitting || branches.length >= 20 || branchLimitReached"
                            :title="branchLimitReached ? `The ${chosenPlan.name} plan allows ${branchLimit === 1 ? '1 branch' : `up to ${branchLimit} branches`}` : ''"
                            @click="addBranch"
                        >
                            Add another branch
                        </button>
                    </div>
                </AppField>

                <AppField label="Plan" :error="fieldErrors.plan_id" hint="Every school starts on a free trial. Pick a paid plan to have the provider set it up; you can also change plans later from the Organization page.">
                    <div class="plans" role="radiogroup" aria-label="Plan">
                        <label class="plan" :class="{ selected: planId === '' }">
                            <input v-model="planId" type="radio" name="plan" value="" :disabled="submitting" />
                            <span class="plan-body">
                                <span class="plan-title">Free trial</span>
                                <span class="muted plan-detail">30 days, no commitment. Upgrade any time.</span>
                            </span>
                        </label>
                        <label v-for="plan in plans" :key="plan.id" class="plan" :class="{ selected: String(planId) === String(plan.id) }">
                            <input v-model="planId" type="radio" name="plan" :value="plan.id" :disabled="submitting" />
                            <span class="plan-body">
                                <span class="plan-title">{{ plan.name }} <span class="muted plan-price">· {{ plan.priceLabel }}</span></span>
                                <span class="muted plan-detail">{{ plan.branchesLabel }} · {{ plan.adminsLabel }} · {{ plan.teachersLabel }} · {{ plan.studentsLabel }}<template v-if="plan.description"> · {{ plan.description }}</template></span>
                            </span>
                        </label>
                        <p v-if="loadingPlans" class="muted plan-detail">Loading plans…</p>
                    </div>
                </AppField>

                <button type="submit" class="btn submit" :disabled="submitting">
                    {{ submitting ? 'Registering…' : 'Register school' }}
                </button>
            </form>
        </div>
    </div>
</template>

<style scoped>
.setup {
    display: flex;
    justify-content: center;
    padding: 16px 0;
}

.setup-card {
    width: 100%;
    max-width: 600px;
    padding: 32px;
}

.step {
    margin: 0 0 4px;
    font-size: 0.85rem;
    letter-spacing: 0.02em;
    text-transform: uppercase;
}

.intro {
    margin: 6px 0 20px;
}

.form {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.form-error {
    margin: 0;
    padding: 8px 10px;
    border: 1px solid var(--danger);
    border-radius: var(--radius);
    color: var(--danger);
    background: rgba(var(--danger-rgb), 0.06);
    font-size: 0.92rem;
}

.pair {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px 16px;
}

.branches {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.branch-row {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) auto;
    align-items: start;
    gap: 10px;
    padding: 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
}

.branch-row.main {
    border-color: rgba(var(--primary-rgb), 0.35);
    background: rgba(var(--primary-rgb), 0.04);
}

.main-pick {
    display: flex;
    align-items: center;
    gap: 6px;
    padding-top: 8px;
    font-size: 0.85rem;
    color: var(--muted);
    cursor: pointer;
    white-space: nowrap;
}

.branch-row.main .main-pick {
    color: var(--primary);
    font-weight: 600;
}

.branch-fields {
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
}

.branch-error {
    color: var(--danger);
    font-size: 0.85rem;
}

.add {
    align-self: flex-start;
}

.plans {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.plan {
    display: flex;
    gap: 10px;
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    cursor: pointer;
}

.plan.selected {
    border-color: var(--primary);
    background: rgba(var(--primary-rgb), 0.05);
}

.plan input {
    margin-top: 3px;
    flex-shrink: 0;
}

.plan-body {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}

.plan-title {
    font-weight: 600;
}

.plan-price {
    font-weight: 400;
}

.plan-detail {
    font-size: 0.85rem;
    margin: 0;
}

.submit {
    width: 100%;
    margin-top: 6px;
}

@media (max-width: 480px) {
    .pair {
        grid-template-columns: 1fr;
    }

    .branch-row {
        grid-template-columns: 1fr;
    }
}
</style>
