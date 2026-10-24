<script setup>
import { computed, ref, watch } from 'vue';
import { adminService } from '../../services';
import { notify } from '../../stores/notifications';
import AppModal from '../ui/AppModal.vue';
import PaceForm from './PaceForm.vue';

/**
 * Admin: modal wrapper around PaceForm that talks to the admin API.
 *
 *  props   modelValue  Boolean      open state (v-model)
 *          pace        Pace|null    record to edit; null registers a new PACE
 *          branches    Branch[]     the school's branches
 *          branchId    Number|null  branch preselected for a new PACE
 *          organizations Organization[]  platform owner only: a searchable school picker comes before the branch
 *          organizationId Number|String|null  school preselected for a new PACE
 *  emits   update:modelValue(Boolean)
 *          saved(Pace|Pace[])
 */
const props = defineProps({
    modelValue: { type: Boolean, default: false },
    pace: { type: Object, default: null },
    branches: { type: Array, default: () => [] },
    branchId: { type: [Number, String], default: null },
    organizations: { type: Array, default: () => [] },
    organizationId: { type: [Number, String], default: null },
});

const emit = defineEmits(['update:modelValue', 'saved']);

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
            ? await adminService.updatePace(props.pace.id, model)
            : await adminService.createPace(model);

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

async function saveRange(payload) {
    submitting.value = true;
    errors.value = {};

    try {
        const created = await adminService.createPaceRange(payload);

        notify.success(`${created.length} PACEs registered: ${payload.name} #${payload.start}–${payload.end}.`);
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
            :branches="branches"
            :branch-id="branchId"
            :organizations="organizations"
            :organization-id="organizationId"
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
