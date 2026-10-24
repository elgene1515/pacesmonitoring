<script setup>
import { computed, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '../../stores/auth';
import { homeRouteFor } from '../../router';
import { APP_NAME } from '../../core/constants';
import { ROLES } from '../../models';
import AppField from '../ui/AppField.vue';
import LegalDialog from '../legal/LegalDialog.vue';
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
        description: 'Register your school and its branches, admit teachers, manage school years and see every teacher\'s records. Or join a school that is already registered, with its code.',
        next: 'Next: register your school, or enter the code of a registered one.',
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
    first_name: '',
    middle_name: '',
    last_name: '',
    suffix: '',
    phone: '',
    address: '',
    email: '',
    password: '',
    password_confirmation: '',
    terms: false,
});
/** The Terms of Service / Privacy Policy dialog: which document is open (null = closed). */
const legalOpen = ref(false);
const legalDocument = ref('terms');

function showLegal(document) {
    legalDocument.value = document;
    legalOpen.value = true;
}

const fieldErrors = ref({});
const formError = ref('');
const submitting = ref(false);

const selectedRole = computed(() => ROLE_OPTIONS.find((option) => option.value === form.role) ?? null);

function validate() {
    const errors = {};

    if (!form.role) {
        errors.role = ['Please choose how you will use the system.'];
    }

    if (!form.first_name.trim()) {
        errors.first_name = ['First name is required.'];
    }

    if (!form.last_name.trim()) {
        errors.last_name = ['Surname is required.'];
    }

    if (!form.phone.trim()) {
        errors.phone = ['Contact number is required.'];
    } else if (!/^\+?[0-9 ()\-.]{7,30}$/.test(form.phone.trim())) {
        errors.phone = ['Enter a contact number using digits (spaces, dashes, brackets and a leading + are fine).'];
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

    if (!form.terms) {
        errors.terms = ['Please accept the Terms of Service and Privacy Policy to create an account.'];
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
            first_name: form.first_name.trim(),
            middle_name: form.middle_name.trim() || null,
            last_name: form.last_name.trim(),
            suffix: form.suffix.trim() || null,
            phone: form.phone.trim(),
            address: form.address.trim() || null,
            email: form.email.trim(),
            password: form.password,
            password_confirmation: form.password_confirmation,
            terms: form.terms,
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

                <div class="pair">
                    <AppField label="First name" for="register-first-name" :error="fieldErrors.first_name" required>
                        <input
                            id="register-first-name"
                            v-model="form.first_name"
                            class="input"
                            type="text"
                            autocomplete="given-name"
                            maxlength="100"
                            :disabled="submitting"
                        />
                    </AppField>

                    <AppField label="Middle name" for="register-middle-name" :error="fieldErrors.middle_name">
                        <input
                            id="register-middle-name"
                            v-model="form.middle_name"
                            class="input"
                            type="text"
                            autocomplete="additional-name"
                            maxlength="100"
                            :disabled="submitting"
                        />
                    </AppField>

                    <AppField label="Surname" for="register-last-name" :error="fieldErrors.last_name" required>
                        <input
                            id="register-last-name"
                            v-model="form.last_name"
                            class="input"
                            type="text"
                            autocomplete="family-name"
                            maxlength="100"
                            :disabled="submitting"
                        />
                    </AppField>

                    <AppField label="Suffix" for="register-suffix" :error="fieldErrors.suffix" hint="e.g. Jr., III">
                        <input
                            id="register-suffix"
                            v-model="form.suffix"
                            class="input"
                            type="text"
                            autocomplete="honorific-suffix"
                            maxlength="20"
                            :disabled="submitting"
                        />
                    </AppField>
                </div>

                <AppField label="Contact number" for="register-phone" :error="fieldErrors.phone" required>
                    <input
                        id="register-phone"
                        v-model="form.phone"
                        class="input"
                        type="tel"
                        inputmode="tel"
                        autocomplete="tel"
                        maxlength="30"
                        placeholder="e.g. 0917 123 4567"
                        :disabled="submitting"
                    />
                </AppField>

                <AppField label="Address" for="register-address" :error="fieldErrors.address" hint="Optional.">
                    <input
                        id="register-address"
                        v-model="form.address"
                        class="input"
                        type="text"
                        autocomplete="street-address"
                        maxlength="500"
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

                <AppField :error="fieldErrors.terms">
                    <label class="checkbox terms">
                        <input id="register-terms" v-model="form.terms" type="checkbox" :disabled="submitting" />
                        <span>
                            I have read and agree to the
                            <button type="button" class="text-link" @click.prevent="showLegal('terms')">Terms of Service</button>
                            and the
                            <button type="button" class="text-link" @click.prevent="showLegal('privacy')">Privacy Policy</button>.
                        </span>
                    </label>
                </AppField>

                <p v-if="selectedRole" class="muted next-step">{{ selectedRole.next }}</p>

                <button type="submit" class="btn submit" :disabled="submitting">
                    {{ submitting ? 'Creating account…' : 'Create account' }}
                </button>
            </form>

            <LegalDialog v-model="legalOpen" v-model:document="legalDocument" />

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

/* First / middle name and surname / suffix side by side. */
.pair {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px 12px;
}

/* The document names in the terms line look like links but open the dialog. */
.text-link {
    display: inline;
    padding: 0;
    border: none;
    background: none;
    color: var(--primary);
    font: inherit;
    cursor: pointer;
}

.text-link:hover {
    text-decoration: underline;
}

.text-link:focus-visible {
    outline: 2px solid var(--primary);
    outline-offset: 2px;
    border-radius: 2px;
}

.terms {
    align-items: flex-start;
    font-size: 0.92rem;
    line-height: 1.5;
}

.terms input {
    margin-top: 3px;
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
