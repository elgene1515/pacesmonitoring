<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { adminService } from '../../services';
import { notify } from '../../stores/notifications';
import { GradeLevel } from '../../models';
import AppModal from '../ui/AppModal.vue';
import AppField from '../ui/AppField.vue';
import SearchSelect from '../ui/SearchSelect.vue';

/**
 * Admin: create / edit a grade level of a branch under one school year.
 *
 *  props   modelValue    Boolean           open state (v-model)
 *          gradeLevel    GradeLevel|null   record to edit; null creates a new one
 *          branches      Branch[]          the school's branches (new grade levels)
 *          schoolYears   SchoolYear[]      the school's school years (new grade levels)
 *          branchId      Number|null       branch preselected for a new grade level
 *          schoolYearId  Number|null       school year preselected for a new grade level
 *          organizations Organization[]    platform owner only: a school dropdown you can type into comes first, and
 *                                          the branches and school years offered narrow to the chosen school
 *  emits   update:modelValue(Boolean)
 *          saved(GradeLevel)               the created / updated record returned by the API
 */
const props = defineProps({
    modelValue: { type: Boolean, default: false },
    gradeLevel: { type: Object, default: null },
    branches: { type: Array, default: () => [] },
    schoolYears: { type: Array, default: () => [] },
    branchId: { type: [Number, String], default: null },
    schoolYearId: { type: [Number, String], default: null },
    organizations: { type: Array, default: () => [] },
});

const emit = defineEmits(['update:modelValue', 'saved']);

const uid = `grade-level-${Math.random().toString(36).slice(2, 8)}`;

const form = reactive({ name: '', sortOrder: '', organizationId: '', branchId: '', schoolYearId: '' });
const submitting = ref(false);
const clientErrors = ref({});
const serverErrors = ref({});

const isEdit = computed(() => Boolean(props.gradeLevel?.exists));

/** The school dropdown shows when the platform owner has schools to choose from. */
const pickSchool = computed(() => props.organizations.length > 0 && !isEdit.value);
const schoolOptions = computed(() => props.organizations.map((organization) => ({ value: organization.id, label: organization.name })));

/** The chosen school's branches and school years (everything offered when there is no school dropdown). */
const branchOptions = computed(() => (pickSchool.value
    ? (form.organizationId === '' ? [] : props.branches.filter((branch) => String(branch.organizationId) === String(form.organizationId)))
    : props.branches));
const yearOptions = computed(() => (pickSchool.value
    ? (form.organizationId === '' ? [] : props.schoolYears.filter((year) => String(year.organizationId) === String(form.organizationId)))
    : props.schoolYears));

/** Another school: start the branch and school year over with that school's own (one branch is picked for you). */
function onSchoolChange(id) {
    form.organizationId = id;
    form.branchId = branchOptions.value.length === 1 ? branchOptions.value[0].id : '';
    form.schoolYearId = yearOptions.value.find((year) => year.isCurrent)?.id ?? '';
}
const title = computed(() => (isEdit.value ? 'Edit grade level' : 'Add grade level'));
const fieldErrors = computed(() => ({ ...clientErrors.value, ...serverErrors.value }));

