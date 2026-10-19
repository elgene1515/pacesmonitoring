<script setup>
import { reactive, ref, watch } from 'vue';
import { useAuthStore } from '../../stores/auth';
import { notify } from '../../stores/notifications';
import AppField from '../ui/AppField.vue';
import PasswordField from '../ui/PasswordField.vue';

const auth = useAuthStore();

const profileForm = reactive({ name: '', email: '' });
const profileErrors = ref({});
const savingProfile = ref(false);

const passwordForm = reactive({ current_password: '', password: '', password_confirmation: '' });
const passwordErrors = ref({});
const savingPassword = ref(false);

watch(
    () => auth.user,
    (user) => {
        profileForm.name = user?.name ?? '';
        profileForm.email = user?.email ?? '';
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

    profileErrors.value = errors;

    return Object.keys(errors).length === 0;
}

async function saveProfile() {
    if (savingProfile.value || !validateProfile()) {
        return;
    }

    savingProfile.value = true;

    try {
        await auth.updateProfile({
            name: profileForm.name.trim(),
            email: profileForm.email.trim(),
        });
        profileErrors.value = {};
        notify.success('Profile updated.');
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
    <div class="settings">
        <div class="page-header">
            <h1>Settings</h1>
        </div>

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

                <AppField label="Email" for="settings-email" :error="profileErrors.email" required>
                    <input
                        id="settings-email"
                        v-model="profileForm.email"
                        class="input"
                        type="email"
                        autocomplete="email"
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
</template>

<style scoped>
.settings {
    display: flex;
    flex-direction: column;
    gap: 20px;
    max-width: 480px;
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
