<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useSchoolYearsStore } from '../../stores/schoolYears';
import { notify } from '../../stores/notifications';
import { SchoolYear } from '../../models';
import { currentSchoolYear, SCHOOL_YEAR_PATTERN } from '../../core/schoolYear';
import AppModal from '../ui/AppModal.vue';
import AppField from '../ui/AppField.vue';

/**
 * Register / edit a school year through the school years store.
 *
 *  props   modelValue  Boolean           open state (v-model)
 *          schoolYear  SchoolYear|null   record to edit; null registers a new one
 *  emits   update:modelValue(Boolean)
 *          saved(SchoolYear)             the created / updated record returned by the API
 */
const props = defineProps({
    modelValue: { type: Boolean, default: false },
    schoolYear: { type: Object, default: null },
});

const emit = defineEmits(['update:modelValue', 'saved']);

const schoolYears = useSchoolYearsStore();
const uid = `school-year-${Math.random().toString(36).slice(2, 8)}`;

const form = reactive({ name: '', startDate: '', endDate: '', isCurrent: false });
const submitting = ref(false);
const clientErrors = ref({});
const serverErrors = ref({});

const isEdit = computed(() => Boolean(props.schoolYear?.exists));
const title = computed(() => (isEdit.value ? 'Edit school year' : 'Add school year'));
const fieldErrors = computed(() => ({ ...clientErrors.value, ...serverErrors.value }));

/** Suggest the next unregistered school year, starting from the current one. */
function suggestedName() {
    let [start] = currentSchoolYear().split('-').map(Number);
    const taken = new Set(schoolYears.names);

    for (let attempts = 0; attempts < 10; attempts += 1) {
        const candidate = `${start}-${start + 1}`;
        if (!taken.has(candidate)) {
            return candidate;
        }
        start += 1;
    }

    return '';
}

function hydrate() {
    clientErrors.value = {};
    serverErrors.value = {};

    if (props.schoolYear?.exists) {
        form.name = props.schoolYear.name;
        form.startDate = props.schoolYear.startDate ?? '';
        form.endDate = props.schoolYear.endDate ?? '';
        form.isCurrent = Boolean(props.schoolYear.isCurrent);
    } else {
        form.name = suggestedName();
        form.startDate = '';
        form.endDate = '';
        form.isCurrent = !schoolYears.hasItems;
    }
}

/** Fill the dates from the label (June 1 to May 31) when they are still blank. */
function onNameChange() {
    if (!SCHOOL_YEAR_PATTERN.test(form.name)) {
        return;
    }

    const [start, end] = form.name.split('-');

    if (!form.startDate) {
        form.startDate = `${start}-06-01`;
    }

    if (!form.endDate) {
        form.endDate = `${end}-05-31`;
    }
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
    const name = String(form.name).trim();

    if (!name) {
        errors.name = ['School year is required.'];
    } else if (!SCHOOL_YEAR_PATTERN.test(name)) {
        errors.name = ['Use the format YYYY-YYYY, e.g. 2026-2027.'];
    } else {
        const [start, end] = name.split('-').map(Number);
        if (end !== start + 1) {
            errors.name = ['The school year must span two consecutive years, e.g. 2026-2027.'];
        }
    }

    if (form.startDate && form.endDate && form.endDate <= form.startDate) {
        errors.end_date = ['The end date must be after the start date.'];
    }

    clientErrors.value = errors;

    return Object.keys(errors).length === 0;
}

async function submit() {
    if (submitting.value || !validate()) {
        return;
    }

    const model = new SchoolYear({
        id: props.schoolYear?.id ?? null,
        name: String(form.name).trim(),
        startDate: form.startDate,
        endDate: form.endDate,
        isCurrent: form.isCurrent,
    });

    submitting.value = true;
    serverErrors.value = {};

    try {
        const saved = isEdit.value
            ? await schoolYears.update(props.schoolYear.id, model)
            : await schoolYears.create(model);

        notify.success(isEdit.value ? 'School year updated.' : `School year ${saved.name} added.`);
        emit('saved', saved);
        emit('update:modelValue', false);
    } catch (err) {
        if (err?.isValidation && err.hasFieldErrors) {
            serverErrors.value = err.errors;
        } else {
            notify.error(err?.message ?? 'Unable to save the school year.');
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
        <form v-if="modelValue" class="school-year-form" novalidate @submit.prevent="submit">
            <AppField label="School year" :for="`${uid}-name`" :error="fieldErrors.name" hint="Format YYYY-YYYY, e.g. 2026-2027" required>
                <input
                    :id="`${uid}-name`"
                    v-model="form.name"
                    class="input"
                    type="text"
                    maxlength="9"
                    placeholder="2026-2027"
                    autocomplete="off"
                    :disabled="submitting"
                    @change="onNameChange"
                />
            </AppField>

            <div class="dates">
                <AppField label="Start date" :for="`${uid}-start`" :error="fieldErrors.start_date">
                    <input :id="`${uid}-start`" v-model="form.startDate" class="input" type="date" :disabled="submitting" />
                </AppField>

                <AppField label="End date" :for="`${uid}-end`" :error="fieldErrors.end_date">
                    <input :id="`${uid}-end`" v-model="form.endDate" class="input" type="date" :disabled="submitting" />
                </AppField>
            </div>

            <AppField :error="fieldErrors.is_current" hint="New students and PACEs are filed under the current school year.">
                <label class="checkbox">
                    <input v-model="form.isCurrent" type="checkbox" :disabled="submitting" />
                    Set as the current school year
                </label>
            </AppField>

            <div class="actions">
                <button type="button" class="btn btn-secondary" :disabled="submitting" @click="close">Cancel</button>
                <button type="submit" class="btn" :disabled="submitting">
                    {{ submitting ? 'Saving…' : (isEdit ? 'Save changes' : 'Add school year') }}
                </button>
            </div>
        </form>
    </AppModal>
</template>

<style scoped>
.school-year-form {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.dates {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px 16px;
}

.actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 6px;
}

@media (max-width: 480px) {
    .dates {
        grid-template-columns: 1fr;
    }
}
</style>
