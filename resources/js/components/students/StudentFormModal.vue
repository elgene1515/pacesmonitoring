<script setup>
import { computed, ref, watch } from 'vue';
import { useStudentsStore } from '../../stores/students';
import { notify } from '../../stores/notifications';
import AppModal from '../ui/AppModal.vue';
import StudentForm from './StudentForm.vue';

/**
 * Modal wrapper around StudentForm that talks to the students store.
 *
 *  props   modelValue  Boolean        open state (v-model)
 *          student     Student|null   record to edit; null creates a new student
 *  emits   update:modelValue(Boolean)
 *          saved(Student)             the created / updated record returned by the API
 */
const props = defineProps({
    modelValue: { type: Boolean, default: false },
    student: { type: Object, default: null },
});

const emit = defineEmits(['update:modelValue', 'saved']);

const students = useStudentsStore();
const submitting = ref(false);
const errors = ref({});

const isEdit = computed(() => Boolean(props.student?.exists));
const title = computed(() => (isEdit.value ? 'Edit student' : 'Add student'));

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
            ? await students.update(props.student.id, model)
            : await students.create(model);

        notify.success(isEdit.value ? 'Student updated.' : `${saved.displayName} added.`);
        emit('saved', saved);
        emit('update:modelValue', false);
    } catch (err) {
        if (err?.isValidation && err.hasFieldErrors) {
            errors.value = err.errors;

            // Limits that are not about one field (the plan's student cap) are shown as a toast.
            if (err.errors.students) {
                notify.error(err.first('students'));
            }
        } else {
            notify.error(err?.message ?? 'Unable to save the student.');
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
        width="620px"
        :closable="!submitting"
        :close-on-backdrop="false"
        @update:model-value="close"
    >
        <StudentForm
            v-if="modelValue"
            :student="student"
            :submitting="submitting"
            :errors="errors"
            submit-text="Add student"
            @submit="save"
            @cancel="close"
        />
    </AppModal>
</template>

<style scoped>
/* Layout is provided by AppModal and StudentForm. */
</style>
