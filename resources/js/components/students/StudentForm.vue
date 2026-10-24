<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { Student, SEX_OPTIONS, STUDENT_DETAIL_FIELDS, blankStudentDetails, studentDetailsOf, cleanDetail } from '../../models';
import { useReferenceStore } from '../../stores/reference';
import { useAuthStore } from '../../stores/auth';
import { ageFromBirthDate, todayIso } from '../../core/format';
import AppField from '../ui/AppField.vue';
import SearchSelect from '../ui/SearchSelect.vue';
import StudentDetailsFields from './StudentDetailsFields.vue';

/**
 * Student create / edit form. A student is enrolled in one section (class);
 * teachers may pick one of the sections they teach in, admins any section of
 * the school. The student takes their own subjects of the section's grade
 * level (every one by default), so two students of a section may differ.
 *
 *  props   student     Student|null  existing record to edit (null = blank create form)
 *          sectionId   Number|null   section preselected for a new student
 *          sections    Section[]|null the sections to offer (admin pages pass the school's; null = the teacher's own, from the reference store)
 *          loadingSections Boolean   true while the parent is still loading `sections`
 *          organizations Organization[]  platform owner only (new student): a school dropdown you can type into comes
 *                                    first; the parent loads that school's `sections` and `branches` on `school-change`
 *          organizationId Number|String|null  school preselected for a new student
 *          branches    Branch[]      the branches to filter the sections by: the chosen school's (owner), or the school's own
 *                                    when it has more than one (school admin); a Branch dropdown shows with them
 *          branchId    Number|String|null  branch preselected as the filter (the page's branch filter)
 *          submitting  Boolean       disables inputs and shows the busy label
 *          errors      Object        server-side 422 field map (snake_case keys)
 *          submitText  String        label of the primary button when creating
 *  emits   submit(Student)           a Student model built from the form (id kept when editing)
 *          school-change(id)         the owner picked another school
 *          cancel()
 */
const props = defineProps({
    student: { type: Object, default: null },
    sectionId: { type: [Number, String], default: null },
    sections: { type: Array, default: null },
    loadingSections: { type: Boolean, default: false },
    organizations: { type: Array, default: () => [] },
    organizationId: { type: [Number, String], default: null },
    branches: { type: Array, default: () => [] },
    branchId: { type: [Number, String], default: null },
    submitting: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
    submitText: { type: String, default: 'Save' },
});

const emit = defineEmits(['submit', 'cancel', 'school-change']);

const reference = useReferenceStore();
const auth = useAuthStore();
const uid = `student-${Math.random().toString(36).slice(2, 8)}`;
const today = todayIso();

const form = reactive(blank());
/** The optional ID number, contact, emergency and health details. */
const details = reactive(blankStudentDetails());
const clientErrors = ref({});

/** Ids of the subjects the student takes (set from the section, or from the student when editing). */
const subjectIds = ref([]);

/** '' = every school year; otherwise only the sections of that school year are offered. */
const yearFilter = ref('');

/** The school (platform owner) and the branch that narrow the sections offered. */
const schoolValue = ref(props.organizationId ?? '');
const branchFilter = ref(props.student?.exists ? '' : (props.branchId ?? ''));
const pickSchool = computed(() => props.organizations.length > 0 && !props.student?.exists);
/** The Branch dropdown: after the school for the owner; for a school admin whenever the school has several branches. */
const showBranch = computed(() => pickSchool.value || (Array.isArray(props.sections) && props.branches.length > 1));
const schoolOptions = computed(() => props.organizations.map((organization) => ({ value: organization.id, label: organization.name })));

function blank() {
    return {
        firstName: '',
        middleName: '',
        lastName: '',
        extension: '',
        birthDate: '',
        age: '',
        sex: '',
        sectionId: '',
    };
}

/** The sections on offer: the parent's list (admin) or the teacher's own classes. */
const allSections = computed(() => (Array.isArray(props.sections) ? props.sections : reference.sections));

const sectionsLoading = computed(() => (Array.isArray(props.sections) ? props.loadingSections : reference.loading && !reference.loaded));

