<script setup>
import { reactive, ref } from 'vue';
import { useAuthStore } from '../../stores/auth';
import { APP_NAME } from '../../core/constants';
import AppField from '../ui/AppField.vue';

/**
 * Ask for a password reset link. The server answers the same way whether or
 * not the address is registered, so the page only ever confirms "sent".
 */
const auth = useAuthStore();

const form = reactive({ email: '' });
const fieldErrors = ref({});
const formError = ref('');
const submitting = ref(false);
const sent = ref(false);

function validate() {
    const errors = {};

    if (!form.email.trim()) {
        errors.email = ['Email is required.'];
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
        await auth.forgotPassword(form.email.trim());
        sent.value = true;
    } catch (err) {
        if (err?.isValidation) {
            fieldErrors.value = err.errors;
            formError.value = err.hasFieldErrors ? '' : err.message;
        } else {
            formError.value = err?.message ?? 'Unable to send the reset link.';
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
            <h1>Forgot password</h1>

            <template v-if="sent">
                <p class="form-success" role="status">
                    If an account exists for <strong>{{ form.email.trim() }}</strong>, a password reset link has been sent to it.
                    The link is valid for 60 minutes.
                </p>
                <p class="muted help">Didn't get it? Check your spam folder, or try again in a minute.</p>
                <button type="button" class="btn btn-secondary submit" @click="sent = false">Send another link</button>
            </template>

            <form v-else class="form" novalidate @submit.prevent="submit">
                <p class="muted intro">Enter the email of your account and we'll send you a link to choose a new password.</p>

                <p v-if="formError" class="form-error" role="alert">{{ formError }}</p>

                <AppField label="Email" for="forgot-email" :error="fieldErrors.email" required>
                    <input
                        id="forgot-email"
                        v-model="form.email"
                        class="input"
                        type="email"
                        autocomplete="email"
                        autofocus
                        :disabled="submitting"
                    />
                </AppField>

                <button type="submit" class="btn submit" :disabled="submitting">
                    {{ submitting ? 'Sending…' : 'Send reset link' }}
                </button>
            </form>

            <p class="switch muted">
                Remembered it?
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

.intro {
    margin: 0;
    font-size: 0.92rem;
}

.form-error,
.form-success {
    margin: 0;
    padding: 8px 10px;
    border-radius: var(--radius);
    font-size: 0.92rem;
}

.form-error {
    border: 1px solid var(--danger);
    color: var(--danger);
    background: rgba(var(--danger-rgb), 0.06);
}

.form-success {
    border: 1px solid var(--success);
    color: var(--success);
    background: rgba(var(--success-rgb), 0.08);
    line-height: 1.5;
}

.help {
    margin: 12px 0 0;
    font-size: 0.9rem;
}

.submit {
    width: 100%;
    margin-top: 6px;
}

.form-success + .help + .submit {
    margin-top: 14px;
}

.switch {
    margin: 18px 0 0;
    text-align: center;
    font-size: 0.92rem;
}
</style>
