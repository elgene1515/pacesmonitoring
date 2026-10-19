<script setup>
import { computed, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../../../stores/auth';
import { notify } from '../../../stores/notifications';
import { tenantService } from '../../../services';
import { homeRouteFor } from '../../../router';
import { formatDate } from '../../../core/format';
import AppField from '../../ui/AppField.vue';

/**
 * Second sign-up step for a teacher: look the school up by its code, pick a
 * branch and ask to join. While the request is pending this page shows its
 * status; once an admin accepts it the teacher is sent to their Home page.
 */
const auth = useAuthStore();
const router = useRouter();

const form = reactive({ code: '', branch_id: '', message: '' });
const organization = ref(null);
const lookingUp = ref(false);
const submitting = ref(false);
const cancelling = ref(false);
const refreshing = ref(false);
const fieldErrors = ref({});
const formError = ref('');

const request = computed(() => auth.user?.joinRequest ?? null);
const pending = computed(() => Boolean(request.value?.isPending));
const declined = computed(() => Boolean(request.value?.isDeclined));

async function lookup() {
    fieldErrors.value = {};
    formError.value = '';
    organization.value = null;
    form.branch_id = '';

    if (!form.code.trim()) {
        fieldErrors.value = { code: ['Enter the school code your administrator gave you.'] };
        return;
    }

    lookingUp.value = true;

    try {
        organization.value = await tenantService.lookupOrganization(form.code.trim());
        form.code = organization.value.code;

        if (organization.value.branches.length === 1) {
            form.branch_id = organization.value.branches[0].id;
        }
    } catch (err) {
        if (err?.isValidation) {
            fieldErrors.value = err.errors;
            formError.value = err.hasFieldErrors ? '' : err.message;
        } else {
            formError.value = err?.message ?? 'Unable to look the school up.';
        }
    } finally {
        lookingUp.value = false;
    }
}

async function submit() {
    formError.value = '';

    if (!organization.value) {
        await lookup();

        if (!organization.value) {
            return;
        }
    }

    if (!form.branch_id) {
        fieldErrors.value = { branch_id: ['Pick the branch you teach at.'] };
        return;
    }

    submitting.value = true;

    try {
        await auth.submitJoinRequest({
            organization_code: organization.value.code,
            branch_id: Number(form.branch_id),
            message: form.message.trim() || null,
        });
        notify.success('Your request was sent. An administrator of the school will review it.');
    } catch (err) {
        if (err?.isValidation) {
            fieldErrors.value = err.errors;
            formError.value = err.hasFieldErrors ? '' : err.message;
        } else {
            formError.value = err?.message ?? 'Unable to send your request.';
        }
    } finally {
        submitting.value = false;
    }
}

async function cancel() {
    cancelling.value = true;

    try {
        await auth.cancelJoinRequest();
        notify.info('Your request was withdrawn.');
    } catch (err) {
        notify.error(err?.message ?? 'Unable to withdraw the request.');
    } finally {
        cancelling.value = false;
    }
}

/** Re-check whether the request has been decided; move on when it was accepted. */
async function refresh() {
    refreshing.value = true;

    try {
        const user = await auth.refresh();

        if (user && !user.needsSetup) {
            notify.success(`Welcome to ${user.organizationName}!`);
            await router.replace(homeRouteFor(user));
        } else if (user?.joinRequest?.isPending) {
            notify.info('Still waiting for an administrator to review your request.');
        }
    } catch (err) {
        notify.error(err?.message ?? 'Unable to check the request.');
    } finally {
        refreshing.value = false;
    }
}

function startOver() {
    form.code = request.value?.organization?.code ?? '';
    form.branch_id = '';
    form.message = '';
    organization.value = null;
    fieldErrors.value = {};
    formError.value = '';
}
</script>

<template>
    <div class="setup">
        <div class="card setup-card">
            <p class="muted step">Step 2 of 2</p>

            <!-- Waiting for the school's administrator. -->
            <template v-if="pending">
                <h1>Request sent</h1>
                <p class="muted intro">
                    Hi {{ auth.user?.firstName }}. Your request to join
                    <strong>{{ request.organizationName }}</strong>
                    <template v-if="request.branchName"> ({{ request.branchName }})</template>
                    is waiting for an administrator to accept it. You will get access to your Home page as soon as they do.
                </p>
                <dl class="info">
                    <div class="info-item">
                        <dt>School code</dt>
                        <dd class="mono">{{ request.organization?.code }}</dd>
                    </div>
                    <div class="info-item">
                        <dt>Branch</dt>
                        <dd>{{ request.branchName || '—' }}</dd>
                    </div>
                    <div class="info-item">
                        <dt>Sent</dt>
                        <dd>{{ formatDate(request.createdAt) || '—' }}</dd>
                    </div>
                    <div v-if="request.message" class="info-item wide">
                        <dt>Your message</dt>
                        <dd>{{ request.message }}</dd>
                    </div>
                </dl>
                <div class="actions">
                    <button type="button" class="btn" :disabled="refreshing" @click="refresh">
                        {{ refreshing ? 'Checking…' : 'Check again' }}
                    </button>
                    <button type="button" class="btn btn-secondary" :disabled="cancelling" @click="cancel">
                        {{ cancelling ? 'Withdrawing…' : 'Withdraw request' }}
                    </button>
                </div>
            </template>

            <!-- Enter the code, pick a branch, send. -->
            <template v-else>
                <h1>Join your school</h1>
                <p class="muted intro">
                    Hi {{ auth.user?.firstName }}. Enter the school code your administrator gave you, pick your branch and send a
                    request to join. Your records stay private to you; the administrator only sees them from the school's dashboard.
                </p>

                <p v-if="declined" class="declined" role="alert">
                    Your previous request to join <strong>{{ request.organizationName }}</strong> was declined
                    <template v-if="request.declineReason">: “{{ request.declineReason }}”</template>.
                    Check with the school's administrator, then send a new request.
                </p>

                <form class="form" novalidate @submit.prevent="submit">
                    <p v-if="formError" class="form-error" role="alert">{{ formError }}</p>

                    <AppField label="School code" for="join-code" :error="fieldErrors.code || fieldErrors.organization_code" hint="Looks like SCH-7F3K2A." required>
                        <div class="code-row">
                            <input
                                id="join-code"
                                v-model="form.code"
                                class="input mono"
                                type="text"
                                maxlength="16"
                                placeholder="SCH-XXXXXX"
                                autocomplete="off"
                                autofocus
                                :disabled="lookingUp || submitting"
                                @keydown.enter.prevent="lookup"
                            />
                            <button type="button" class="btn btn-secondary" :disabled="lookingUp || submitting" @click="lookup">
                                {{ lookingUp ? 'Looking up…' : 'Look up' }}
                            </button>
                        </div>
                    </AppField>

                    <div v-if="organization" class="found">
                        <p class="found-name">{{ organization.name }}</p>
                        <p class="muted found-code">Code {{ organization.code }}</p>
                    </div>

                    <AppField label="Branch" for="join-branch" :error="fieldErrors.branch_id" required>
                        <select id="join-branch" v-model="form.branch_id" class="input" :disabled="!organization || submitting">
                            <option value="">{{ organization ? 'Select your branch' : 'Look the school up first' }}</option>
                            <option v-for="branch in organization?.branches ?? []" :key="branch.id" :value="branch.id">
                                {{ branch.name }} ({{ branch.code }}){{ branch.isMain ? ' · main' : '' }}
                            </option>
                        </select>
                    </AppField>

                    <AppField label="Message to the administrator" for="join-message" :error="fieldErrors.message" hint="Optional, e.g. the grade level you teach.">
                        <input id="join-message" v-model="form.message" class="input" type="text" maxlength="255" :disabled="submitting" />
                    </AppField>

                    <div class="actions">
                        <button type="submit" class="btn" :disabled="submitting || lookingUp">
                            {{ submitting ? 'Sending…' : 'Send request' }}
                        </button>
                        <button v-if="declined" type="button" class="btn btn-link" :disabled="submitting" @click="startOver">Reset</button>
                    </div>
                </form>
            </template>
        </div>
    </div>
</template>

<style scoped>
.setup {
    display: flex;
    justify-content: center;
    padding: 16px 0;
}

.setup-card {
    width: 100%;
    max-width: 560px;
    padding: 32px;
}

.step {
    margin: 0 0 4px;
    font-size: 0.85rem;
    letter-spacing: 0.02em;
    text-transform: uppercase;
}

.intro {
    margin: 6px 0 20px;
}

.form {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.form-error,
.declined {
    margin: 0 0 14px;
    padding: 8px 10px;
    border: 1px solid var(--danger);
    border-radius: var(--radius);
    color: var(--danger);
    background: rgba(var(--danger-rgb), 0.06);
    font-size: 0.92rem;
}

.form-error {
    margin-bottom: 0;
}

.code-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 8px;
}

.found {
    padding: 10px 12px;
    border: 1px solid rgba(var(--success-rgb), 0.35);
    border-radius: var(--radius);
    background: rgba(var(--success-rgb), 0.06);
}

.found-name {
    margin: 0;
    font-weight: 600;
}

.found-code {
    margin: 2px 0 0;
    font-size: 0.85rem;
}

.info {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 14px 24px;
    margin: 0 0 20px;
}

.info-item.wide {
    grid-column: 1 / -1;
}

.info-item dt {
    font-size: 0.85rem;
    color: var(--muted);
    margin-bottom: 2px;
}

.info-item dd {
    margin: 0;
}

.actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 6px;
}
</style>
