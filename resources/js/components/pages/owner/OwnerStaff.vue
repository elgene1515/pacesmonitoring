<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { ownerService } from '../../../services';
import { useAuthStore } from '../../../stores/auth';
import { useScopeStore } from '../../../stores/scope';
import { notify } from '../../../stores/notifications';
import { formatDate } from '../../../core/format';
import { ROLES } from '../../../models';
import AppField from '../../ui/AppField.vue';
import AppIcon from '../../ui/AppIcon.vue';
import AppModal from '../../ui/AppModal.vue';
import ConfirmDialog from '../../ui/ConfirmDialog.vue';
import EmptyState from '../../ui/EmptyState.vue';
import PasswordField from '../../ui/PasswordField.vue';
import StatusBadge from '../../ui/StatusBadge.vue';

/**
 * Platform staff (owner only): add owners and vice admins, switch their role,
 * remove them. A vice admin sees everything the owner sees except this page,
 * deactivating organizations and deleting plans.
 */
const auth = useAuthStore();
const scope = useScopeStore();

const staff = ref([]);
const loading = ref(true);

const modalOpen = ref(false);
const form = reactive({ name: '', email: '', password: '', password_confirmation: '', role: ROLES.VICE_ADMIN });
const errors = ref({});
const saving = ref(false);

const changingRole = ref(null);
const removing = ref(null);
const confirming = ref(null);

const ownerCount = computed(() => staff.value.filter((member) => member.isOwner).length);

const ROLE_OPTIONS = [
    { value: ROLES.OWNER, label: 'CEO', hint: 'Full access, including this page.' },
    { value: ROLES.VICE_ADMIN, label: 'Admin', hint: 'Everything except managing staff, deactivating schools and deleting plans.' },
];

function open() {
    form.name = '';
    form.email = '';
    form.password = '';
    form.password_confirmation = '';
    form.role = ROLES.VICE_ADMIN;
    errors.value = {};
    modalOpen.value = true;
}

function validate() {
    const next = {};

    if (!form.name.trim()) {
        next.name = ['Name is required.'];
    }

    if (!form.email.trim()) {
        next.email = ['Email is required.'];
    }

    if (!form.password) {
        next.password = ['Password is required.'];
    } else if (form.password.length < 8) {
        next.password = ['Password must be at least 8 characters.'];
    } else if (form.password !== form.password_confirmation) {
        next.password_confirmation = ['Passwords do not match.'];
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
        const created = await ownerService.createStaff({
            name: form.name.trim(),
            email: form.email.trim(),
            password: form.password,
            password_confirmation: form.password_confirmation,
            role: form.role,
        });
        notify.success(`${created.name} added as ${created.roleLabel.toLowerCase()}.`);
        modalOpen.value = false;
        await load();
    } catch (err) {
        if (err?.isValidation && err.hasFieldErrors) {
            errors.value = err.errors;
        } else {
            notify.error(err?.message ?? 'Unable to add the account.');
        }
    } finally {
        saving.value = false;
    }
}

async function changeRole(member, role) {
    if (member.role === role) {
        return;
    }

    changingRole.value = member.id;

    try {
        await ownerService.updateStaffRole(member.id, role);
        notify.success(`${member.name} is now ${role === ROLES.OWNER ? 'a CEO' : 'an admin'}.`);
        await load();

        if (member.id === auth.user?.id) {
            auth.refresh().catch(() => {});
        }
    } catch (err) {
        notify.error(err?.isValidation ? (err.first('role') ?? err.message) : (err?.message ?? 'Unable to change the role.'));
        await load();
    } finally {
        changingRole.value = null;
    }
}

async function remove() {
    const member = confirming.value;

    if (!member) {
        return;
    }

    removing.value = member.id;

    try {
        await ownerService.deleteStaff(member.id);
        notify.success(`${member.name} removed.`);
        confirming.value = null;
        await load();
    } catch (err) {
        notify.error(err?.isValidation ? (err.first('user') ?? err.message) : (err?.message ?? 'Unable to remove the account.'));
    } finally {
        removing.value = null;
    }
}

async function load() {
    loading.value = true;

    try {
        staff.value = await ownerService.listStaff();
    } catch (err) {
        notify.error(err?.message ?? 'Failed to load staff.');
    } finally {
        loading.value = false;
    }
}

onMounted(load);
</script>

