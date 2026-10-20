<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useAuthStore } from '../../stores/auth';
import { notify } from '../../stores/notifications';
import { formatDate } from '../../core/format';
import AppField from '../ui/AppField.vue';
import PasswordField from '../ui/PasswordField.vue';
import StatusBadge from '../ui/StatusBadge.vue';

/**
 * My account: the signed-in member's own details (as an administrator would
 * see them on the user page), then the forms to change name / email and the
 * password. Open to every role.
 */
const auth = useAuthStore();

const user = computed(() => auth.user);

const profileForm = reactive({ name: '', email: '', current_password: '' });
const profileErrors = ref({});
const savingProfile = ref(false);

const passwordForm = reactive({ current_password: '', password: '', password_confirmation: '' });
const passwordErrors = ref({});
const savingPassword = ref(false);
const resendingVerification = ref(false);

watch(
    () => auth.user,
    (current) => {
        profileForm.name = current?.name ?? '';
        profileForm.email = current?.email ?? '';
    },
    { immediate: true },
);

function validateProfile() {
    const errors = {};

    if (!profileForm.name.trim()) {
        errors.name = ['Name is required.'];
    }

    if (!profileForm.email.trim()) {
        errors.email = ['Email is required.'];
    }

    if (!profileForm.current_password) {
        errors.current_password = ['Enter your current password to save these changes.'];
    }

    profileErrors.value = errors;

    return Object.keys(errors).length === 0;
}

async function saveProfile() {
    if (savingProfile.value || !validateProfile()) {
        return;
    }

    savingProfile.value = true;

    try {
        const emailChanged = profileForm.email.trim() !== auth.user?.email;
        await auth.updateProfile({
            name: profileForm.name.trim(),
            email: profileForm.email.trim(),
            current_password: profileForm.current_password,
        });
        profileForm.current_password = '';
        profileErrors.value = {};
        notify.success(emailChanged ? 'Profile updated. Check your new address for a verification link.' : 'Profile updated.');
    } catch (err) {
        if (err?.isValidation && err.hasFieldErrors) {
            profileErrors.value = err.errors;
        } else {
            notify.error(err?.message ?? 'Unable to update your profile.');
        }
    } finally {
        savingProfile.value = false;
    }
}

async function resendVerification() {
    resendingVerification.value = true;

    try {
        notify.success(await auth.resendVerification());
    } catch (err) {
        notify.error(err?.message ?? 'Unable to send a new verification link right now.');
    } finally {
        resendingVerification.value = false;
    }
}

function validatePassword() {
    const errors = {};

    if (!passwordForm.current_password) {
        errors.current_password = ['Current password is required.'];
    }

    if (!passwordForm.password) {
        errors.password = ['New password is required.'];
    } else if (passwordForm.password.length < 8) {
        errors.password = ['Password must be at least 8 characters.'];
    } else if (passwordForm.password !== passwordForm.password_confirmation) {
        errors.password_confirmation = ['Passwords do not match.'];
    }

    passwordErrors.value = errors;

    return Object.keys(errors).length === 0;
}

async function savePassword() {
    if (savingPassword.value || !validatePassword()) {
        return;
    }

    savingPassword.value = true;

    try {
        await auth.updatePassword({ ...passwordForm });
        passwordForm.current_password = '';
        passwordForm.password = '';
        passwordForm.password_confirmation = '';
        passwordErrors.value = {};
        notify.success('Password changed.');
    } catch (err) {
        if (err?.isValidation && err.hasFieldErrors) {
            passwordErrors.value = err.errors;
        } else {
            notify.error(err?.message ?? 'Unable to change your password.');
        }
    } finally {
        savingPassword.value = false;
    }
}
</script>

