<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { adminService } from '../../services';
import { notify } from '../../stores/notifications';
import AppModal from '../ui/AppModal.vue';
import AppField from '../ui/AppField.vue';

/**
 * Admin: one stock movement on a PACE of the branch catalog.
 *
 *  mode 'receive'    booklets arrived: add them to the pool
 *       'adjust'     correct the pool: enter the right count, the difference is recorded
 *       'distribute' hand booklets from the pool to a teacher
 *       'holding'    set what a teacher holds (the difference moves to / from the pool)
 *
 *  props   modelValue  Boolean
 *          mode        String       one of the modes above
 *          pace        Pace         the PACE (poolStock, holdings)
 *          teachers    User[]       the branch's teachers (distribute / holding)
 *          teacherId   Number|null  teacher preselected (distribute / holding)
 *  emits   update:modelValue(Boolean)
 *          saved(Pace)              the PACE returned by the API after the move
 */
const props = defineProps({
    modelValue: { type: Boolean, default: false },
    mode: { type: String, default: 'receive' },
    pace: { type: Object, default: null },
    teachers: { type: Array, default: () => [] },
    teacherId: { type: [Number, String], default: null },
});

const emit = defineEmits(['update:modelValue', 'saved']);

const uid = `stock-${Math.random().toString(36).slice(2, 8)}`;
const form = reactive({ teacherId: '', quantity: 1, note: '' });
const clientErrors = ref({});
const serverErrors = ref({});
const submitting = ref(false);

const errors = computed(() => ({ ...clientErrors.value, ...serverErrors.value }));

const TITLES = {
    receive: 'Receive booklets',
    adjust: 'Correct the pool count',
    distribute: 'Give booklets to a teacher',
    holding: "Set a teacher's booklets",
};

const title = computed(() => TITLES[props.mode] ?? 'Stock');
const needsTeacher = computed(() => props.mode === 'distribute' || props.mode === 'holding');
const pool = computed(() => Number(props.pace?.poolStock ?? 0));

const teacher = computed(() => props.teachers.find((item) => Number(item.id) === Number(form.teacherId)) ?? null);

/** What the chosen teacher holds now (from the loaded holdings). */
const currentHolding = computed(() => (props.pace && form.teacherId ? props.pace.holdingOf(form.teacherId) : 0));

const quantityLabel = computed(() => {
    switch (props.mode) {
        case 'adjust':
            return 'The pool should have';
        case 'holding':
            return 'Booklets the teacher holds';
        default:
            return 'Booklets';
    }
});

