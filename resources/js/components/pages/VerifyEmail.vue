<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { useAuthStore } from '../../stores/auth';
import { homeRouteFor } from '../../router';
import { APP_NAME } from '../../core/constants';

/**
 * Landing page of the verification email (?id=&hash=&expires=&signature=).
 * The parameters are replayed against the API as soon as the page opens.
 */
const auth = useAuthStore();
const route = useRoute();

const params = computed(() => {
    const pick = (key) => (typeof route.query[key] === 'string' ? route.query[key] : '');
    return { id: pick('id'), hash: pick('hash'), expires: pick('expires'), signature: pick('signature') };
});
const linkIsComplete = computed(() => Object.values(params.value).every((value) => value !== ''));

const state = ref('checking'); // checking | verified | failed
const message = ref('');
const resending = ref(false);
const resent = ref('');

const continueTo = computed(() => (auth.isAuthenticated ? homeRouteFor(auth.user) : { name: 'login' }));
const canResend = computed(() => auth.isAuthenticated && !auth.user?.emailVerified);

onMounted(async () => {
    if (!linkIsComplete.value) {
        state.value = 'failed';
        message.value = 'This verification link is incomplete. Please open the link from the email again.';
        return;
    }

    try {
        message.value = await auth.verifyEmail(params.value);
        state.value = 'verified';
    } catch (err) {
        state.value = 'failed';
        message.value = err?.isForbidden
            ? 'This verification link is invalid or has expired.'
            : err?.message ?? 'Unable to verify your email address.';
    }
});

async function resend() {
    resending.value = true;
    resent.value = '';

    try {
        resent.value = await auth.resendVerification();
    } catch (err) {
        resent.value = err?.message ?? 'Unable to send a new link right now.';
    } finally {
        resending.value = false;
    }
}
</script>

<template>
    <div class="auth">
        <div class="card auth-card">
            <p class="app-name">{{ APP_NAME }}</p>
            <h1>Verify email</h1>

            <p v-if="state === 'checking'" class="muted" role="status">Checking your verification link…</p>

            <template v-else-if="state === 'verified'">
                <p class="form-success" role="status">{{ message }}</p>
                <RouterLink :to="continueTo" class="btn submit">{{ auth.isAuthenticated ? 'Continue' : 'Sign in' }}</RouterLink>
            </template>

            <template v-else>
                <p class="form-error" role="alert">{{ message }}</p>

                <template v-if="canResend">
                    <p v-if="resent" class="muted help" role="status">{{ resent }}</p>
                    <button type="button" class="btn btn-secondary submit" :disabled="resending" @click="resend">
                        {{ resending ? 'Sending…' : 'Send a new link' }}
                    </button>
                </template>
                <p v-else-if="!auth.isAuthenticated" class="muted help">
                    Sign in and use <strong>Resend link</strong> on your My account page to get a new link.
                </p>

                <RouterLink :to="continueTo" class="btn submit" :class="{ 'btn-secondary': canResend }">
                    {{ auth.isAuthenticated ? 'Continue' : 'Sign in' }}
                </RouterLink>
            </template>
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

.form-success {
    border: 1px solid var(--success);
    color: var(--success);
    background: rgba(var(--success-rgb), 0.08);
}

.help {
    margin: 12px 0 0;
    font-size: 0.9rem;
}

.submit {
    display: block;
    width: 100%;
    margin-top: 14px;
    text-align: center;
}

.submit + .submit {
    margin-top: 10px;
}
</style>