const sectionsLoaded = computed(() => (Array.isArray(props.sections) ? !props.loadingSections : reference.loaded));

/** The school years the offered sections belong to, newest first. */
const yearOptions = computed(() => {
    const map = new Map();

    for (const section of allSections.value) {
        const year = section.gradeLevel?.schoolYear;

        if (year && !map.has(year.id)) {
            map.set(year.id, year);
        }
    }

    return [...map.values()].sort((a, b) => String(b.name).localeCompare(String(a.name)));
});

const sectionOptions = computed(() => {
    let list = allSections.value;

    if (yearFilter.value !== '' && yearFilter.value !== null) {
        const numeric = Number(yearFilter.value);

        list = list.filter((section) => section.gradeLevel?.schoolYearId === numeric);
    }

    if (showBranch.value && branchFilter.value !== '') {
        const branch = Number(branchFilter.value);

        list = list.filter((section) => section.gradeLevel?.branchId === branch);
    }

    return list;
});

function sectionById(id) {
    const numeric = Number(id);
    return allSections.value.find((section) => section.id === numeric) ?? null;
}

/** The chosen section and its grade level's subjects (the student's choices). */
const chosenSection = computed(() => sectionById(form.sectionId));
const availableSubjects = computed(() => chosenSection.value?.offeredSubjects ?? []);

/** Subject ids the signed-in teacher handles in the chosen section (null for admins: no restriction). */
const teachableIds = computed(() => (auth.isTeacher && Array.isArray(chosenSection.value?.mySubjectIds) ? chosenSection.value.mySubjectIds : null));

function selectAllSubjects() {
    subjectIds.value = availableSubjects.value.map((subject) => subject.id);
}

function hydrate(student) {
    clientErrors.value = {};
    subjectIds.value = student?.subjects ? student.subjectIds : [];
    Object.assign(details, student ? studentDetailsOf(student) : blankStudentDetails());

    Object.assign(form, blank(), student
        ? {
            firstName: student.firstName ?? '',
            middleName: student.middleName ?? '',
            lastName: student.lastName ?? '',
            extension: student.extension ?? '',
            birthDate: student.birthDate ?? '',
            age: student.age ?? '',
            sex: student.sex ?? '',
            sectionId: student.sectionId ?? '',
        }
        : { sectionId: props.sectionId ?? '' });

    syncYearFilter();

    if (!student?.subjects) {
        selectAllSubjects();
    }
}

/** Start the school year filter on the chosen section's year, else the current year, else the newest. */
function syncYearFilter() {
    const chosen = sectionById(form.sectionId);

    if (chosen?.gradeLevel?.schoolYearId) {
        yearFilter.value = chosen.gradeLevel.schoolYearId;
        return;
    }

    if (yearFilter.value !== '' && yearOptions.value.some((year) => year.id === Number(yearFilter.value))) {
        return;
    }

    const current = yearOptions.value.find((year) => year.isCurrent) ?? yearOptions.value[0] ?? null;
    yearFilter.value = current ? current.id : '';
}

watch(() => props.student, hydrate, { immediate: true });

watch(() => props.sectionId, (sectionId) => {
    if (!props.student?.exists && sectionId) {
        form.sectionId = sectionId;
        syncYearFilter();
    }
});

/** Once the sections arrive, settle the year filter (and keep a preselected section). */
watch(allSections, () => syncYearFilter());

watch(() => props.organizationId, (id) => {
    if (pickSchool.value) {
        schoolValue.value = id ?? '';
    }
});

/** Another school: its branches and sections are loaded by the parent, so start the choices over. */
function onSchoolChange(id) {
    schoolValue.value = id;
    branchFilter.value = '';
    form.sectionId = '';
    emit('school-change', id);
}

/** Changing the branch drops a section that belongs to another branch. */
watch(branchFilter, (branch) => {
    const chosen = sectionById(form.sectionId);

    if (branch !== '' && chosen && chosen.gradeLevel?.branchId !== Number(branch)) {
        form.sectionId = '';
    }
});

