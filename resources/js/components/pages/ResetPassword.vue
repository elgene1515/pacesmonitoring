<script setup>
import { computed, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import { useAuthStore } from '../../stores/auth';
import { APP_NAME } from '../../core/constants';
import AppField from '../ui/AppField.vue';
import PasswordField from '../ui/PasswordField.vue';

/**
 * Choose a new password from an emailed reset link (?token=&email=).
 */
const auth = useAuthStore();
const route = useRoute();

const token = computed(() => (typeof route.query.token === 'string' ? route.query.token : ''));
const email = computed(() => (typeof route.query.email === 'string' ? route.query.email : ''));
const linkIsValid = computed(() => token.value !== '' && email.value !== '');

const form = reactive({ password: '', password_confirmation: '' });
const fieldErrors = ref({});
const formError = ref('');
const submitting = ref(false);
const done = ref(false);

function validate() {
    const errors = {};

    if (!form.password) {
        errors.password = ['Password is required.'];
    } else if (form.password.length < 8) {
        errors.password = ['Password must be at least 8 characters.'];
    } else if (form.password !== form.password_confirmation) {
        errors.password_confirmation = ['Passwords do not match.'];
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
        await auth.resetPassword({
            token: token.value,
            email: email.value,
            password: form.password,
            password_confirmation: form.password_confirmation,
        });
        done.value = true;
    } catch (err) {
        if (err?.isValidation) {
            // Token / email problems come back under "email"; the user can't edit it here, so surface them as the form error.
            const { email: emailErrors, token: tokenErrors, ...rest } = err.errors;
            fieldErrors.value = rest;
            formError.value = emailErrors?.[0] ?? tokenErrors?.[0] ?? (Object.keys(rest).length ? '' : err.message);
        } else {
            formError.value = err?.message ?? 'Unable to reset your password.';
        }
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <div class="auth">
        <div class="card auth-card">
            <p class="app-name">{{ APP_NAME }}</p>
            <h1>Reset password</h1>

            <template v-if="done">
                <p class="form-success" role="status">Your password has been reset. You can sign in with it now.</p>
                <RouterLink :to="{ name: 'login' }" class="btn submit">Sign in</RouterLink>
            </template>

            <template v-else-if="!linkIsValid">
                <p class="form-error" role="alert">This reset link is incomplete. Please open the link from the email again, or request a new one.</p>
                <RouterLink :to="{ name: 'forgot-password' }" class="btn btn-secondary submit">Request a new link</RouterLink>
            </template>

            <form v-else class="form" novalidate @submit.prevent="submit">
                <p v-if="formError" class="form-error" role="alert">
                    {{ formError }}
                    <RouterLink :to="{ name: 'forgot-password' }">Request a new link</RouterLink>
                </p>

                <AppField label="Email" for="reset-email">
                    <input id="reset-email" class="input" type="email" :value="email" disabled />
                </AppField>

                <AppField
                    label="New password"
                    for="reset-password"
                    :error="fieldErrors.password"
                    hint="At least 8 characters."
                    required
                >
                    <PasswordField
                        id="reset-password"
                        v-model="form.password"
                        autocomplete="new-password"
                        :disabled="submitting"
                    />
                </AppField>

                <AppField
                    label="Confirm new password"
                    for="reset-password-confirmation"
                    :error="fieldErrors.password_confirmation"
                    required
                >
                    <PasswordField
                        id="reset-password-confirmation"
                        v-model="form.password_confirmation"
                        autocomplete="new-password"
                        :disabled="submitting"
                    />
                </AppField>

                <button type="submit" class="btn submit" :disabled="submitting">
                    {{ submitting ? 'Saving…' : 'Set new password' }}
                </button>
            </form>

            <p v-if="!done" class="switch muted">
                <RouterLink :to="{ name: 'login' }">Back to sign in</RouterLink>
            </p>
        </div>
    </div>
</template>

<style scoped>
.auth {
    min-height: calc(100vh - 48px);
    display: flex;
    align-items: center;
    justify-content: center;
}

.auth-card {
    width: 100%;
    max-width: 440px;
    padding: 36px 32px;
}

.app-name {
    margin: 0 0 4px;
    color: var(--muted);
    font-size: 0.9rem;
    letter-spacing: 0.02em;
}

h1 {
    margin-bottom: 20px;
}

.form {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.form-error,
.form-success {
    margin: 0;
    padding: 8px 10px;
    border-radius: var(--radius);
    font-size: 0.92rem;
    line-height: 1.5;
}

.form-error {
    border: 1px solid var(--danger);
    color: var(--danger);
    background: rgba(var(--danger-rgb), 0.06);
}

.form-error a {
    color: inherit;
    text-decoration: underline;
}

.form-success {
    border: 1px solid var(--success);
    color: var(--success);
    background: rgba(var(--success-rgb), 0.08);
}

.submit {
    display: block;
    width: 100%;
    margin-top: 6px;
    text-align: center;
}

.form-success + .submit,
.form-error + .submit {
    margin-top: 14px;
}

.switch {
    margin: 18px 0 0;
    text-align: center;
    font-size: 0.92rem;
}
</style>
