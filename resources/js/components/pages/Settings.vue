<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useAuthStore } from '../../stores/auth';
import { notify } from '../../stores/notifications';
import { formatDate } from '../../core/format';
import AppField from '../ui/AppField.vue';
import PasswordField from '../ui/PasswordField.vue';
import LegalDialog from '../legal/LegalDialog.vue';
import StatusBadge from '../ui/StatusBadge.vue';

/**
 * My account: the signed-in member's own details (as an administrator would
 * see them on the user page), then the forms to change name (in parts),
 * contact details, email and the password. Open to every role.
 */
const auth = useAuthStore();

const user = computed(() => auth.user);

const profileForm = reactive({ first_name: '', middle_name: '', last_name: '', suffix: '', phone: '', address: '', email: '', current_password: '' });
/** True when the name parts were guessed from an older account's single name: ask the person to check them. */
const namePartsGuessed = ref(false);
const profileErrors = ref({});
const savingProfile = ref(false);

/** The Terms of Service / Privacy Policy dialog. */
const legalOpen = ref(false);
const legalDocument = ref('terms');

function showLegal(document) {
    legalDocument.value = document;
    legalOpen.value = true;
}

const passwordForm = reactive({ current_password: '', password: '', password_confirmation: '' });
const passwordErrors = ref({});
const savingPassword = ref(false);
const resendingVerification = ref(false);

watch(
    () => auth.user,
    (current) => {
        const parts = current?.nameParts ?? { first: '', middle: '', last: '', suffix: '', guessed: false };

        profileForm.first_name = parts.first;
        profileForm.middle_name = parts.middle;
        profileForm.last_name = parts.last;
        profileForm.suffix = parts.suffix;
        namePartsGuessed.value = parts.guessed;
        profileForm.phone = current?.phone ?? '';
        profileForm.address = current?.address ?? '';
        profileForm.email = current?.email ?? '';
    },
    { immediate: true },
);

function validateProfile() {
    const errors = {};

    if (!profileForm.first_name.trim()) {
        errors.first_name = ['First name is required.'];
    }

    if (!profileForm.last_name.trim()) {
        errors.last_name = ['Surname is required.'];
    }

    if (!profileForm.phone.trim()) {
        errors.phone = ['Contact number is required.'];
    } else if (!/^\+?[0-9 ()\-.]{7,30}$/.test(profileForm.phone.trim())) {
        errors.phone = ['Enter a contact number using digits (spaces, dashes, brackets and a leading + are fine).'];
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
            first_name: profileForm.first_name.trim(),
            middle_name: profileForm.middle_name.trim() || null,
            last_name: profileForm.last_name.trim(),
            suffix: profileForm.suffix.trim() || null,
            phone: profileForm.phone.trim(),
            address: profileForm.address.trim() || null,
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
                    <dt>Contact number</dt>
                    <dd>{{ user.phone || '—' }}</dd>
                </div>
                <div class="info-item">
                    <dt>Address</dt>
                    <dd>{{ user.address || '—' }}</dd>
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
                <div v-if="user.termsAcceptedAt" class="info-item">
                    <dt>Terms and Privacy Policy accepted</dt>
                    <dd>
                        {{ formatDate(user.termsAcceptedAt) }}
                        <button type="button" class="legal-link" @click="showLegal('terms')">Terms</button>
                        ·
                        <button type="button" class="legal-link" @click="showLegal('privacy')">Privacy</button>
                    </dd>
                </div>
            </dl>
        </section>

        <div class="pair">
            <section class="card">
                <h2 class="card-title">Profile</h2>
                <form class="form" novalidate @submit.prevent="saveProfile">
                    <p v-if="namePartsGuessed" class="muted guessed">
                        We split your saved name into parts. Please check them, then save.
                    </p>

                    <div class="name-grid">
                        <AppField label="First name" for="settings-first-name" :error="profileErrors.first_name" required>
                            <input id="settings-first-name" v-model="profileForm.first_name" class="input" type="text" autocomplete="given-name" maxlength="100" :disabled="savingProfile" />
                        </AppField>
                        <AppField label="Middle name" for="settings-middle-name" :error="profileErrors.middle_name">
                            <input id="settings-middle-name" v-model="profileForm.middle_name" class="input" type="text" autocomplete="additional-name" maxlength="100" :disabled="savingProfile" />
                        </AppField>
                        <AppField label="Surname" for="settings-last-name" :error="profileErrors.last_name" required>
                            <input id="settings-last-name" v-model="profileForm.last_name" class="input" type="text" autocomplete="family-name" maxlength="100" :disabled="savingProfile" />
                        </AppField>
                        <AppField label="Suffix" for="settings-suffix" :error="profileErrors.suffix" hint="e.g. Jr., III">
                            <input id="settings-suffix" v-model="profileForm.suffix" class="input" type="text" autocomplete="honorific-suffix" maxlength="20" :disabled="savingProfile" />
                        </AppField>
                    </div>

                    <AppField label="Contact number" for="settings-phone" :error="profileErrors.phone" required>
                        <input id="settings-phone" v-model="profileForm.phone" class="input" type="tel" inputmode="tel" autocomplete="tel" maxlength="30" :disabled="savingProfile" />
                    </AppField>

                    <AppField label="Address" for="settings-address" :error="profileErrors.address" hint="Optional.">
                        <input id="settings-address" v-model="profileForm.address" class="input" type="text" autocomplete="street-address" maxlength="500" :disabled="savingProfile" />
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

        <LegalDialog v-model="legalOpen" v-model:document="legalDocument" />
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

.name-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px 12px;
}

.guessed {
    margin: 0;
    font-size: 0.88rem;
}

/* Looks like the other links but opens the dialog. */
.legal-link {
    padding: 0;
    border: none;
    background: none;
    color: var(--primary);
    font: inherit;
    font-size: 0.88rem;
    cursor: pointer;
}

.legal-link:hover {
    text-decoration: underline;
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
