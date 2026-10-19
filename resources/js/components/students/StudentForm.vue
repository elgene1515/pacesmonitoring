<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { Student, SEX_OPTIONS } from '../../models';
import { useReferenceStore } from '../../stores/reference';
import { useSchoolYearsStore } from '../../stores/schoolYears';
import { ageFromBirthDate, todayIso } from '../../core/format';
import AppField from '../ui/AppField.vue';

/**
 * Student create / edit form.
 *
 *  props   student     Student|null  existing record to edit (null = blank create form)
 *          submitting  Boolean       disables inputs and shows the busy label
 *          errors      Object        server-side 422 field map (snake_case keys)
 *          submitText  String        label of the primary button when creating
 *  emits   submit(Student)           a Student model built from the form (id kept when editing)
 *          cancel()
 */
const props = defineProps({
    student: { type: Object, default: null },
    submitting: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
    submitText: { type: String, default: 'Save' },
});

const emit = defineEmits(['submit', 'cancel']);

const reference = useReferenceStore();
const schoolYears = useSchoolYearsStore();
const uid = `student-${Math.random().toString(36).slice(2, 8)}`;
const today = todayIso();

const form = reactive(blank());
const clientErrors = ref({});

function blank() {
    return {
        firstName: '',
        middleName: '',
        lastName: '',
        extension: '',
        birthDate: '',
        age: '',
        sex: '',
        gradeLevelId: '',
        schoolYearId: '',
    };
}

function hydrate(student) {
    clientErrors.value = {};

    Object.assign(form, blank(), student
        ? {
            firstName: student.firstName ?? '',
            middleName: student.middleName ?? '',
            lastName: student.lastName ?? '',
            extension: student.extension ?? '',
            birthDate: student.birthDate ?? '',
            age: student.age ?? '',
            sex: student.sex ?? '',
            gradeLevelId: student.gradeLevelId ?? '',
            schoolYearId: student.schoolYearId ?? '',
        }
        : {});

    if (!form.schoolYearId && !student?.exists && schoolYears.current) {
        form.schoolYearId = schoolYears.current.id;
    }
}

watch(() => props.student, hydrate, { immediate: true });

/** A new student defaults to the current school year once the years are loaded. */
watch(
    () => schoolYears.current,
    (current) => {
        if (current && !form.schoolYearId && !props.student?.exists) {
            form.schoolYearId = current.id;
        }
    },
);

onMounted(() => {
    reference.load();
    schoolYears.load();
});

/** Merge client-side and server-side messages; server messages win. */
const fieldErrors = computed(() => ({ ...clientErrors.value, ...props.errors }));

/** Grade levels of the chosen school year (every grade level, labelled with its year, when none is chosen). */
const gradeLevelOptions = computed(() => reference.gradeLevelsFor(form.schoolYearId));

/** Changing the school year drops a grade level that belongs to another year. */
watch(
    () => form.schoolYearId,
    (schoolYearId) => {
        if (!schoolYearId || !form.gradeLevelId) {
            return;
        }

        const level = reference.gradeLevelById(form.gradeLevelId);

        if (level && level.schoolYearId !== Number(schoolYearId)) {
            form.gradeLevelId = '';
        }
    },
);

const isEdit = computed(() => Boolean(props.student?.exists));

/** Age follows the birth date whenever the user changes the date; it stays editable afterwards. */
function onBirthDateChange() {
    const age = ageFromBirthDate(form.birthDate);
    if (age !== null) {
        form.age = age;
    }
}

function validate() {
    const errors = {};

    if (!String(form.firstName).trim()) {
        errors.first_name = ['First name is required.'];
    }

    if (!String(form.lastName).trim()) {
        errors.last_name = ['Last name is required.'];
    }

    if (!form.birthDate) {
        errors.birth_date = ['Date of birth is required.'];
    } else if (form.birthDate > today) {
        errors.birth_date = ['Date of birth cannot be in the future.'];
    }

    const age = form.age === '' || form.age === null ? null : Number(form.age);
    if (age === null || Number.isNaN(age)) {
        errors.age = ['Age is required.'];
    } else if (!Number.isInteger(age) || age < 0 || age > 120) {
        errors.age = ['Age must be a whole number between 0 and 120.'];
    }

    if (!form.sex) {
        errors.sex = ['Please select a sex.'];
    }

    if (!form.gradeLevelId) {
        errors.grade_level_id = ['Please select a grade level.'];
    }

    clientErrors.value = errors;

    return Object.keys(errors).length === 0;
}

function submit() {
    if (props.submitting || !validate()) {
        return;
    }

    emit('submit', new Student({
        id: props.student?.id ?? null,
        firstName: String(form.firstName).trim(),
        middleName: String(form.middleName).trim(),
        lastName: String(form.lastName).trim(),
        extension: String(form.extension).trim(),
        birthDate: form.birthDate,
        age: Number(form.age),
        sex: form.sex,
        gradeLevelId: Number(form.gradeLevelId),
        schoolYearId: form.schoolYearId ? Number(form.schoolYearId) : null,
    }));
}
</script>