function hydrate() {
    clientErrors.value = {};
    serverErrors.value = {};
    form.name = props.gradeLevel?.name ?? '';
    form.sortOrder = props.gradeLevel?.exists ? props.gradeLevel.sortOrder : '';
    form.branchId = props.gradeLevel?.exists
        ? (props.gradeLevel.branchId ?? '')
        : (props.branchId || (props.branches.length === 1 ? props.branches[0].id : '') || '');
    form.schoolYearId = props.gradeLevel?.exists
        ? (props.gradeLevel.schoolYearId ?? '')
        : (props.schoolYearId || props.schoolYears.find((year) => year.isCurrent)?.id || '');

    form.organizationId = '';

    if (pickSchool.value) {
        // The branch the page is filtered on tells which school; the only school there is starts the form too.
        const known = props.branches.find((branch) => String(branch.id) === String(props.branchId));

        form.organizationId = known?.organizationId ?? (props.organizations.length === 1 ? props.organizations[0].id : '');

        if (form.organizationId !== '') {
            // Keep a preselected branch / year only when it belongs to that school.
            if (!branchOptions.value.some((branch) => String(branch.id) === String(form.branchId))) {
                form.branchId = branchOptions.value.length === 1 ? branchOptions.value[0].id : '';
            }

            if (!yearOptions.value.some((year) => String(year.id) === String(form.schoolYearId))) {
                form.schoolYearId = yearOptions.value.find((year) => year.isCurrent)?.id ?? '';
            }
        } else {
            form.branchId = '';
            form.schoolYearId = '';
        }
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

    if (!String(form.name).trim()) {
        errors.name = ['Grade level name is required.'];
    } else if (String(form.name).trim().length > 100) {
        errors.name = ['Grade level name may not be longer than 100 characters.'];
    }

    if (pickSchool.value && form.organizationId === '') {
        errors.organization_id = ['Please select the school first.'];
    }

    if (!isEdit.value && !form.branchId) {
        errors.branch_id = ['Please select the branch this grade level belongs to.'];
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
        branchId: form.branchId,
        schoolYearId: form.schoolYearId,
    });

    submitting.value = true;
    serverErrors.value = {};

    try {
        const saved = isEdit.value
            ? await adminService.updateGradeLevel(props.gradeLevel.id, model)
            : await adminService.createGradeLevel(model);

        notify.success(isEdit.value ? 'Grade level updated.' : `${saved.name} added${saved.schoolYearName ? ` to SY ${saved.schoolYearName}` : ''}.`);
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
                v-if="pickSchool"
                label="School"
                :for="`${uid}-school`"
                :error="fieldErrors.organization_id"
                hint="Click to pick a school, or type part of its name to find it."
                required
            >
                <SearchSelect
                    :id="`${uid}-school`"
                    :model-value="form.organizationId"
                    :options="schoolOptions"
                    placeholder="Select a school"
                    empty-text="No school matches"
                    :invalid="Boolean(fieldErrors.organization_id)"
                    :disabled="submitting"
                    @change="onSchoolChange"
                />
            </AppField>

            <AppField
                v-if="!isEdit"
                label="Branch"
                :for="`${uid}-branch`"
                :error="fieldErrors.branch_id"
                hint="Each branch has its own grade levels and sections."
                required
            >
                <select :id="`${uid}-branch`" v-model="form.branchId" class="input" :disabled="submitting || (pickSchool && form.organizationId === '')">
                    <option value="" disabled>{{ pickSchool && form.organizationId === '' ? 'Select a school first' : (branchOptions.length ? 'Select branch' : 'No branches') }}</option>
                    <option v-for="branch in branchOptions" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
                </select>
            </AppField>

            <AppField
                v-if="!isEdit"
                label="School year"
                :for="`${uid}-year`"
                :error="fieldErrors.school_year_id"
                hint="Each school year has its own set of grade levels, subjects and sections."
                required
            >
                <select :id="`${uid}-year`" v-model="form.schoolYearId" class="input" :disabled="submitting || (pickSchool && form.organizationId === '')">
                    <option value="" disabled>{{ pickSchool && form.organizationId === '' ? 'Select a school first' : (yearOptions.length ? 'Select school year' : 'No school years registered') }}</option>
                    <option v-for="year in yearOptions" :key="year.id" :value="year.id">
                        {{ year.name }}{{ year.isCurrent ? ' (current)' : '' }}
                    </option>
                </select>
                <small v-if="yearOptions.length === 0 && !(pickSchool && form.organizationId === '')" class="no-years">
                    <RouterLink :to="{ name: 'admin-school-years' }">Register a school year</RouterLink> first.
                </small>
            </AppField>

            <p v-else class="muted fixed">
                {{ gradeLevel.branchName ? `${gradeLevel.branchName} · ` : '' }}SY {{ gradeLevel.schoolYearName || '—' }}
                — a grade level stays in the branch and school year it was registered under.
            </p>

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

.fixed {
    margin: 0;
    font-size: 0.9rem;
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
