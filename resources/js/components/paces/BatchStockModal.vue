<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { adminService } from '../../services';
import { notify } from '../../stores/notifications';
import { pluralize } from '../../core/format';
import AppModal from '../ui/AppModal.vue';
import AppField from '../ui/AppField.vue';

/**
 * Admin: change the pool of several PACEs at once, all or nothing.
 *
 *  props   modelValue  Boolean
 *          paces       Pace[]    the selected PACEs
 *          mode        String    initial choice: 'receive' | 'adjust' | 'set'
 *  emits   update:modelValue(Boolean)
 *          saved(Pace[])         the PACEs returned by the API after the change
 */
const props = defineProps({
    modelValue: { type: Boolean, default: false },
    paces: { type: Array, default: () => [] },
    mode: { type: String, default: 'receive' },
});

const emit = defineEmits(['update:modelValue', 'saved']);

const uid = `batch-stock-${Math.random().toString(36).slice(2, 8)}`;

const MODES = [
    { value: 'receive', label: 'Booklets arrived', hint: 'Adds the number to the pool of every selected PACE.' },
    { value: 'adjust', label: 'Add or remove a number', hint: 'A positive number adds to every pool, a negative one takes away (lost or damaged booklets).' },
    { value: 'set', label: 'Set the pool to a number', hint: 'Every selected pool becomes exactly this number (a recount).' },
];

const form = reactive({ mode: 'receive', quantity: 1, note: '' });
const clientErrors = ref({});
const serverErrors = ref({});
const submitting = ref(false);

const errors = computed(() => ({ ...clientErrors.value, ...serverErrors.value }));
const current = computed(() => MODES.find((item) => item.value === form.mode) ?? MODES[0]);

const quantityLabel = computed(() => {
    switch (form.mode) {
        case 'adjust':
            return 'Change to each pool';
        case 'set':
            return 'Each pool should have';
        default:
            return 'Booklets for each PACE';
    }
});

/** What each selected pool would become, for the preview ("12 → 17"). */
const preview = computed(() => {
    const quantity = Number(form.quantity);

    if (!Number.isInteger(quantity)) {
        return [];
    }

    return props.paces.map((pace) => {
        const from = Number(pace.poolStock) || 0;
        let to = from;

        if (form.mode === 'receive') {
            to = from + quantity;
        } else if (form.mode === 'adjust') {
            to = from + quantity;
        } else {
            to = quantity;
        }

        return { id: pace.id, label: pace.label, from, to };
    });
});

const tooSmall = computed(() => preview.value.filter((row) => row.to < 0));
const unchanged = computed(() => preview.value.length > 0 && preview.value.every((row) => row.to === row.from));

watch(
    () => props.modelValue,
    (open) => {
        if (!open) {
            return;
        }

        clientErrors.value = {};
        serverErrors.value = {};
        form.mode = props.mode;
        form.quantity = props.mode === 'set' ? 0 : 1;
        form.note = '';
    },
);

watch(() => form.mode, (mode) => {
    form.quantity = mode === 'set' ? 0 : 1;
    clientErrors.value = {};
    serverErrors.value = {};
});

function close() {
    if (submitting.value) {
        return;
    }

    emit('update:modelValue', false);
}

function validate() {
    const found = {};
    const quantity = Number(form.quantity);

    if (!Number.isInteger(quantity)) {
        found.quantity = ['Enter a whole number.'];
    } else if (form.mode === 'receive' && quantity < 1) {
        found.quantity = ['Enter at least 1 booklet.'];
    } else if (form.mode === 'adjust' && quantity === 0) {
        found.quantity = ['Enter how many booklets to add or remove.'];
    } else if (form.mode === 'set' && quantity < 0) {
        found.quantity = ['A pool cannot have fewer than 0 booklets.'];
    } else if (tooSmall.value.length > 0) {
        found.quantity = [`PACE ${tooSmall.value[0].label} has only ${tooSmall.value[0].from} in its pool, so it cannot lose ${Math.abs(quantity)}.`];
    } else if (unchanged.value) {
        found.quantity = ['Every selected pool already has that number.'];
    }

    clientErrors.value = found;

    return Object.keys(found).length === 0;
}

async function submit() {
    if (submitting.value || props.paces.length === 0 || !validate()) {
        return;
    }

    submitting.value = true;
    serverErrors.value = {};

    try {
        const saved = await adminService.batchStock(props.paces.map((pace) => pace.id), form.mode, form.quantity, String(form.note).trim());

        notify.success(`${pluralize(saved.length, 'PACE')} updated.`);
        emit('saved', saved);
        emit('update:modelValue', false);
    } catch (err) {
        if (err?.isValidation && err.hasFieldErrors) {
            serverErrors.value = err.errors;
        } else {
            notify.error(err?.message ?? 'Unable to update the stock.');
        }
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <AppModal
        :model-value="modelValue"
        :title="`Edit the pool of ${pluralize(paces.length, 'PACE')}`"
        width="520px"
        :closable="!submitting"
        :close-on-backdrop="false"
        @update:model-value="close"
    >
        <form v-if="modelValue" class="batch-form" novalidate @submit.prevent="submit">
            <AppField label="What happened">
                <div class="modes" role="radiogroup" aria-label="Pool change">
                    <label v-for="item in MODES" :key="item.value" class="checkbox mode">
                        <input v-model="form.mode" type="radio" :name="`${uid}-mode`" :value="item.value" :disabled="submitting" />
                        {{ item.label }}
                    </label>
                </div>
            </AppField>

            <AppField :label="quantityLabel" :for="`${uid}-quantity`" :error="errors.quantity ?? errors.pace_ids" :hint="current.hint" required>
                <input
                    :id="`${uid}-quantity`"
                    v-model="form.quantity"
                    class="input quantity"
                    type="number"
                    :min="form.mode === 'adjust' ? undefined : (form.mode === 'set' ? 0 : 1)"
                    step="1"
                    inputmode="numeric"
                    :disabled="submitting"
                />
            </AppField>

            <AppField label="Note" :for="`${uid}-note`" :error="errors.note" hint="Optional: shows in each PACE's stock ledger.">
                <input :id="`${uid}-note`" v-model="form.note" class="input" type="text" maxlength="255" :disabled="submitting" />
            </AppField>

            <div class="preview">
                <p class="muted preview-title">Preview</p>
                <ul class="preview-list">
                    <li v-for="row in preview" :key="row.id" :class="{ bad: row.to < 0 }">
                        <span class="mono">#{{ row.label }}</span>
                        <span class="mono">{{ row.from }} → {{ row.to }}</span>
                    </li>
                </ul>
            </div>

            <div class="actions">
                <button type="button" class="btn btn-secondary" :disabled="submitting" @click="close">Cancel</button>
                <button type="submit" class="btn" :disabled="submitting">{{ submitting ? 'Saving…' : 'Apply to all' }}</button>
            </div>
        </form>
    </AppModal>
</template>

<style scoped>
.batch-form {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.modes {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.quantity {
    max-width: 160px;
}

.preview-title {
    margin: 0 0 4px;
    font-size: 0.85rem;
}

.preview-list {
    margin: 0;
    padding: 0;
    list-style: none;
    max-height: 170px;
    overflow-y: auto;
    border: 1px solid var(--border);
    border-radius: var(--radius);
}

.preview-list li {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    padding: 4px 10px;
    font-size: 0.9rem;
}

.preview-list li + li {
    border-top: 1px solid var(--border);
}

.preview-list li.bad {
    color: var(--danger);
}

.actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 6px;
}
</style>