<template>
    <form class="student-form" novalidate @submit.prevent="submit">
        <div class="grid">
            <AppField label="First name" :for="`${uid}-first`" :error="fieldErrors.first_name" required>
                <input
                    :id="`${uid}-first`"
                    v-model="form.firstName"
                    class="input"
                    type="text"
                    autocomplete="off"
                    :disabled="submitting"
                />
            </AppField>

            <AppField label="Middle name" :for="`${uid}-middle`" :error="fieldErrors.middle_name">
                <input
                    :id="`${uid}-middle`"
                    v-model="form.middleName"
                    class="input"
                    type="text"
                    autocomplete="off"
                    :disabled="submitting"
                />
            </AppField>

            <AppField label="Last name" :for="`${uid}-last`" :error="fieldErrors.last_name" required>
                <input
                    :id="`${uid}-last`"
                    v-model="form.lastName"
                    class="input"
                    type="text"
                    autocomplete="off"
                    :disabled="submitting"
                />
            </AppField>

            <AppField label="Extension" :for="`${uid}-ext`" :error="fieldErrors.extension" hint="e.g. Jr., III">
                <input
                    :id="`${uid}-ext`"
                    v-model="form.extension"
                    class="input"
                    type="text"
                    autocomplete="off"
                    :disabled="submitting"
                />
            </AppField>

            <AppField label="Date of birth" :for="`${uid}-birth`" :error="fieldErrors.birth_date" required>
                <input
                    :id="`${uid}-birth`"
                    v-model="form.birthDate"
                    class="input"
                    type="date"
                    :max="today"
                    :disabled="submitting"
                    @change="onBirthDateChange"
                />
            </AppField>

            <AppField
                label="Age"
                :for="`${uid}-age`"
                :error="fieldErrors.age"
                hint="Filled in from the date of birth; adjust if needed."
                required
            >
                <input
                    :id="`${uid}-age`"
                    v-model="form.age"
                    class="input"
                    type="number"
                    min="0"
                    max="120"
                    step="1"
                    inputmode="numeric"
                    :disabled="submitting"
                />
            </AppField>

            <AppField label="Sex" :error="fieldErrors.sex" required>
                <div class="radios" role="radiogroup">
                    <label v-for="option in SEX_OPTIONS" :key="option.value" class="checkbox">
                        <input
                            v-model="form.sex"
                            type="radio"
                            :name="`${uid}-sex`"
                            :value="option.value"
                            :disabled="submitting"
                        />
                        {{ option.label }}
                    </label>
                </div>
            </AppField>

            <AppField label="Grade level" :for="`${uid}-grade`" :error="fieldErrors.grade_level_id" required>
                <select
                    :id="`${uid}-grade`"
                    v-model="form.gradeLevelId"
                    class="input"
                    :disabled="submitting || reference.loading"
                >
                    <option value="" disabled>
                        {{ reference.loading ? 'Loading…' : (gradeLevelOptions.length ? 'Select grade level' : 'No grade levels in this school year') }}
                    </option>
                    <option v-for="level in gradeLevelOptions" :key="level.id" :value="level.id">
                        {{ form.schoolYearId ? level.name : level.labelWithYear }}
                    </option>
                </select>
                <small v-if="reference.loaded && !reference.hasGradeLevels" class="no-levels">
                    You have no grade levels yet.
                    <RouterLink :to="{ name: 'grade-levels' }">Create one with its subjects</RouterLink>
                    before adding students.
                </small>
            </AppField>

            <AppField
                label="School year"
                :for="`${uid}-year`"
                :error="fieldErrors.school_year_id"
                hint="The school year the student is registered in."
            >
                <select
                    :id="`${uid}-year`"
                    v-model="form.schoolYearId"
                    class="input"
                    :disabled="submitting || schoolYears.loading"
                >
                    <option value="">
                        {{ schoolYears.loading ? 'Loading…' : (schoolYears.hasItems ? 'No school year' : 'No school years registered') }}
                    </option>
                    <option v-for="year in schoolYears.items" :key="year.id" :value="year.id">
                        {{ year.name }}{{ year.isCurrent ? ' (current)' : '' }}
                    </option>
                </select>
                <small v-if="schoolYears.loaded && !schoolYears.hasItems" class="no-levels">
                    Ask your school administrator to register a school year
                    so the student counts under it.
                </small>
            </AppField>
        </div>

        <div class="actions">
            <button type="button" class="btn btn-secondary" :disabled="submitting" @click="emit('cancel')">
                Cancel
            </button>
            <button type="submit" class="btn" :disabled="submitting">
                {{ submitting ? 'Saving…' : (isEdit ? 'Save changes' : submitText) }}
            </button>
        </div>
    </form>
</template>

<style scoped>
.student-form {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px 16px;
}

.radios {
    display: flex;
    align-items: center;
    gap: 18px;
    min-height: 36px;
}

.actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
}

.no-levels {
    color: var(--danger);
    font-size: 0.85rem;
}

@media (max-width: 560px) {
    .grid {
        grid-template-columns: 1fr;
    }
}
</style>