<template>
    <div class="owner-staff">
        <div class="page-header">
            <div>
                <h1>Staff</h1>
                <p class="muted subtitle">
                    PACES Monitoring System accounts. A CEO can do everything; an admin sees every organization and manages
                    subscriptions, plans and plan requests, but cannot manage staff, deactivate schools or delete plans.
                </p>
            </div>
            <div class="page-actions">
                <button type="button" class="btn" @click="open">Add staff account</button>
            </div>
        </div>

        <div class="card">
            <p v-if="loading" class="muted state">Loading…</p>

            <EmptyState v-else-if="staff.length === 0" message="No staff accounts." />

            <div v-else class="table-wrap">
                <table class="table">
                    <colgroup>
                        <col />
                        <col class="col-email" />
                        <col class="col-role" />
                        <col class="col-date" />
                        <col class="col-actions" />
                    </colgroup>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Added</th>
                            <th class="table-actions"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="member in staff" :key="member.id">
                            <td class="ellipsis">
                                <span class="name">{{ member.name }}</span>
                                <span v-if="member.id === auth.user?.id" class="muted"> (you)</span>
                            </td>
                            <td class="ellipsis muted">{{ member.email }}</td>
                            <td>
                                <select
                                    class="input role-select"
                                    :value="member.role"
                                    :disabled="changingRole === member.id || (member.isOwner && ownerCount <= 1)"
                                    :title="member.isOwner && ownerCount <= 1 ? 'The last CEO cannot be demoted' : ''"
                                    :aria-label="`Role of ${member.name}`"
                                    @change="changeRole(member, $event.target.value)"
                                >
                                    <option v-for="option in ROLE_OPTIONS" :key="option.value" :value="option.value">{{ option.label }}</option>
                                </select>
                            </td>
                            <td class="nowrap muted">{{ formatDate(member.createdAt) || '—' }}</td>
                            <td class="table-actions">
                                <RouterLink
                                    :to="{ name: 'admin-user-detail', params: { id: member.id } }"
                                    class="btn btn-link btn-sm btn-icon"
                                    :title="`View ${member.name}`"
                                    :aria-label="`View ${member.name}`"
                                    @click="scope.clearScope()"
                                >
                                    <AppIcon name="eye" />
                                </RouterLink>
                                <button
                                    type="button"
                                    class="btn btn-link btn-sm text-danger"
                                    :disabled="removing === member.id || member.id === auth.user?.id || (member.isOwner && ownerCount <= 1)"
                                    @click="confirming = member"
                                >
                                    Remove
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <AppModal :model-value="modalOpen" title="Add staff account" width="460px" :closable="!saving" :close-on-backdrop="false" @update:model-value="modalOpen = false">
            <form v-if="modalOpen" class="form" novalidate @submit.prevent="save">
                <AppField label="Role" :error="errors.role" required>
                    <div class="roles">
                        <label v-for="option in ROLE_OPTIONS" :key="option.value" class="role" :class="{ selected: form.role === option.value }">
                            <input v-model="form.role" type="radio" name="staff-role" :value="option.value" :disabled="saving" />
                            <span class="role-body">
                                <span class="role-title">{{ option.label }}</span>
                                <span class="muted role-hint">{{ option.hint }}</span>
                            </span>
                        </label>
                    </div>
                </AppField>
                <AppField label="Full name" for="staff-name" :error="errors.name" required>
                    <input id="staff-name" v-model="form.name" class="input" type="text" :disabled="saving" />
                </AppField>
                <AppField label="Email" for="staff-email" :error="errors.email" required>
                    <input id="staff-email" v-model="form.email" class="input" type="email" :disabled="saving" />
                </AppField>
                <AppField label="Password" for="staff-password" :error="errors.password" hint="At least 8 characters. Share it with them; they can change it later." required>
                    <PasswordField id="staff-password" v-model="form.password" autocomplete="new-password" :disabled="saving" />
                </AppField>
                <AppField label="Confirm password" for="staff-password-confirmation" :error="errors.password_confirmation" required>
                    <PasswordField id="staff-password-confirmation" v-model="form.password_confirmation" autocomplete="new-password" :disabled="saving" />
                </AppField>
                <div class="actions">
                    <button type="button" class="btn btn-secondary" :disabled="saving" @click="modalOpen = false">Cancel</button>
                    <button type="submit" class="btn" :disabled="saving">{{ saving ? 'Adding…' : 'Add account' }}</button>
                </div>
            </form>
        </AppModal>

        <ConfirmDialog
            :model-value="confirming !== null"
            title="Remove staff account"
            confirm-text="Remove"
            :busy="removing !== null"
            @update:model-value="confirming = null"
            @confirm="remove"
        >
            Remove <strong>{{ confirming?.name }}</strong>'s account? They will no longer be able to sign in.
        </ConfirmDialog>
    </div>
</template>

<style scoped>
.owner-staff {
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
    min-width: 720px;
}

.col-email {
    width: 30%;
}

.col-role {
    width: 150px;
}

.col-date {
    width: 120px;
}

.col-actions {
    width: 140px;
}

.table-actions {
    white-space: nowrap;
}

.name {
    font-weight: 500;
}

.role-select {
    width: auto;
    padding-top: 4px;
    padding-bottom: 4px;
}

.ellipsis {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.form {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.roles {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.role {
    display: flex;
    gap: 10px;
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    cursor: pointer;
}

.role.selected {
    border-color: var(--primary);
    background: rgba(var(--primary-rgb), 0.05);
}

.role input {
    margin-top: 3px;
}

.role-body {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.role-title {
    font-weight: 600;
}

.role-hint {
    font-size: 0.85rem;
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
</style>