/** Changing the school year drops a section that belongs to another year. */
watch(yearFilter, (schoolYearId) => {
    if (schoolYearId === '' || !form.sectionId) {
        return;
    }

    const chosen = sectionById(form.sectionId);

    if (chosen && chosen.gradeLevel?.schoolYearId !== Number(schoolYearId)) {
        form.sectionId = '';
    }
});

onMounted(() => {
    if (!Array.isArray(props.sections)) {
        reference.load();
    }
});

/** Merge client-side and server-side messages; server messages win. */
const fieldErrors = computed(() => ({ ...clientErrors.value, ...props.errors }));

const isEdit = computed(() => Boolean(props.student?.exists));


function toggleSubject(id) {
    subjectIds.value = subjectIds.value.includes(id)
        ? subjectIds.value.filter((item) => item !== id)
        : [...subjectIds.value, id];
}

/**
 * Picking another section of the same grade level keeps the ticked subjects the new
 * section also offers; another grade level starts over with every subject it offers.
 */
watch(
    () => [chosenSection.value?.id, chosenSection.value?.gradeLevelId],
    ([sectionId, gradeLevelId], [previousId, previousGradeLevelId]) => {
        if (!sectionId || !previousId || sectionId === previousId) {
            return;
        }

        if (gradeLevelId !== previousGradeLevelId) {
            selectAllSubjects();
        } else {
            const offered = availableSubjects.value.map((subject) => subject.id);
            subjectIds.value = subjectIds.value.filter((id) => offered.includes(id));
        }
    },
);

/** The sections arrive after the form opened (admin pages load them): fill the default once. */
watch(availableSubjects, (list) => {
    if (list.length && subjectIds.value.length === 0 && !props.student?.subjects) {
        selectAllSubjects();
    }
});

const noSections = computed(() => sectionsLoaded.value && allSections.value.length === 0 && !(pickSchool.value && schoolValue.value === ''));

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

    const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    const phonePattern = /^\+?[0-9 ()\-.]{7,30}$/;

    for (const [prop, key, label] of [['email', 'email', 'Student email'], ['guardianEmail', 'guardian_email', 'Emergency contact email']]) {
        if (cleanDetail(details[prop]) && !emailPattern.test(cleanDetail(details[prop]))) {
            errors[key] = [`${label} must be a valid email address.`];
        }
    }

    for (const [prop, key] of [['mobile', 'mobile'], ['guardianPhone', 'guardian_phone']]) {
        if (cleanDetail(details[prop]) && !phonePattern.test(cleanDetail(details[prop]))) {
            errors[key] = ['Enter a phone number using digits (spaces, dashes, brackets and a leading + are fine).'];
        }
    }

    if (!form.sectionId) {
        errors.section_id = ['Please select a section.'];
    } else if (availableSubjects.value.length > 0 && teachableIds.value !== null && !subjectIds.value.some((id) => teachableIds.value.includes(id))) {
        errors.subject_ids = ['Include at least one subject you teach in this section.'];
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
        ...Object.fromEntries(STUDENT_DETAIL_FIELDS.map((field) => [field.prop, cleanDetail(details[field.prop]) ?? ''])),
        sectionId: Number(form.sectionId),
        // Without a loaded subject list the server keeps / defaults them.
        subjects: availableSubjects.value.length > 0
            ? availableSubjects.value.filter((subject) => subjectIds.value.includes(subject.id))
            : null,
    }));
}
</script>

