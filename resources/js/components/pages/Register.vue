<script setup>
import { computed, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '../../stores/auth';
import { homeRouteFor } from '../../router';
import { APP_NAME } from '../../core/constants';
import { ROLES } from '../../models';
import AppField from '../ui/AppField.vue';
import PasswordField from '../ui/PasswordField.vue';

/**
 * Sign-up: pick a role, then enter personal details. The next step depends on
 * the role: an administrator registers their school, a teacher asks to join one.
 */
const auth = useAuthStore();
const router = useRouter();
const route = useRoute();

const ROLE_OPTIONS = [
    {
        value: ROLES.ADMIN,
        title: 'School administrator',
        description: 'Register your school and its branches, admit teachers, manage school years and see every teacher\'s records.',
        next: 'Next: register your school.',
    },
    {
        value: ROLES.TEACHER,
        title: 'Teacher',
        description: 'Keep track of your students, PACEs and PACE records inside your school.',
        next: 'Next: enter your school\'s code and ask to join.',
    },
];

const form = reactive({
    role: '',
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});
const fieldErrors = ref({});
const formError = ref('');
const submitting = ref(false);

const selectedRole = computed(() => ROLE_OPTIONS.find((option) => option.value === form.role) ?? null);

function validate() {
    const errors = {};

    if (!form.role) {
        errors.role = ['Please choose how you will use the system.'];
    }

    if (!form.name.trim()) {
        errors.name = ['Name is required.'];
    }

    if (!form.email.trim()) {
        errors.email = ['Email is required.'];
    }

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
        const user = await auth.register({
            role: form.role,
            name: form.name.trim(),
            email: form.email.trim(),
            password: form.password,
            password_confirmation: form.password_confirmation,
        });
        await router.replace(homeRouteFor(user));
    } catch (err) {
        if (err?.isValidation) {
            fieldErrors.value = err.errors;
            formError.value = err.hasFieldErrors ? '' : err.message;
        } else {
            formError.value = err?.message ?? 'Unable to create your account.';
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
            <h1>Create account</h1>

            <form class="form" novalidate @submit.prevent="submit">
                <p v-if="formError" class="form-error" role="alert">{{ formError }}</p>

                <AppField label="I am registering as" :error="fieldErrors.role" required>
                    <div class="roles" role="radiogroup" aria-label="Account type">
                        <label
                            v-for="option in ROLE_OPTIONS"
                            :key="option.value"
                            class="role"
                            :class="{ selected: form.role === option.value }"
                        >
                            <input
                                v-model="form.role"
                                type="radio"
                                name="role"
                                :value="option.value"
                                :disabled="submitting"
                            />
                            <span class="role-body">
                                <span class="role-title">{{ option.title }}</span>
                                <span class="role-description muted">{{ option.description }}</span>
                            </span>
                        </label>
                    </div>
                </AppField>

                <AppField label="Full name" for="register-name" :error="fieldErrors.name" required>
                    <input
                        id="register-name"
                        v-model="form.name"
                        class="input"
                        type="text"
                        autocomplete="name"
                        :disabled="submitting"
                    />
                </AppField>

                <AppField label="Email" for="register-email" :error="fieldErrors.email" required>
                    <input
                        id="register-email"
                        v-model="form.email"
                        class="input"
                        type="email"
                        autocomplete="email"
                        :disabled="submitting"
                    />
                </AppField>

                <AppField
                    label="Password"
                    for="register-password"
                    :error="fieldErrors.password"
                    hint="At least 8 characters."
                    required
                >
                    <PasswordField
                        id="register-password"
                        v-model="form.password"
                        autocomplete="new-password"
                        :disabled="submitting"
                    />
                </AppField>

                <AppField
                    label="Confirm password"
                    for="register-password-confirmation"
                    :error="fieldErrors.password_confirmation"
                    required
                >
                    <PasswordField
                        id="register-password-confirmation"
                        v-model="form.password_confirmation"
                        autocomplete="new-password"
                        :disabled="submitting"
                    />
                </AppField>

                <p v-if="selectedRole" class="muted next-step">{{ selectedRole.next }}</p>

                <button type="submit" class="btn submit" :disabled="submitting">
                    {{ submitting ? 'Creating account…' : 'Create account' }}
                </button>
            </form>

            <p class="switch muted">
                Already have an account?
                <RouterLink :to="{ name: 'login', query: route.query }">Sign in</RouterLink>
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
    max-width: 480px;
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

.roles {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
}

.role {
    display: flex;
    gap: 10px;
    padding: 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    cursor: pointer;
    transition: border-color 0.12s ease, background-color 0.12s ease;
}

.role:hover {
    border-color: var(--border-strong);
}

.role.selected {
    border-color: var(--primary);
    background: rgba(var(--primary-rgb), 0.05);
}

.role input {
    margin-top: 3px;
    flex-shrink: 0;
}

.role-body {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 0;
}

.role-title {
    font-weight: 600;
}

.role-description {
    font-size: 0.85rem;
    line-height: 1.4;
}

.next-step {
    margin: 0;
    font-size: 0.9rem;
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

@media (max-width: 480px) {
    .roles {
        grid-template-columns: 1fr;
    }
}
</style>
