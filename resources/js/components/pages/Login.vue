<script setup>
import { computed, onBeforeUnmount, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '../../stores/auth';
import { homeRouteFor, resolveRedirect } from '../../router';
import { APP_NAME } from '../../core/constants';
import AppField from '../ui/AppField.vue';

const auth = useAuthStore();
const router = useRouter();
const route = useRoute();

const form = reactive({ email: '', password: '' });
const fieldErrors = ref({});
const formError = ref('');
const submitting = ref(false);
const showPassword = ref(false);

const redirectTarget = computed(() => resolveRedirect(route.query.redirect, auth.user));

// Lockout countdown after too many failed attempts (server answers 429 + retry_after).
const lockoutSeconds = ref(0);
let lockoutUntil = 0;
let lockoutTimer = null;

const lockedOut = computed(() => lockoutSeconds.value > 0);
const lockoutMessage = computed(() => (
    `Too many login attempts. Please try again in ${lockoutSeconds.value} second${lockoutSeconds.value === 1 ? '' : 's'}.`
));

function tickLockout() {
    lockoutSeconds.value = Math.max(0, Math.ceil((lockoutUntil - Date.now()) / 1000));

    if (lockoutSeconds.value === 0) {
        clearLockout();
    }
}

function startLockout(seconds) {
    lockoutUntil = Date.now() + seconds * 1000;
    clearInterval(lockoutTimer);
    lockoutTimer = setInterval(tickLockout, 1000);
    tickLockout();
}

function clearLockout() {
    clearInterval(lockoutTimer);
    lockoutTimer = null;
    lockoutUntil = 0;
    lockoutSeconds.value = 0;
}

onBeforeUnmount(clearLockout);

function validate() {
    const errors = {};

    if (!form.email.trim()) {
        errors.email = ['Email is required.'];
    }

    if (!form.password) {
        errors.password = ['Password is required.'];
    }

    fieldErrors.value = errors;

    return Object.keys(errors).length === 0;
}

async function submit() {
    formError.value = '';

    if (lockedOut.value || !validate()) {
        return;
    }

    submitting.value = true;

    try {
        const user = await auth.login({ email: form.email.trim(), password: form.password });
        // A user who still owes an onboarding step is sent there regardless of ?redirect=.
        await router.replace(user?.needsSetup ? homeRouteFor(user) : redirectTarget.value);
    } catch (err) {
        if (err?.isRateLimited) {
            fieldErrors.value = {};
            startLockout(err.retryAfter ?? 60);
        } else if (err?.isValidation) {
            fieldErrors.value = err.errors;
            formError.value = err.hasFieldErrors ? '' : err.message;
        } else {
            formError.value = err?.message ?? 'Unable to sign in.';
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
            <h1>Sign in</h1>

            <form class="form" novalidate @submit.prevent="submit">
                <p v-if="lockedOut" class="form-error" role="alert" aria-live="polite">{{ lockoutMessage }}</p>
                <p v-else-if="formError" class="form-error" role="alert">{{ formError }}</p>

                <AppField label="Email" for="login-email" :error="fieldErrors.email" required>
                    <input
                        id="login-email"
                        v-model="form.email"
                        class="input"
                        type="email"
                        autocomplete="email"
                        autofocus
                        :disabled="submitting"
                    />
                </AppField>

                <AppField label="Password" for="login-password" :error="fieldErrors.password" required>
                    <div class="password-field">
                        <input
                            id="login-password"
                            v-model="form.password"
                            class="input"
                            :type="showPassword ? 'text' : 'password'"
                            autocomplete="current-password"
                            :disabled="submitting"
                        />
                        <button
                            type="button"
                            class="toggle-password"
                            :aria-label="showPassword ? 'Hide password' : 'Show password'"
                            :aria-pressed="showPassword"
                            tabindex="-1"
                            @click="showPassword = !showPassword"
                        >
                            <svg v-if="showPassword" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a21.3 21.3 0 0 1 5.06-6.06M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 8 11 8a21.3 21.3 0 0 1-3.22 4.44M14.12 14.12a3 3 0 1 1-4.24-4.24" />
                                <line x1="1" y1="1" x2="23" y2="23" />
                            </svg>
                            <svg v-else viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z" />
                                <circle cx="12" cy="12" r="3" />
                            </svg>
                        </button>
                    </div>
                </AppField>

                <p class="forgot">
                    <RouterLink :to="{ name: 'forgot-password' }">Forgot password?</RouterLink>
                </p>

                <button type="submit" class="btn submit" :disabled="submitting || lockedOut">
                    <template v-if="submitting">Signing in…</template>
                    <template v-else-if="lockedOut">Try again in {{ lockoutSeconds }}s</template>
                    <template v-else>Sign in</template>
                </button>
            </form>

            <p class="switch muted">
                No account yet?
                <RouterLink :to="{ name: 'register', query: route.query }">Create one</RouterLink>
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

.form-error {
    margin: 0;
    padding: 8px 10px;
    border: 1px solid var(--danger);
    border-radius: var(--radius);
    color: var(--danger);
    background: rgba(var(--danger-rgb), 0.06);
    font-size: 0.92rem;
}

.forgot {
    margin: -6px 0 0;
    text-align: right;
    font-size: 0.88rem;
}

.password-field {
    position: relative;
    display: flex;
}

.password-field .input {
    width: 100%;
    padding-right: 38px;
}

.toggle-password {
    position: absolute;
    top: 50%;
    right: 8px;
    transform: translateY(-50%);
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    padding: 0;
    border: none;
    background: transparent;
    color: var(--muted);
    cursor: pointer;
}

.toggle-password:hover {
    color: var(--text);
}

.submit {
    width: 100%;
    margin-top: 6px;
}

.switch {
    margin: 18px 0 0;
    text-align: center;
    font-size: 0.92rem;
}
</style>