<template>
    <form class="student-form" novalidate @submit.prevent="submit">
        <div class="grid">
            <AppField label="Student ID number" :for="`${uid}-number`" :error="fieldErrors.student_number" hint="Optional; the school's own ID. Unique within the school." class="section-field">
                <input
                    :id="`${uid}-number`"
                    v-model="details.studentNumber"
                    class="input mono number-input"
                    type="text"
                    autocomplete="off"
                    maxlength="50"
                    :disabled="submitting"
                />
            </AppField>

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

            <AppField
                v-if="pickSchool"
                label="School"
                :for="`${uid}-school`"
                hint="Click to pick a school, or type part of its name to find it."
                required
                class="section-field"
            >
                <SearchSelect
                    :id="`${uid}-school`"
                    :model-value="schoolValue"
                    :options="schoolOptions"
                    placeholder="Select a school"
                    empty-text="No school matches"
                    :disabled="submitting"
                    @change="onSchoolChange"
                />
            </AppField>

            <AppField
                v-if="showBranch"
                label="Branch"
                :for="`${uid}-branch`"
                hint="Narrows the sections offered below."
                class="section-field"
            >
                <select :id="`${uid}-branch`" v-model="branchFilter" class="input" :disabled="submitting || (pickSchool && schoolValue === '')">
                    <option value="">{{ pickSchool && schoolValue === '' ? 'Select a school first' : 'All branches' }}</option>
                    <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
                </select>
            </AppField>

            <AppField
                v-if="yearOptions.length > 1"
                label="School year"
                :for="`${uid}-year`"
                hint="Narrows the sections offered below."
            >
                <select :id="`${uid}-year`" v-model="yearFilter" class="input" :disabled="submitting">
                    <option value="">All school years</option>
                    <option v-for="year in yearOptions" :key="year.id" :value="year.id">
                        {{ year.name }}{{ year.isCurrent ? ' (current)' : '' }}
                    </option>
                </select>
            </AppField>

            <AppField
                label="Section"
                :for="`${uid}-section`"
                :error="fieldErrors.section_id"
                :hint="isEdit ? 'Changing the section moves the student to that class.' : 'The class the student is enrolled in.'"
                required
                class="section-field"
            >
                <select
                    :id="`${uid}-section`"
                    v-model="form.sectionId"
                    class="input"
                    :disabled="submitting || sectionsLoading || (pickSchool && schoolValue === '')"
                >
                    <option value="" disabled>
                        {{ pickSchool && schoolValue === '' ? 'Select a school first' : (sectionsLoading ? 'Loading…' : (sectionOptions.length ? 'Select section' : 'No sections in this school year')) }}
                    </option>
                    <option v-for="section in sectionOptions" :key="section.id" :value="section.id">
                        {{ yearFilter === '' ? section.labelWithYear : section.label }}
                    </option>
                </select>
                <small v-if="noSections" class="no-sections">
                    <template v-if="Array.isArray(sections)">
                        No sections yet. Set up the grade levels (with their subjects and sections) before enrolling students.
                    </template>
                    <template v-else>
                        You are not teaching any class yet.
                        <RouterLink :to="{ name: 'teaching-requests' }">Ask your school admin for a class</RouterLink>
                        before enrolling students.
                    </template>
                </small>
            </AppField>
        </div>

        <AppField
            v-if="availableSubjects.length > 0"
            label="Subjects"
            :error="fieldErrors.subject_ids"
            hint="The subjects this student takes, from the ones the section offers. Students of one section may take different ones."
        >
            <div class="subjects" role="group" aria-label="Subjects the student takes">
                <label v-for="subject in availableSubjects" :key="subject.id" class="checkbox subject-option">
                    <input
                        type="checkbox"
                        :checked="subjectIds.includes(subject.id)"
                        :disabled="submitting"
                        @change="toggleSubject(subject.id)"
                    />
                    {{ subject.name }}
                    <span v-if="teachableIds !== null && teachableIds.includes(subject.id)" class="muted taught">(yours)</span>
                </label>
            </div>
            <button type="button" class="btn btn-link btn-sm all-link" :disabled="submitting" @click="selectAllSubjects">
                Select all
            </button>
        </AppField>

        <StudentDetailsFields v-model="details" :errors="fieldErrors" :disabled="submitting" />

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

.section-field {
    grid-column: 1 / -1;
}

.number-input {
    max-width: 260px;
}

.subjects {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 6px 16px;
}

.subject-option {
    min-width: 0;
}

.taught {
    font-size: 0.82rem;
}

.all-link {
    align-self: flex-start;
    padding-left: 0;
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

.no-sections {
    color: var(--danger);
    font-size: 0.85rem;
}

@media (max-width: 560px) {
    .grid,
    .subjects {
        grid-template-columns: 1fr;
    }
}
</style>
