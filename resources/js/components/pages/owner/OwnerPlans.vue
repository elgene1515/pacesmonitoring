<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { ownerService } from '../../../services';
import { useAuthStore } from '../../../stores/auth';
import { notify } from '../../../stores/notifications';
import AppField from '../../ui/AppField.vue';
import AppModal from '../../ui/AppModal.vue';
import EmptyState from '../../ui/EmptyState.vue';
import AppIcon from '../../ui/AppIcon.vue';
import PasswordField from '../../ui/PasswordField.vue';
import StatusBadge from '../../ui/StatusBadge.vue';

/**
 * Subscription plans. Retiring a plan hides it from new subscriptions;
 * organizations already on it keep it. A plan nobody ever subscribed to can
 * be deleted outright (owner only).
 */
const auth = useAuthStore();

const plans = ref([]);
const loading = ref(true);

const modalOpen = ref(false);
const editing = ref(null);
const form = reactive({ name: '', slug: '', description: '', max_branches: 1, max_admins: '', max_teachers: '', max_students: '', price_per_year: 0, currency: 'PHP', is_active: true, sort_order: '' });
const errors = ref({});
const saving = ref(false);

const deleting = ref(null);
const deletePassword = ref('');
const deleteErrors = ref({});
const deletingBusy = ref(false);

const modalTitle = computed(() => (editing.value ? 'Edit plan' : 'Add plan'));

function open(plan = null) {
    editing.value = plan;
    form.name = plan?.name ?? '';
    form.slug = plan?.slug ?? '';
    form.description = plan?.description ?? '';
    form.max_branches = plan?.maxBranches ?? 1;
    form.max_admins = plan?.maxAdmins ?? '';
    form.max_teachers = plan?.maxTeachers ?? '';
    form.max_students = plan?.maxStudents ?? '';
    form.price_per_year = plan?.pricePerYear ?? 0;
    form.currency = plan?.currency ?? 'PHP';
    form.is_active = plan ? plan.isActive : true;
    form.sort_order = plan?.sortOrder ?? '';
    errors.value = {};
    modalOpen.value = true;
}

function validate() {
    const next = {};

    if (!form.name.trim()) {
        next.name = ['The plan name is required.'];
    }

    if (!(Number(form.max_branches) >= 1)) {
        next.max_branches = ['At least 1 branch.'];
    }

    if (form.max_admins !== '' && form.max_admins !== null && !(Number(form.max_admins) >= 1)) {
        next.max_admins = ['At least 1 admin, or leave blank for unlimited.'];
    }

    if (form.max_teachers !== '' && form.max_teachers !== null && !(Number(form.max_teachers) >= 1)) {
        next.max_teachers = ['At least 1 teacher, or leave blank for unlimited.'];
    }

    if (form.max_students !== '' && form.max_students !== null && !(Number(form.max_students) >= 1)) {
        next.max_students = ['At least 1 student, or leave blank for unlimited.'];
    }

    if (!(Number(form.price_per_year) >= 0)) {
        next.price_per_year = ['The price cannot be negative.'];
    }

    errors.value = next;

    return Object.keys(next).length === 0;
}

async function save() {
    if (saving.value || !validate()) {
        return;
    }

    saving.value = true;

    try {
        const payload = {
            name: form.name.trim(),
            slug: form.slug.trim() || null,
            description: form.description.trim() || null,
            max_branches: Number(form.max_branches),
            max_admins: form.max_admins === '' || form.max_admins === null ? null : Number(form.max_admins),
            max_teachers: form.max_teachers === '' || form.max_teachers === null ? null : Number(form.max_teachers),
            max_students: form.max_students === '' || form.max_students === null ? null : Number(form.max_students),
            price_per_year: Number(form.price_per_year),
            currency: (form.currency || 'PHP').toUpperCase(),
            is_active: Boolean(form.is_active),
            sort_order: form.sort_order === '' || form.sort_order === null ? null : Number(form.sort_order),
        };

        if (editing.value) {
            await ownerService.updatePlan(editing.value.id, payload);
            notify.success('Plan updated.');
        } else {
            await ownerService.createPlan(payload);
            notify.success(`Plan ${payload.name} added.`);
        }

        modalOpen.value = false;
        await load();
    } catch (err) {
        if (err?.isValidation && err.hasFieldErrors) {
            errors.value = err.errors;
        } else {
            notify.error(err?.message ?? 'Unable to save the plan.');
        }
    } finally {
        saving.value = false;
    }
}

async function toggleActive(plan) {
    try {
        await ownerService.updatePlan(plan.id, { is_active: !plan.isActive });
        notify.success(plan.isActive ? `${plan.name} retired.` : `${plan.name} is available again.`);
        await load();
    } catch (err) {
        notify.error(err?.message ?? 'Unable to update the plan.');
    }
}

