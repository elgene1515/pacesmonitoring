<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { usePacesStore } from '../../stores/paces';
import { usePaceRequestsStore } from '../../stores/paceRequests';
import { notify } from '../../stores/notifications';
import AppModal from '../ui/AppModal.vue';
import AppField from '../ui/AppField.vue';

/**
 * Ask the school admin for PACE booklets, or edit a pending request.
 *
 *  props   modelValue  Boolean           open state (v-model)
 *          pace        Pace|null         preselects one of the teacher's PACEs (new requests)
 *          request     PaceRequest|null  pending request to edit; null files a new one
 *  emits   update:modelValue(Boolean)
 *          saved(PaceRequest)
 */
const props = defineProps({
    modelValue: { type: Boolean, default: false },
    pace: { type: Object, default: null },
    request: { type: Object, default: null },
});

const emit = defineEmits(['update:modelValue', 'saved']);

const paces = usePacesStore();
const requests = usePaceRequestsStore();

const uid = `pace-request-${Math.random().toString(36).slice(2, 8)}`;
const form = reactive({ pace_id: '', pace_number: '', pace_name: '', quantity: 1, needed_by: '', message: '' });
const clientErrors = ref({});
const serverErrors = ref({});
const submitting = ref(false);

const errors = computed(() => ({ ...clientErrors.value, ...serverErrors.value }));
const isEdit = computed(() => Boolean(props.request?.id));
const title = computed(() => (isEdit.value ? 'Edit stock request' : 'Request PACE stock'));

/** The registered PACE picked in the select, when any. */
const linkedPace = computed(() => (form.pace_id === '' || form.pace_id === null ? null : paces.byId(form.pace_id) ?? null));

watch(linkedPace, (pace) => {
    if (pace) {
        form.pace_number = pace.number;
        form.pace_name = pace.name;
    }
});

watch(
    () => props.modelValue,
    (open) => {
        if (!open) {
            return;
        }

        clientErrors.value = {};
        serverErrors.value = {};

        if (!paces.loaded && !paces.loading) {
            paces.fetch();
        }

        const source = props.request;
        form.pace_id = source ? (source.paceId ?? '') : (props.pace?.id ?? '');
        form.pace_number = source?.paceNumber ?? props.pace?.number ?? '';
        form.pace_name = source?.paceName ?? props.pace?.name ?? '';
        form.quantity = source?.quantity ?? 1;
        form.needed_by = source?.neededBy ?? '';
        form.message = source?.message ?? '';
    },
);

function close() {
    if (submitting.value) {
        return;
    }

    emit('update:modelValue', false);
}

function validate() {
    const found = {};
    const quantity = Number(form.quantity);

    if (!Number.isInteger(quantity) || quantity < 1) {
        found.quantity = ['Enter how many booklets you need (at least 1).'];
    }

    if (!linkedPace.value) {
        const number = String(form.pace_number).trim();

        if (!number) {
            found.pace_number = ['Pick one of your PACEs or enter the PACE number.'];
        } else if (!/^[0-9]+$/.test(number)) {
            found.pace_number = ['The PACE number must contain digits only.'];
        }

        if (!String(form.pace_name).trim()) {
            found.pace_name = ['Pick one of your PACEs or enter the PACE name.'];
        }
    }

    clientErrors.value = found;

    return Object.keys(found).length === 0;
}

async function submit() {
    if (submitting.value || !validate()) {
        return;
    }

    submitting.value = true;
    serverErrors.value = {};

    const payload = {
        pace_id: linkedPace.value ? linkedPace.value.id : null,
        pace_number: String(form.pace_number).trim(),
        pace_name: String(form.pace_name).trim(),
        quantity: Number(form.quantity),
        needed_by: form.needed_by || null,
        message: String(form.message).trim() || null,
    };

    try {
        const saved = isEdit.value
            ? await requests.update(props.request.id, payload)
            : await requests.create(payload);

        notify.success(isEdit.value
            ? 'Request updated.'
            : `Request for ${saved.quantity} × PACE ${saved.label} sent to your school admin.`);
        emit('saved', saved);
        emit('update:modelValue', false);
    } catch (err) {
        if (err?.isValidation && err.hasFieldErrors) {
            serverErrors.value = err.errors;

            if (err.errors.request) {
                notify.error(err.first('request'));
            }
        } else {
            notify.error(err?.message ?? 'Unable to send the request.');
        }
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <AppModal
        :model-value="modelValue"
        :title="title"
        width="500px"
        :closable="!submitting"
        :close-on-backdrop="false"
        @update:model-value="close"
    >
        <form v-if="modelValue" class="request-form" novalidate @submit.prevent="submit">
            <AppField
                label="PACE"
                :for="`${uid}-pace`"
                :error="errors.pace_id"
                hint="Pick one of your registered PACEs, or choose “Other” and type the number and name."
            >
                <select :id="`${uid}-pace`" v-model="form.pace_id" class="input" :disabled="submitting">
                    <option value="">Other / not registered yet</option>
                    <option v-for="pace in paces.items" :key="pace.id" :value="pace.id">
                        {{ pace.label }} · {{ pace.stock }} in stock
                    </option>
                </select>
            </AppField>

            <div class="grid-2">
                <AppField label="PACE number" :for="`${uid}-number`" :error="errors.pace_number" required>
                    <input
                        :id="`${uid}-number`"
                        v-model="form.pace_number"
                        class="input"
                        type="text"
                        inputmode="numeric"
                        maxlength="50"
                        autocomplete="off"
                        :disabled="submitting || Boolean(linkedPace)"
                    />
                </AppField>
                <AppField label="PACE name" :for="`${uid}-name`" :error="errors.pace_name" required>
                    <input
                        :id="`${uid}-name`"
                        v-model="form.pace_name"
                        class="input"
                        type="text"
                        autocomplete="off"
                        :disabled="submitting || Boolean(linkedPace)"
                    />
                </AppField>
            </div>

            <div class="grid-2">
                <AppField label="Quantity" :for="`${uid}-quantity`" :error="errors.quantity" hint="Booklets you need." required>
                    <input
                        :id="`${uid}-quantity`"
                        v-model="form.quantity"
                        class="input"
                        type="number"
                        min="1"
                        step="1"
                        inputmode="numeric"
                        :disabled="submitting"
                    />
                </AppField>
                <AppField label="Needed by" :for="`${uid}-needed`" :error="errors.needed_by" hint="Optional.">
                    <input :id="`${uid}-needed`" v-model="form.needed_by" class="input" type="date" :disabled="submitting" />
                </AppField>
            </div>

            <AppField label="Note to your admin" :for="`${uid}-message`" :error="errors.message" hint="Optional.">
                <input :id="`${uid}-message`" v-model="form.message" class="input" type="text" maxlength="255" :disabled="submitting" />
            </AppField>

            <p v-if="linkedPace" class="muted note">
                When your admin marks this request as given, the booklets are added to the stock of
                PACE {{ linkedPace.label }} (now {{ linkedPace.stock }}).
            </p>
            <p v-else class="muted note">
                Not linked to a registered PACE: once the booklets arrive, register the PACE (or set its stock) yourself.
            </p>

            <div class="actions">
                <button type="button" class="btn btn-secondary" :disabled="submitting" @click="close">Cancel</button>
                <button type="submit" class="btn" :disabled="submitting">
                    {{ submitting ? 'Saving…' : (isEdit ? 'Save changes' : 'Send request') }}
                </button>
            </div>
        </form>
    </AppModal>
</template>

<style scoped>
.request-form {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.note {
    margin: 0;
    font-size: 0.85rem;
}

.actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 6px;
}
</style>
