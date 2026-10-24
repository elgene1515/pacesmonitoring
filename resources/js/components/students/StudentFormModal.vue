<script setup>
import { computed, ref, watch } from 'vue';
import { useStudentsStore } from '../../stores/students';
import { notify } from '../../stores/notifications';
import { adminService } from '../../services';
import AppModal from '../ui/AppModal.vue';
import StudentForm from './StudentForm.vue';

/**
 * Modal wrapper around StudentForm.
 *
 * Teachers enroll new students in one of their sections (through the students
 * store). Admins (`admin`) enroll, edit and move any student of the school
 * through the admin API, choosing among the sections the page passes in.
 *
 *  props   modelValue  Boolean        open state (v-model)
 *          student     Student|null   record to edit (admin only); null creates a new student
 *          sectionId   Number|null    section preselected for a new student
 *          admin       Boolean        use the admin API
 *          sections    Section[]|null the sections to offer (admin); null = the teacher's own
 *          loadingSections Boolean
 *          organizations Organization[]  platform owner only: the form starts with a school dropdown you can type into
 *          organizationId Number|String|null  school preselected for a new student
 *          branches    Branch[]       the branches that narrow the sections (the chosen school's, or the school's own)
 *          branchId    Number|String|null  branch preselected as that filter
 *  emits   update:modelValue(Boolean)
 *          school-change(id)          the owner picked a school in the form (the page loads its sections and branches)
 *          saved(Student)             the created / updated record returned by the API
 */
const props = defineProps({
    modelValue: { type: Boolean, default: false },
    student: { type: Object, default: null },
    sectionId: { type: [Number, String], default: null },
    admin: { type: Boolean, default: false },
    sections: { type: Array, default: null },
    loadingSections: { type: Boolean, default: false },
    organizations: { type: Array, default: () => [] },
    organizationId: { type: [Number, String], default: null },
    branches: { type: Array, default: () => [] },
    branchId: { type: [Number, String], default: null },
});

const emit = defineEmits(['update:modelValue', 'saved', 'school-change']);

const students = useStudentsStore();
const submitting = ref(false);
const errors = ref({});

const isEdit = computed(() => Boolean(props.student?.exists));
const title = computed(() => (isEdit.value ? 'Edit student' : 'Enroll student'));

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
        let saved;

        if (props.admin) {
            saved = isEdit.value
                ? await adminService.updateStudent(props.student.id, model)
                : await adminService.createStudent(model);
        } else {
            saved = await students.create(model);
        }

        notify.success(isEdit.value ? 'Student updated.' : `${saved.displayName} enrolled in ${saved.classLabel || 'the section'}.`);
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
            :section-id="sectionId"
            :sections="sections"
            :loading-sections="loadingSections"
            :organizations="organizations"
            :organization-id="organizationId"
            :branches="branches"
            :branch-id="branchId"
            :submitting="submitting"
            :errors="errors"
            submit-text="Enroll student"
            @school-change="emit('school-change', $event)"
            @submit="save"
            @cancel="close"
        />
    </AppModal>
</template>

<style scoped>
/* Layout is provided by AppModal and StudentForm. */
</style>