<template>
    <div v-if="user" class="account">
        <div class="page-header">
            <div>
                <h1>{{ user.name }}</h1>
                <p class="muted subtitle">
                    {{ user.roleLabel }} · {{ user.email }}
                    <template v-if="user.isPlatform"> · {{ user.organizationLabel }}</template>
                    <template v-else-if="user.hasOrganization"> · {{ user.organizationName }}<template v-if="user.branchLabel"> ({{ user.branchLabel }})</template></template>
                    <template v-else> · Not in a school yet</template>
                    · Registered {{ formatDate(user.createdAt) || '—' }}
                </p>
            </div>
        </div>

        <section class="card">
            <h2 class="card-title">User information</h2>
            <dl class="info">
                <div class="info-item">
                    <dt>Name</dt>
                    <dd>{{ user.name }}</dd>
                </div>
                <div class="info-item">
                    <dt>Email</dt>
                    <dd class="email">
                        <span>{{ user.email }}</span>
                        <StatusBadge :tone="user.emailVerified ? 'ok' : 'warn'">{{ user.emailVerified ? 'Verified' : 'Not verified' }}</StatusBadge>
                        <button
                            v-if="!user.emailVerified"
                            type="button"
                            class="btn btn-link btn-sm"
                            :disabled="resendingVerification"
                            @click="resendVerification"
                        >
                            {{ resendingVerification ? 'Sending…' : 'Resend link' }}
                        </button>
                    </dd>
                </div>
                <div class="info-item">
                    <dt>Role</dt>
                    <dd><StatusBadge :tone="user.isPlatform ? 'bad' : user.isAdmin ? 'info' : 'muted'">{{ user.roleLabel }}</StatusBadge></dd>
                </div>
                <div class="info-item">
                    <dt>{{ user.isPlatform ? 'Organization' : 'School' }}</dt>
                    <dd>{{ user.organizationLabel || '—' }}</dd>
                </div>
                <!-- School admins manage every branch, so only teachers show one. -->
                <div v-if="user.isTeacher && user.hasOrganization" class="info-item">
                    <dt>Branch</dt>
                    <dd>{{ user.branchName || '—' }}</dd>
                </div>
                <div v-if="!user.isPlatform && user.subscription" class="info-item">
                    <dt>Plan</dt>
                    <dd class="plan">
                        {{ user.subscription.plan?.name ?? '—' }}
                        <StatusBadge :tone="user.subscription.tone">{{ user.subscription.statusLabel }}</StatusBadge>
                    </dd>
                </div>
                <div class="info-item">
                    <dt>Registered</dt>
                    <dd>{{ formatDate(user.createdAt) || '—' }}</dd>
                </div>
            </dl>
        </section>

        <div class="pair">
            <section class="card">
                <h2 class="card-title">Profile</h2>
                <form class="form" novalidate @submit.prevent="saveProfile">
                    <AppField label="Name" for="settings-name" :error="profileErrors.name" required>
                        <input
                            id="settings-name"
                            v-model="profileForm.name"
                            class="input"
                            type="text"
                            autocomplete="name"
                            :disabled="savingProfile"
                        />
                    </AppField>

                    <AppField
                        label="Email"
                        for="settings-email"
                        :error="profileErrors.email"
                        hint="A new address will need to be verified again."
                        required
                    >
                        <input
                            id="settings-email"
                            v-model="profileForm.email"
                            class="input"
                            type="email"
                            autocomplete="email"
                            :disabled="savingProfile"
                        />
                    </AppField>

                    <AppField
                        label="Current password"
                        for="settings-profile-password"
                        :error="profileErrors.current_password"
                        hint="Required to confirm it is you making the change."
                        required
                    >
                        <PasswordField
                            id="settings-profile-password"
                            v-model="profileForm.current_password"
                            autocomplete="current-password"
                            :disabled="savingProfile"
                        />
                    </AppField>

                    <div class="actions">
                        <button type="submit" class="btn" :disabled="savingProfile">
                            {{ savingProfile ? 'Saving…' : 'Save changes' }}
                        </button>
                    </div>
                </form>
            </section>

            <section class="card">
                <h2 class="card-title">Change password</h2>
                <form class="form" novalidate @submit.prevent="savePassword">
                    <AppField
                        label="Current password"
                        for="settings-current-password"
                        :error="passwordErrors.current_password"
                        required
                    >
                        <PasswordField
                            id="settings-current-password"
                            v-model="passwordForm.current_password"
                            autocomplete="current-password"
                            :disabled="savingPassword"
                        />
                    </AppField>

                    <AppField
                        label="New password"
                        for="settings-password"
                        :error="passwordErrors.password"
                        hint="At least 8 characters."
                        required
                    >
                        <PasswordField
                            id="settings-password"
                            v-model="passwordForm.password"
                            autocomplete="new-password"
                            :disabled="savingPassword"
                        />
                    </AppField>

                    <AppField
                        label="Confirm new password"
                        for="settings-password-confirmation"
                        :error="passwordErrors.password_confirmation"
                        required
                    >
                        <PasswordField
                            id="settings-password-confirmation"
                            v-model="passwordForm.password_confirmation"
                            autocomplete="new-password"
                            :disabled="savingPassword"
                        />
                    </AppField>

                    <div class="actions">
                        <button type="submit" class="btn" :disabled="savingPassword">
                            {{ savingPassword ? 'Saving…' : 'Change password' }}
                        </button>
                    </div>
                </form>
            </section>
        </div>
    </div>
</template>

<style scoped>
.account {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.subtitle {
    margin: 4px 0 0;
}

/* `dl.` keeps this off StatusBadge, whose "info" tone class would otherwise match too. */
dl.info {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: 14px 24px;
    margin: 0;
}

.info-item dt {
    font-size: 0.85rem;
    color: var(--muted);
    margin-bottom: 2px;
}

.info-item dd {
    margin: 0;
}

.info-item .email,
.info-item .plan {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
}

.pair {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: 20px;
    align-items: start;
}

.pair > .card {
    min-width: 0;
}

.form {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.actions {
    display: flex;
    justify-content: flex-end;
    margin-top: 6px;
}
</style>
