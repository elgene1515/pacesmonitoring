<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Pace } from '../../models';
import AppField from '../ui/AppField.vue';

/**
 * PACE create / edit form. When creating, leaving "End number" blank registers
 * just the one PACE; filling it in registers every number from "PACE number"
 * through "End number" (inclusive), all sharing the same name and stock.
 *
 *  props   pace        Pace|null   existing record to edit (null = blank create form)
 *          submitting  Boolean
 *          errors      Object      server-side 422 field map ({ name, number|start|end, stock })
 *          submitText  String      label of the primary button when creating
 *  emits   submit(Pace)                                  a Pace model built from the form (id kept when editing)
 *          submit-range({ name, start, end, stock })      only when creating with an end number filled in
 *          cancel()
 */
const props = defineProps({
    pace: { type: Object, default: null },
    submitting: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
    submitText: { type: String, default: 'Save' },
});

const emit = defineEmits(['submit', 'submit-range', 'cancel']);

const uid = `pace-${Math.random().toString(36).slice(2, 8)}`;
const MAX_RANGE_SIZE = 500;
const form = reactive({ number: '', end: '', name: '', stock: 0 });
const clientErrors = ref({});

watch(
    () => props.pace,
    (pace) => {
        clientErrors.value = {};
        form.number = pace?.number ?? '';
        form.end = '';
        form.name = pace?.name ?? '';
        form.stock = pace?.stock ?? 0;
    },
    { immediate: true },
);

const fieldErrors = computed(() => ({ ...clientErrors.value, ...props.errors }));
const isEdit = computed(() => Boolean(props.pace?.exists));
const isRange = computed(() => !isEdit.value && String(form.end).trim() !== '');

function stockValue() {
    const raw = String(form.stock ?? '').trim();

    return raw === '' ? 0 : Number(raw);
}

function validateStock(errors) {
    const stock = stockValue();

    if (!Number.isInteger(stock) || stock < 0) {
        errors.stock = ['Stock must be a whole number of 0 or more.'];
    }
}

function validateSingle() {
    const errors = {};
    const number = String(form.number).trim();

    if (!number) {
        errors.number = ['PACE number is required.'];
    } else if (!/^[0-9]+$/.test(number)) {
        errors.number = ['PACE number must contain digits only.'];
    } else if (number.length > 50) {
        errors.number = ['PACE number may not be longer than 50 characters.'];
    }

    if (!String(form.name).trim()) {
        errors.name = ['PACE name is required.'];
    }

    validateStock(errors);

    return errors;
}

function validateRange() {
    const errors = {};
    const start = String(form.number).trim();
    const end = String(form.end).trim();

    if (!/^[0-9]+$/.test(start)) {
        errors.number = ['PACE number must contain digits only when an end number is given.'];
    }

    if (!/^[0-9]+$/.test(end)) {
        errors.end = ['End number must contain digits only.'];
    } else if (/^[0-9]+$/.test(start) && Number(end) < Number(start)) {
        errors.end = ['End number must not be less than the PACE number.'];
    } else if (/^[0-9]+$/.test(start) && Number(end) - Number(start) + 1 > MAX_RANGE_SIZE) {
        errors.end = [`A single range may register at most ${MAX_RANGE_SIZE} PACEs.`];
    }

    if (!String(form.name).trim()) {
        errors.name = ['PACE name is required.'];
    }

    validateStock(errors);

    return errors;
}

function validate() {
    const errors = isRange.value ? validateRange() : validateSingle();
    clientErrors.value = errors;

    return Object.keys(errors).length === 0;
}

function submit() {
    if (props.submitting || !validate()) {
        return;
    }

    if (isRange.value) {
        emit('submit-range', {
            name: String(form.name).trim(),
            start: String(form.number).trim(),
            end: String(form.end).trim(),
            stock: stockValue(),
        });

        return;
    }

    emit('submit', new Pace({
        id: props.pace?.id ?? null,
        number: String(form.number).trim(),
        name: String(form.name).trim(),
        stock: stockValue(),
    }));
}
</script>

<template>
    <form class="pace-form" novalidate @submit.prevent="submit">
        <AppField
            label="PACE number"
            :for="`${uid}-number`"
            :error="fieldErrors.number"
            hint="e.g. 1001"
            required
        >
            <input
                :id="`${uid}-number`"
                v-model="form.number"
                class="input"
                type="text"
                inputmode="numeric"
                maxlength="50"
                autocomplete="off"
                :disabled="submitting"
            />
        </AppField>

        <AppField
            v-if="!isEdit"
            label="End number"
            :for="`${uid}-end`"
            :error="fieldErrors.end"
            hint="Leave blank to register just one PACE, or fill in to register every number from the PACE number above through this one, e.g. 1024."
        >
            <input
                :id="`${uid}-end`"
                v-model="form.end"
                class="input"
                type="text"
                inputmode="numeric"
                maxlength="50"
                autocomplete="off"
                :disabled="submitting"
            />
        </AppField>

        <AppField
            :label="isRange ? 'PACE name (used for every number in the range)' : 'PACE name'"
            :for="`${uid}-name`"
            :error="fieldErrors.name"
            required
        >
            <input
                :id="`${uid}-name`"
                v-model="form.name"
                class="input"
                type="text"
                autocomplete="off"
                :disabled="submitting"
            />
        </AppField>

        <AppField
            :label="isRange ? 'Stock on hand (for each PACE in the range)' : 'Stock on hand'"
            :for="`${uid}-stock`"
            :error="fieldErrors.stock"
            hint="Booklets you currently have. Issuing a PACE to a student takes one."
        >
            <input
                :id="`${uid}-stock`"
                v-model="form.stock"
                class="input stock-input"
                type="number"
                min="0"
                step="1"
                inputmode="numeric"
                :disabled="submitting"
            />
        </AppField>

        <div class="actions">
            <button type="button" class="btn btn-secondary" :disabled="submitting" @click="emit('cancel')">
                Cancel
            </button>
            <button type="submit" class="btn" :disabled="submitting">
                {{ submitting ? 'Saving…' : (isEdit ? 'Save changes' : (isRange ? 'Register PACEs' : submitText)) }}
            </button>
        </div>
    </form>
</template>

<style scoped>
.pace-form {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.stock-input {
    max-width: 160px;
}

.actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 6px;
}
</style>
