<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useReferenceStore } from '../../stores/reference';
import { useSchoolYearsStore } from '../../stores/schoolYears';
import { notify } from '../../stores/notifications';
import { GradeLevel } from '../../models';
import AppModal from '../ui/AppModal.vue';
import AppField from '../ui/AppField.vue';

/**
 * Create / edit a grade level (school year + name + optional sort order) through the reference store.
 *
 *  props   modelValue    Boolean           open state (v-model)
 *          gradeLevel    GradeLevel|null   record to edit; null creates a new one
 *          schoolYearId  Number|null       school year preselected for a new grade level
 *  emits   update:modelValue(Boolean)
 *          saved(GradeLevel)               the created / updated record returned by the API
 */
const props = defineProps({
    modelValue: { type: Boolean, default: false },
    gradeLevel: { type: Object, default: null },
    schoolYearId: { type: [Number, String], default: null },
});

const emit = defineEmits(['update:modelValue', 'saved']);

const reference = useReferenceStore();
const schoolYears = useSchoolYearsStore();
const uid = `grade-level-${Math.random().toString(36).slice(2, 8)}`;

const form = reactive({ name: '', sortOrder: '', schoolYearId: '' });
const submitting = ref(false);
const clientErrors = ref({});
const serverErrors = ref({});

const isEdit = computed(() => Boolean(props.gradeLevel?.exists));
const title = computed(() => (isEdit.value ? 'Edit grade level' : 'Add grade level'));
const fieldErrors = computed(() => ({ ...clientErrors.value, ...serverErrors.value }));

const yearLabel = computed(() => {
    const year = schoolYears.byId(form.schoolYearId);
    return year ? year.name : '';
});

function hydrate() {
    clientErrors.value = {};
    serverErrors.value = {};
    form.name = props.gradeLevel?.name ?? '';
    form.sortOrder = props.gradeLevel?.exists ? props.gradeLevel.sortOrder : '';
    form.schoolYearId = props.gradeLevel?.exists
        ? (props.gradeLevel.schoolYearId ?? '')
        : (props.schoolYearId || schoolYears.current?.id || '');
}

watch(
    () => props.modelValue,
    (open) => {
        if (open) {
            schoolYears.load();
            hydrate();
        }
    },
    { immediate: true },
);

/** A new grade level defaults to the current school year once the years are loaded. */
watch(
    () => schoolYears.current,
    (current) => {
        if (current && !form.schoolYearId && !props.gradeLevel?.exists) {
            form.schoolYearId = current.id;
        }
    },
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
        errors.name = ['Grade level name is required.'];
    } else if (String(form.name).trim().length > 100) {
        errors.name = ['Grade level name may not be longer than 100 characters.'];
    }

    if (!isEdit.value && !form.schoolYearId) {
        errors.school_year_id = ['Please select the school year this grade level belongs to.'];
    }

    if (form.sortOrder !== '' && form.sortOrder !== null) {
        const order = Number(form.sortOrder);
        if (!Number.isInteger(order) || order < 0) {
            errors.sort_order = ['Sort order must be a whole number of 0 or more.'];
        }
    }

    clientErrors.value = errors;

    return Object.keys(errors).length === 0;
}

async function submit() {
    if (submitting.value || !validate()) {
        return;
    }

    const model = new GradeLevel({
        id: props.gradeLevel?.id ?? null,
        name: String(form.name).trim(),
        sortOrder: form.sortOrder,
        schoolYearId: form.schoolYearId,
    });

    submitting.value = true;
    serverErrors.value = {};

    try {
        const saved = isEdit.value
            ? await reference.updateGradeLevel(props.gradeLevel.id, model)
            : await reference.createGradeLevel(model);

        notify.success(isEdit.value ? 'Grade level updated.' : `${saved.name} added to SY ${saved.schoolYearName || yearLabel.value}.`);
        emit('saved', saved);
        emit('update:modelValue', false);
    } catch (err) {
        if (err?.isValidation && err.hasFieldErrors) {
            serverErrors.value = err.errors;
        } else {
            notify.error(err?.message ?? 'Unable to save the grade level.');
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
        <form v-if="modelValue" class="grade-level-form" novalidate @submit.prevent="submit">
            <AppField
                label="School year"
                :for="`${uid}-year`"
                :error="fieldErrors.school_year_id"
                :hint="isEdit ? 'A grade level stays in the school year it was registered under.' : 'Each school year has its own set of grade levels and subjects.'"
                required
            >
                <select
                    :id="`${uid}-year`"
                    v-model="form.schoolYearId"
                    class="input"
                    :disabled="submitting || isEdit || schoolYears.loading"
                >
                    <option value="" disabled>
                        {{ schoolYears.loading ? 'Loading…' : (schoolYears.hasItems ? 'Select school year' : 'No school years registered') }}
                    </option>
                    <option v-for="year in schoolYears.items" :key="year.id" :value="year.id">
                        {{ year.name }}{{ year.isCurrent ? ' (current)' : '' }}
                    </option>
                </select>
                <small v-if="schoolYears.loaded && !schoolYears.hasItems" class="no-years">
                    Ask your school administrator to register a school year first.
                </small>
            </AppField>

            <AppField label="Name" :for="`${uid}-name`" :error="fieldErrors.name" hint="e.g. Kindergarten, Grade 1" required>
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
                    {{ submitting ? 'Saving…' : (isEdit ? 'Save changes' : 'Add grade level') }}
                </button>
            </div>
        </form>
    </AppModal>
</template>

<style scoped>
.grade-level-form {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.no-years {
    color: var(--danger);
    font-size: 0.85rem;
}

.actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 6px;
}
</style>
