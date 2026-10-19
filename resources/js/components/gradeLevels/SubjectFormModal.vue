<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useReferenceStore } from '../../stores/reference';
import { notify } from '../../stores/notifications';
import { Subject } from '../../models';
import AppModal from '../ui/AppModal.vue';
import AppField from '../ui/AppField.vue';

/**
 * Create / edit a subject under a grade level through the reference store.
 *
 *  props   modelValue  Boolean          open state (v-model)
 *          gradeLevel  GradeLevel       the grade level the subject belongs to
 *          subject     Subject|null     record to edit; null adds a new subject
 *  emits   update:modelValue(Boolean)
 *          saved(Subject)               the created / updated record returned by the API
 */
const props = defineProps({
    modelValue: { type: Boolean, default: false },
    gradeLevel: { type: Object, default: null },
    subject: { type: Object, default: null },
});

const emit = defineEmits(['update:modelValue', 'saved']);

const reference = useReferenceStore();
const uid = `subject-${Math.random().toString(36).slice(2, 8)}`;

const form = reactive({ name: '', sortOrder: '' });
const submitting = ref(false);
const clientErrors = ref({});
const serverErrors = ref({});

const isEdit = computed(() => Boolean(props.subject?.exists));
const title = computed(() => {
    const level = props.gradeLevel?.name ? ` · ${props.gradeLevel.name}` : '';
    return (isEdit.value ? 'Edit subject' : 'Add subject') + level;
});
const fieldErrors = computed(() => ({ ...clientErrors.value, ...serverErrors.value }));

function hydrate() {
    clientErrors.value = {};
    serverErrors.value = {};
    form.name = props.subject?.name ?? '';
    form.sortOrder = props.subject?.exists ? props.subject.sortOrder : '';
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
        errors.name = ['Subject name is required.'];
    } else if (String(form.name).trim().length > 100) {
        errors.name = ['Subject name may not be longer than 100 characters.'];
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

    const model = new Subject({
        id: props.subject?.id ?? null,
        gradeLevelId: props.gradeLevel.id,
        name: String(form.name).trim(),
        sortOrder: form.sortOrder,
    });

    submitting.value = true;
    serverErrors.value = {};

    try {
        const saved = isEdit.value
            ? await reference.updateSubject(props.subject.id, model)
            : await reference.createSubject(model);

        notify.success(isEdit.value ? 'Subject updated.' : `${saved.name} added to ${props.gradeLevel.name}.`);
        emit('saved', saved);
        emit('update:modelValue', false);
    } catch (err) {
        if (err?.isValidation && err.hasFieldErrors) {
            serverErrors.value = err.errors;
        } else {
            notify.error(err?.message ?? 'Unable to save the subject.');
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

            <AppField label="Subject name" :for="`${uid}-name`" :error="fieldErrors.name" hint="e.g. Math, English, Science" required>
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
                    {{ submitting ? 'Saving…' : (isEdit ? 'Save changes' : 'Add subject') }}
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
