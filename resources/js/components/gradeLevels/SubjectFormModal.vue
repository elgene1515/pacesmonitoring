<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { adminService } from '../../services';
import { notify } from '../../stores/notifications';
import { Subject, Section } from '../../models';
import AppModal from '../ui/AppModal.vue';
import AppField from '../ui/AppField.vue';

/**
 * Admin: create / edit a subject or a section under a grade level (both are a
 * name plus an optional sort order).
 *
 *  props   modelValue  Boolean          open state (v-model)
 *          kind        'subject'|'section'
 *          gradeLevel  GradeLevel       the grade level the record belongs to
 *          record      Subject|Section|null  record to edit; null adds a new one
 *  emits   update:modelValue(Boolean)
 *          saved(Subject|Section)       the created / updated record returned by the API
 */
const props = defineProps({
    modelValue: { type: Boolean, default: false },
    kind: { type: String, default: 'subject' },
    gradeLevel: { type: Object, default: null },
    record: { type: Object, default: null },
});

const emit = defineEmits(['update:modelValue', 'saved']);

const uid = `${props.kind}-${Math.random().toString(36).slice(2, 8)}`;

const form = reactive({ name: '', sortOrder: '' });
const submitting = ref(false);
const clientErrors = ref({});
const serverErrors = ref({});

const isSection = computed(() => props.kind === 'section');
const noun = computed(() => (isSection.value ? 'section' : 'subject'));
const isEdit = computed(() => Boolean(props.record?.exists));
const title = computed(() => {
    const level = props.gradeLevel?.name ? ` · ${props.gradeLevel.name}` : '';
    return (isEdit.value ? `Edit ${noun.value}` : `Add ${noun.value}`) + level;
});
const fieldErrors = computed(() => ({ ...clientErrors.value, ...serverErrors.value }));
const nameHint = computed(() => (isSection.value ? 'e.g. Section 1, Ruby, St. Paul' : 'e.g. Math, English, Science'));

function hydrate() {
    clientErrors.value = {};
    serverErrors.value = {};
    form.name = props.record?.name ?? '';
    form.sortOrder = props.record?.exists ? props.record.sortOrder : '';
}

watch(
    () => props.modelValue,
    (open) => {
        if (open) {
            hydrate();
        }
    },
    { immediate: true },
);

function close() {
    if (submitting.value) {
        return;
    }

    emit('update:modelValue', false);
}

function validate() {
    const errors = {};

    if (!String(form.name).trim()) {
        errors.name = [`${isSection.value ? 'Section' : 'Subject'} name is required.`];
    } else if (String(form.name).trim().length > 100) {
        errors.name = [`${isSection.value ? 'Section' : 'Subject'} name may not be longer than 100 characters.`];
    }

    if (form.sortOrder !== '' && form.sortOrder !== null) {
        const order = Number(form.sortOrder);
        if (!Number.isInteger(order) || order < 0) {
            errors.sort_order = ['Sort order must be a whole number of 0 or more.'];
        }
    }

    if (!props.gradeLevel?.id) {
        errors.grade_level_id = ['A grade level is required.'];
    }

    clientErrors.value = errors;

    return Object.keys(errors).length === 0;
}

async function submit() {
    if (submitting.value || !validate()) {
        return;
    }

    const Model = isSection.value ? Section : Subject;
    const model = new Model({
        id: props.record?.id ?? null,
        gradeLevelId: props.gradeLevel.id,
        name: String(form.name).trim(),
        sortOrder: form.sortOrder,
    });

    submitting.value = true;
    serverErrors.value = {};

    try {
        let saved;

        if (isSection.value) {
            saved = isEdit.value
                ? await adminService.updateSection(props.record.id, model)
                : await adminService.createSection(props.gradeLevel.id, model);
        } else {
            saved = isEdit.value
                ? await adminService.updateSubject(props.record.id, model)
                : await adminService.createSubject(props.gradeLevel.id, model);
        }

        notify.success(isEdit.value
            ? `${isSection.value ? 'Section' : 'Subject'} updated.`
            : `${saved.name} added to ${props.gradeLevel.name}.`);
        emit('saved', saved);
        emit('update:modelValue', false);
    } catch (err) {
        if (err?.isValidation && err.hasFieldErrors) {
            serverErrors.value = err.errors;
        } else {
            notify.error(err?.message ?? `Unable to save the ${noun.value}.`);
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
        <form v-if="modelValue" class="subject-form" novalidate @submit.prevent="submit">
            <p v-if="fieldErrors.grade_level_id" class="text-danger form-error" role="alert">
                {{ Array.isArray(fieldErrors.grade_level_id) ? fieldErrors.grade_level_id[0] : fieldErrors.grade_level_id }}
            </p>

            <AppField :label="`${isSection ? 'Section' : 'Subject'} name`" :for="`${uid}-name`" :error="fieldErrors.name" :hint="nameHint" required>
                <input
                    :id="`${uid}-name`"
                    v-model="form.name"
                    class="input"
                    type="text"
                    maxlength="100"
                    autocomplete="off"
                    :disabled="submitting"
                />
            </AppField>

            <AppField
                label="Sort order"
                :for="`${uid}-order`"
                :error="fieldErrors.sort_order"
                hint="Lower numbers come first. Leave blank to add it at the end."
            >
                <input
                    :id="`${uid}-order`"
                    v-model="form.sortOrder"
                    class="input"
                    type="number"
                    min="0"
                    step="1"
                    inputmode="numeric"
                    :disabled="submitting"
                />
            </AppField>

            <div class="actions">
                <button type="button" class="btn btn-secondary" :disabled="submitting" @click="close">Cancel</button>
                <button type="submit" class="btn" :disabled="submitting">
                    {{ submitting ? 'Saving…' : (isEdit ? 'Save changes' : `Add ${noun}`) }}
                </button>
            </div>
        </form>
    </AppModal>
</template>

<style scoped>
.subject-form {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.form-error {
    margin: 0;
    font-size: 0.9rem;
}

.actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 6px;
}
</style>