const quantityHint = computed(() => {
    switch (props.mode) {
        case 'receive':
            return `Added to the pool (now ${pool.value}).`;
        case 'adjust':
            return `Booklets on hand for the branch. The pool is now ${pool.value}; the difference is saved in the ledger as a correction.`;
        case 'distribute':
            return `Taken from the pool (now ${pool.value}) and given to the teacher.`;
        case 'holding':
            return form.teacherId
                ? `Now ${currentHolding.value}. Setting it higher takes the difference from the pool (${pool.value}); lower returns it.`
                : 'The difference moves to or from the pool.';
        default:
            return '';
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
        form.teacherId = props.teacherId ?? '';
        form.note = '';

        if (props.mode === 'holding') {
            form.quantity = props.pace && form.teacherId ? props.pace.holdingOf(form.teacherId) : 0;
        } else if (props.mode === 'adjust') {
            form.quantity = pool.value;
        } else {
            form.quantity = 1;
        }
    },
);

watch(() => form.teacherId, (teacherId) => {
    if (props.mode === 'holding' && props.pace && teacherId) {
        form.quantity = props.pace.holdingOf(teacherId);
    }
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

    if (needsTeacher.value && !form.teacherId) {
        found.teacher_id = ['Pick a teacher.'];
    }

    if (!Number.isInteger(quantity)) {
        found.quantity = ['Enter a whole number.'];
    } else if (props.mode === 'adjust' && quantity < 0) {
        found.quantity = ['The pool cannot have fewer than 0 booklets.'];
    } else if (props.mode === 'adjust' && quantity === pool.value) {
        found.quantity = [`The pool already has ${pool.value}.`];
    } else if (props.mode === 'holding' && quantity < 0) {
        found.quantity = ['A teacher cannot hold fewer than 0 booklets.'];
    } else if ((props.mode === 'receive' || props.mode === 'distribute') && quantity < 1) {
        found.quantity = ['Enter at least 1 booklet.'];
    } else if (props.mode === 'distribute' && quantity > pool.value) {
        found.quantity = [`The pool only has ${pool.value}.`];
    } else if (props.mode === 'holding' && quantity - currentHolding.value > pool.value) {
        found.quantity = [`The pool only has ${pool.value} to give.`];
    }

    clientErrors.value = found;

    return Object.keys(found).length === 0;
}

async function submit() {
    if (submitting.value || !props.pace || !validate()) {
        return;
    }

    submitting.value = true;
    serverErrors.value = {};

    const quantity = Number(form.quantity);
    const note = String(form.note).trim();

    try {
        let saved;

        switch (props.mode) {
            case 'receive':
                saved = await adminService.receiveStock(props.pace.id, quantity, note);
                notify.success(`${quantity} booklet${quantity === 1 ? '' : 's'} of PACE ${saved.label} received; the pool is now ${saved.poolStock}.`);
                break;
            case 'adjust':
                saved = await adminService.adjustPool(props.pace.id, quantity - pool.value, note || `Recount: pool set to ${quantity} (was ${pool.value})`);
                notify.success(`Pool of PACE ${saved.label} corrected from ${pool.value} to ${saved.poolStock}.`);
                break;
            case 'distribute':
                saved = await adminService.distributeStock(props.pace.id, form.teacherId, quantity, note);
                notify.success(`${quantity} × PACE ${saved.label} given to ${teacher.value?.name ?? 'the teacher'}; the pool is now ${saved.poolStock}.`);
                break;
            default:
                saved = await adminService.setHolding(props.pace.id, form.teacherId, quantity);
                notify.success(`${teacher.value?.name ?? 'The teacher'} now holds ${quantity} × PACE ${saved.label}; the pool is now ${saved.poolStock}.`);
        }

        emit('saved', saved);
        emit('update:modelValue', false);
    } catch (err) {
        if (err?.isValidation && err.hasFieldErrors) {
            serverErrors.value = err.errors;

            if (err.errors.stock) {
                notify.error(err.first('stock'));
            }
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
        :title="title"
        width="440px"
        :closable="!submitting"
        :close-on-backdrop="false"
        @update:model-value="close"
    >
        <form v-if="modelValue && pace" class="stock-form" novalidate @submit.prevent="submit">
            <p class="muted intro">
                PACE <strong>{{ pace.label }}</strong> · pool <span class="mono">{{ pool }}</span>
                <template v-if="pace.holdingsTotal !== null"> · held by teachers <span class="mono">{{ pace.holdingsTotal }}</span></template>
            </p>

            <AppField v-if="needsTeacher" label="Teacher" :for="`${uid}-teacher`" :error="errors.teacher_id" required>
                <select :id="`${uid}-teacher`" v-model="form.teacherId" class="input" :disabled="submitting || teachers.length === 0">
                    <option value="" disabled>{{ teachers.length ? 'Select teacher' : 'No teachers in this branch' }}</option>
                    <option v-for="item in teachers" :key="item.id" :value="item.id">
                        {{ item.name }}<template v-if="pace.holdingOf(item.id) > 0"> · holds {{ pace.holdingOf(item.id) }}</template>
                    </option>
                </select>
            </AppField>

            <AppField :label="quantityLabel" :for="`${uid}-quantity`" :error="errors.quantity ?? errors.delta" :hint="quantityHint" required>
                <input
                    :id="`${uid}-quantity`"
                    v-model="form.quantity"
                    class="input quantity"
                    type="number"
                    :min="mode === 'adjust' || mode === 'holding' ? 0 : 1"
                    step="1"
                    inputmode="numeric"
                    :disabled="submitting"
                />
            </AppField>

            <AppField v-if="mode !== 'holding'" label="Note" :for="`${uid}-note`" :error="errors.note" hint="Optional: shows in the stock ledger.">
                <input :id="`${uid}-note`" v-model="form.note" class="input" type="text" maxlength="255" :disabled="submitting" />
            </AppField>

            <div class="actions">
                <button type="button" class="btn btn-secondary" :disabled="submitting" @click="close">Cancel</button>
                <button type="submit" class="btn" :disabled="submitting">{{ submitting ? 'Saving…' : 'Save' }}</button>
            </div>
        </form>
    </AppModal>
</template>

<style scoped>
.stock-form {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.intro {
    margin: 0;
    font-size: 0.92rem;
}

.quantity {
    max-width: 160px;
}

.actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 6px;
}
</style>