function openDelete(plan) {
    deleting.value = plan;
    deletePassword.value = '';
    deleteErrors.value = {};
}

async function remove() {
    const plan = deleting.value;

    if (!plan || deletingBusy.value) {
        return;
    }

    deleteErrors.value = {};

    if (!deletePassword.value) {
        deleteErrors.value = { admin_password: ['Enter your password to confirm.'] };
        return;
    }

    deletingBusy.value = true;

    try {
        await ownerService.deletePlan(plan.id, deletePassword.value);
        notify.success(`Plan ${plan.name} deleted.`);
        deleting.value = null;
        await load();
    } catch (err) {
        if (err?.isValidation && err.errors.admin_password) {
            deleteErrors.value = err.errors;
        } else {
            notify.error(err?.isValidation ? (err.first('plan') ?? err.message) : (err?.message ?? 'Unable to delete the plan.'));
            deleting.value = null;
        }
    } finally {
        deletingBusy.value = false;
    }
}

async function load() {
    loading.value = true;

    try {
        plans.value = await ownerService.listPlans();
    } catch (err) {
        notify.error(err?.message ?? 'Failed to load plans.');
    } finally {
        loading.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div class="owner-plans">
        <div class="page-header">
            <div>
                <h1>Plans</h1>
                <p class="muted subtitle">
                    Subscription tiers and their limits. New organizations start on a trial of the Starter plan; schools
                    pick a plan at sign-up or request a change later, and you confirm it from the organization's page.
                </p>
            </div>
            <div class="page-actions">
                <button type="button" class="btn" @click="open()">Add plan</button>
            </div>
        </div>

        <div class="card">
            <p v-if="loading" class="muted state">Loading…</p>

            <EmptyState v-else-if="plans.length === 0" message="No plans yet.">
                <template #action>
                    <button type="button" class="btn btn-secondary btn-sm" @click="open()">Add plan</button>
                </template>
            </EmptyState>

            <div v-else class="table-wrap">
                <table class="table">
                    <colgroup>
                        <col />
                        <col class="col-limits" />
                        <col class="col-price" />
                        <col class="col-count" />
                        <col class="col-status" />
                        <col class="col-actions" />
                    </colgroup>
                    <thead>
                        <tr>
                            <th>Plan</th>
                            <th>Limits</th>
                            <th>Price</th>
                            <th class="text-right">Subscriptions</th>
                            <th>Status</th>
                            <th class="table-actions"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="plan in plans" :key="plan.id">
                            <td>
                                <span class="name">{{ plan.name }}</span>
                                <span class="muted small">{{ plan.slug }}<template v-if="plan.description"> · {{ plan.description }}</template></span>
                            </td>
                            <td>{{ plan.branchesLabel }} · {{ plan.adminsLabel }} · {{ plan.teachersLabel }} · {{ plan.studentsLabel }}</td>
                            <td class="nowrap">{{ plan.priceLabel }}</td>
                            <td class="mono text-right">{{ plan.subscriptionsCount ?? 0 }}</td>
                            <td><StatusBadge :tone="plan.isActive ? 'ok' : 'muted'">{{ plan.isActive ? 'Available' : 'Retired' }}</StatusBadge></td>
                            <td class="table-actions">
                                <button type="button" class="btn btn-link btn-sm btn-icon" :title="`Edit ${plan.name}`" :aria-label="`Edit ${plan.name}`" @click="open(plan)">
                                    <AppIcon name="pencil" />
                                </button>
                                <button type="button" class="btn btn-link btn-sm" @click="toggleActive(plan)">
                                    {{ plan.isActive ? 'Retire' : 'Restore' }}
                                </button>
                                <button
                                    v-if="auth.isOwner"
                                    type="button"
                                    class="btn btn-link btn-sm btn-icon text-danger"
                                    :disabled="(plan.subscriptionsCount ?? 0) > 0"
                                    :title="(plan.subscriptionsCount ?? 0) > 0 ? 'Plans with subscriptions on record can only be retired' : `Delete ${plan.name}`"
                                    :aria-label="`Delete ${plan.name}`"
                                    @click="openDelete(plan)"
                                >
                                    <AppIcon name="trash" />
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <AppModal :model-value="modalOpen" :title="modalTitle" width="480px" :closable="!saving" :close-on-backdrop="false" @update:model-value="modalOpen = false">
            <form v-if="modalOpen" class="form" novalidate @submit.prevent="save">
                <AppField label="Name" for="plan-name" :error="errors.name" required>
                    <input id="plan-name" v-model="form.name" class="input" type="text" maxlength="100" :disabled="saving" />
                </AppField>
                <AppField label="Slug" for="plan-slug" :error="errors.slug" hint="Short identifier; made from the name when blank.">
                    <input id="plan-slug" v-model="form.slug" class="input mono" type="text" maxlength="50" :disabled="saving" />
                </AppField>
                <AppField label="Description" for="plan-description" :error="errors.description">
                    <input id="plan-description" v-model="form.description" class="input" type="text" maxlength="255" :disabled="saving" />
                </AppField>
                <div class="pair">
                    <AppField label="Branch limit" for="plan-branches" :error="errors.max_branches" required>
                        <input id="plan-branches" v-model="form.max_branches" class="input" type="number" min="1" :disabled="saving" />
                    </AppField>
                    <AppField label="Admin limit" for="plan-admins" :error="errors.max_admins" hint="School administrators. Blank = unlimited.">
                        <input id="plan-admins" v-model="form.max_admins" class="input" type="number" min="1" :disabled="saving" />
                    </AppField>
                </div>
                <div class="pair">
                    <AppField label="Teacher limit" for="plan-teachers" :error="errors.max_teachers" hint="Blank = unlimited.">
                        <input id="plan-teachers" v-model="form.max_teachers" class="input" type="number" min="1" :disabled="saving" />
                    </AppField>
                    <AppField label="Student limit" for="plan-students" :error="errors.max_students" hint="Blank = unlimited.">
                        <input id="plan-students" v-model="form.max_students" class="input" type="number" min="1" :disabled="saving" />
                    </AppField>
                </div>
                <div class="pair">
                    <AppField label="Price per year" for="plan-price" :error="errors.price_per_year" required>
                        <input id="plan-price" v-model="form.price_per_year" class="input" type="number" min="0" step="0.01" :disabled="saving" />
                    </AppField>
                    <AppField label="Currency" for="plan-currency" :error="errors.currency">
                        <input id="plan-currency" v-model="form.currency" class="input" type="text" maxlength="3" :disabled="saving" />
                    </AppField>
                </div>
                <div class="pair">
                    <AppField label="Sort order" for="plan-sort" :error="errors.sort_order">
                        <input id="plan-sort" v-model="form.sort_order" class="input" type="number" min="0" :disabled="saving" />
                    </AppField>
                    <AppField :error="errors.is_active">
                        <label class="checkbox tall">
                            <input v-model="form.is_active" type="checkbox" :disabled="saving" />
                            Available for new subscriptions
                        </label>
                    </AppField>
                </div>
                <div class="actions">
                    <button type="button" class="btn btn-secondary" :disabled="saving" @click="modalOpen = false">Cancel</button>
                    <button type="submit" class="btn" :disabled="saving">{{ saving ? 'Saving…' : (editing ? 'Save changes' : 'Add plan') }}</button>
                </div>
            </form>
        </AppModal>

        <!-- Delete (owner only, password confirmed) -->
        <AppModal :model-value="deleting !== null" title="Delete plan" width="440px" :closable="!deletingBusy" :close-on-backdrop="false" @update:model-value="deleting = null">
            <form v-if="deleting" class="form" novalidate @submit.prevent="remove">
                <p class="muted">
                    Delete <strong>{{ deleting.name }}</strong>? Only plans nobody has ever subscribed to can be deleted; pending requests
                    for it are removed as well.
                </p>
                <AppField label="Your password" for="plan-delete-password" :error="deleteErrors.admin_password" hint="Confirm it's you." required>
                    <PasswordField id="plan-delete-password" v-model="deletePassword" autocomplete="current-password" :disabled="deletingBusy" />
                </AppField>
                <div class="actions">
                    <button type="button" class="btn btn-secondary" :disabled="deletingBusy" @click="deleting = null">Cancel</button>
                    <button type="submit" class="btn btn-danger" :disabled="deletingBusy">{{ deletingBusy ? 'Deleting…' : 'Delete plan' }}</button>
                </div>
            </form>
        </AppModal>
    </div>
</template>

<style scoped>
.owner-plans {
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

.card {
    padding: 0;
    overflow: hidden;
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
    min-width: 860px;
}

.col-limits {
    width: 32%;
}

.col-price {
    width: 150px;
}

.col-count {
    width: 110px;
}

.col-status {
    width: 110px;
}

.col-actions {
    width: 150px;
}

.name {
    font-weight: 500;
    display: block;
}

.small {
    display: block;
    font-size: 0.8rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.form {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.pair {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px 16px;
}

.triple {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 14px 16px;
}

.checkbox.tall {
    margin-top: 26px;
}

.actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 6px;
}

.table-actions {
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
    .pair,
    .triple {
        grid-template-columns: 1fr;
    }
}
</style>
