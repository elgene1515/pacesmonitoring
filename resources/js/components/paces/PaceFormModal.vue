<script setup>
import { computed, ref, watch } from 'vue';
import { usePacesStore } from '../../stores/paces';
import { notify } from '../../stores/notifications';
import AppModal from '../ui/AppModal.vue';
import PaceForm from './PaceForm.vue';

/**
 * Modal wrapper around PaceForm that talks to the paces store.
 *
 *  props   modelValue  Boolean      open state (v-model)
 *          pace        Pace|null    record to edit; null registers a new PACE
 *  emits   update:modelValue(Boolean)
 *          saved(Pace)
 */
const props = defineProps({
    modelValue: { type: Boolean, default: false },
    pace: { type: Object, default: null },
});

const emit = defineEmits(['update:modelValue', 'saved']);

const paces = usePacesStore();
const submitting = ref(false);
const errors = ref({});

const isEdit = computed(() => Boolean(props.pace?.exists));
const title = computed(() => (isEdit.value ? 'Edit PACE' : 'Register PACE'));

watch(
    () => props.modelValue,
    (open) => {
        if (open) {
            errors.value = {};
        }
    },
);

function close() {
    if (submitting.value) {
        return;
    }

    emit('update:modelValue', false);
}

async function save(model) {
    submitting.value = true;
    errors.value = {};

    try {
        const saved = isEdit.value
            ? await paces.update(props.pace.id, model)
            : await paces.create(model);

        notify.success(isEdit.value ? 'PACE updated.' : `PACE ${saved.label} registered.`);
        emit('saved', saved);
        emit('update:modelValue', false);
    } catch (err) {
        if (err?.isValidation && err.hasFieldErrors) {
            errors.value = err.errors;
        } else {
            notify.error(err?.message ?? 'Unable to save the PACE.');
        }
    } finally {
        submitting.value = false;
    }
}

async function saveRange({ name, start, end, stock }) {
    submitting.value = true;
    errors.value = {};

    try {
        const created = await paces.createRange({ name, start, end, stock });

        notify.success(`${created.length} PACEs registered: ${name} #${start}–${end}.`);
        emit('saved', created);
        emit('update:modelValue', false);
    } catch (err) {
        if (err?.isValidation && err.hasFieldErrors) {
            errors.value = err.errors;
        } else {
            notify.error(err?.message ?? 'Unable to register the PACE range.');
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
        width="460px"
        :closable="!submitting"
        :close-on-backdrop="false"
        @update:model-value="close"
    >
        <PaceForm
            v-if="modelValue"
            :pace="pace"
            :submitting="submitting"
            :errors="errors"
            submit-text="Register PACE"
            @submit="save"
            @submit-range="saveRange"
            @cancel="close"
        />
    </AppModal>
</template>

<style scoped>
/* Layout is provided by AppModal and PaceForm. */
</style>
