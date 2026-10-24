<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { adminService, ownerService } from '../../../services';
import { SEX_OPTIONS } from '../../../models';
import { useAuthStore } from '../../../stores/auth';
import { useScopeStore } from '../../../stores/scope';
import { notify } from '../../../stores/notifications';
import { formatDate, formatScore, pluralize } from '../../../core/format';
import EmptyState from '../../ui/EmptyState.vue';
import AppIcon from '../../ui/AppIcon.vue';
import ConfirmDialog from '../../ui/ConfirmDialog.vue';
import SearchSelect from '../../ui/SearchSelect.vue';
import StudentFormModal from '../../students/StudentFormModal.vue';
import PaceRecordsBar from '../../charts/PaceRecordsBar.vue';
import PaceRecordsLegend from '../../charts/PaceRecordsLegend.vue';

/** Bar segments and legend read tested / issued / assigned, left to right (same as the teacher pages). */
const RECORD_ORDER = ['tested', 'issued', 'assigned'];

const auth = useAuthStore();
const scope = useScopeStore();

const students = ref([]);
const loading = ref(true);
const loaded = ref(false);
const search = ref('');

/**
 * The owner can narrow the list to one school here (the scope bar does the same for the
 * whole admin area; while it is set, this filter is hidden). School admins never see it.
 */
const organizations = ref([]);
const schoolId = ref('');
const showSchoolFilter = computed(() => auth.isPlatform && !scope.isScoped);

/** The organization_id the page's own requests carry, when the school filter is in use. */
const schoolParams = () => (showSchoolFilter.value && schoolId.value !== '' ? { organization_id: schoolId.value } : {});

/** '' (any) | 'male' | 'female': narrows the loaded students by sex. */
const sexFilter = ref('');
/** Average-score range (0–100, either end optional); students without a score drop out once a bound is set. */
const scoreMin = ref('');
const scoreMax = ref('');

/** The score bounds as numbers (null when blank), swapped when entered back to front. */
const scoreRange = computed(() => {
    const parse = (value) => {
        const text = String(value ?? '').trim();
        const number = Number(text);

        return text === '' || Number.isNaN(number) ? null : Math.min(100, Math.max(0, number));
    };

    let min = parse(scoreMin.value);
    let max = parse(scoreMax.value);

    if (min !== null && max !== null && min > max) {
        [min, max] = [max, min];
    }

    return { min, max };
});

/** Teachers belong to one branch each, so the branch narrows the students (and the teachers offered below). */
const branches = ref([]);
const branchId = ref('');

/**
 * Branch names repeat across schools (every school has a "Main"), so the branch
 * filter is only offered when one school is in view (a school admin's own, or the
 * one the owner picked in the scope bar) and it has more than one branch.
 */
const showBranchFilter = computed(() => (!auth.isPlatform || scope.isScoped || schoolId.value !== '') && branches.value.length > 1);


const teachers = ref([]);
const teacherId = ref('');

/** The organization's school years (independent of the teacher). */
const schoolYears = ref([]);
const schoolYearId = ref('');
const loadingSchoolYears = ref(false);

/** The grade levels of the selected branch and school year, and the selected one's sections. */
const gradeLevels = ref([]);
const gradeLevelId = ref('');
const loadingGradeLevels = ref(false);
const sectionId = ref('');

const sectionOptions = computed(() => gradeLevels.value.find((level) => level.id === Number(gradeLevelId.value))?.sections ?? []);

/* Enroll / edit / delete */
const formOpen = ref(false);
const editing = ref(null);
const formSections = ref([]);
const loadingFormSections = ref(false);
/** The school the Enroll form is for (platform owner without a school in the scope bar) and its branches. */
const formSchoolId = ref('');
const formBranches = ref([]);
/** The school filter's choices: every school, or none picked. */
const schoolFilterOptions = computed(() => [{ value: '', label: 'All schools' }, ...organizations.value.map((school) => ({ value: school.id, label: school.name }))]);
/** The branches that narrow the Enroll form's sections: the chosen school's for the owner, the school's own otherwise. */
const formBranchOptions = computed(() => (showSchoolFilter.value ? (editing.value ? [] : formBranches.value) : branches.value));
const formOrganizations = computed(() => (showSchoolFilter.value && !editing.value ? organizations.value : []));
const deleting = ref(null);
const removing = ref(false);

const pendingChanges = computed(() => auth.organization?.pendingStudentChangeRequestsCount ?? 0);

let searchTimer = null;

const hasFilters = computed(() => (
    search.value.trim() !== ''
    || schoolId.value !== ''
    || sexFilter.value !== ''
    || scoreRange.value.min !== null
    || scoreRange.value.max !== null
    || branchId.value !== ''
    || teacherId.value !== ''
    || gradeLevelId.value !== ''
    || sectionId.value !== ''
    || schoolYearId.value !== ''
));
/** The loaded students narrowed by the sex and score-range filters (the rest are filtered by the server). */
const visibleStudents = computed(() => {
    let list = students.value;

    if (sexFilter.value !== '') {
        list = list.filter((student) => student.sex === sexFilter.value);
    }

    const { min, max } = scoreRange.value;

    if (min !== null || max !== null) {
        list = list.filter((student) => {
            const score = student.averageScore;

            return score !== null && score !== undefined
                && (min === null || score >= min)
                && (max === null || score <= max);
        });
    }

    return list;
});

const emptyMessage = computed(() => (hasFilters.value ? 'No students match your filters.' : 'No students registered yet.'));

async function load() {
    loading.value = true;

    try {
        students.value = await adminService.listStudents({
            ...schoolParams(),
            search: search.value,
            branch_id: branchId.value,
            teacher_id: teacherId.value,
            grade_level_id: gradeLevelId.value,
            section_id: sectionId.value,
            school_year_id: schoolYearId.value,
        });
        loaded.value = true;
    } catch (err) {
        notify.error(err?.message ?? 'Failed to load students.');
    } finally {
        loading.value = false;
    }
}

/** The teachers offered in the filter follow the selected branch. */
async function loadTeachers() {
    try {
        teachers.value = await adminService.listTeachers({ branch_id: branchId.value, ...schoolParams() });
    } catch (err) {
        notify.error(err?.message ?? 'Failed to load teachers.');
    }
}

async function loadBranches() {
    try {
        branches.value = await adminService.listBranches(schoolParams());
    } catch {
        branches.value = [];
    }
}

async function loadOrganizations() {
    if (!auth.isPlatform) {
        return;
    }

    try {
        organizations.value = await ownerService.listOrganizations();
    } catch {
        organizations.value = [];
    }
}

/** Changing the school drops the branch, teacher, school year and grade level filters and reloads their options. */
async function onSchoolChange() {
    branchId.value = '';
    teacherId.value = '';
    schoolYearId.value = '';
    gradeLevelId.value = '';
    sectionId.value = '';
    gradeLevels.value = [];

    await Promise.all([loadBranches(), loadTeachers(), loadSchoolYears()]);

    load();
}

/** Changing the branch drops the teacher and grade level filters and offers only that branch's teachers and grade levels. */
async function onBranchChange() {
    teacherId.value = '';

    await Promise.all([loadTeachers(), reloadGradeLevels()]);

    load();
}

/** The grade levels of the chosen branch and school year (none until a school year is picked). */
async function reloadGradeLevels() {
    gradeLevelId.value = '';
    sectionId.value = '';
    gradeLevels.value = [];

    if (!schoolYearId.value) {
        return;
    }

    loadingGradeLevels.value = true;

    try {
        gradeLevels.value = await adminService.listGradeLevels({
            ...schoolParams(),
            branch_id: branchId.value,
            school_year_id: schoolYearId.value,
        });
    } catch (err) {
        notify.error(err?.message ?? 'Failed to load grade levels.');
    } finally {
        loadingGradeLevels.value = false;
    }
}

function onGradeLevelChange() {
    sectionId.value = '';
    load();
}

/** The organization's school years (every teacher shares the same list). */
async function loadSchoolYears(teacherId = null) {
    loadingSchoolYears.value = true;

    try {
        schoolYears.value = await adminService.listSchoolYears(teacherId || null, schoolParams());
    } catch (err) {
        notify.error(err?.message ?? 'Failed to load school years.');
    } finally {
        loadingSchoolYears.value = false;
    }
}

/** The teacher filter narrows the students to the sections that teacher teaches in. */
function onTeacherChange() {
    load();
}

/** Changing the school year reloads the grade levels for that year. */
async function onSchoolYearChange() {
    await reloadGradeLevels();

    load();
}

/** Every section of the school, with its grade level and school year, for the enroll / edit form. */
async function loadFormSections(organizationId = null) {
    loadingFormSections.value = true;

    try {
        const levels = await adminService.listGradeLevels(organizationId ? { organization_id: organizationId } : schoolParams());

        formSections.value = levels.flatMap((level) => level.sections.map((section) => {
            section.gradeLevel = level;
            return section;
        }));
    } catch (err) {
        notify.error(err?.message ?? 'Failed to load the sections.');
    } finally {
        loadingFormSections.value = false;
    }
}

/** The owner picked a school in the Enroll form: offer that school's branches and sections. */
async function onFormSchoolChange(id) {
    formSchoolId.value = id;
    formSections.value = [];
    formBranches.value = [];

    if (!id) {
        return;
    }

    loadFormSections(id);

    try {
        formBranches.value = await adminService.listBranches({ organization_id: id });
    } catch {
        formBranches.value = [];
    }
}

function openEnroll() {
    editing.value = null;
    formOpen.value = true;
    formSections.value = [];
    formBranches.value = [];
    formSchoolId.value = '';

    if (showSchoolFilter.value) {
        // The page's school filter (or the only school there is) starts the form; otherwise the owner picks one first.
        const preselected = schoolId.value !== '' ? schoolId.value : (organizations.value.length === 1 ? organizations.value[0].id : '');

        if (preselected !== '') {
            onFormSchoolChange(preselected);
        }

        return;
    }

    loadFormSections();
}

function openEdit(student) {
    editing.value = student;
    formOpen.value = true;
    loadFormSections(showSchoolFilter.value ? student.organizationId ?? null : null);
}

async function confirmRemove() {
    const target = deleting.value;

    if (removing.value || !target) {
        return;
    }

    removing.value = true;

    try {
        await adminService.removeStudent(target.id);
        notify.success(`${target.displayName} deleted.`);
        deleting.value = null;
        load();
    } catch (err) {
        notify.error(err?.message ?? 'Unable to delete the student.');
    } finally {
        removing.value = false;
    }
}

function clearFilters() {
    const hadBranch = branchId.value !== '';
    const hadSchool = schoolId.value !== '';

    search.value = '';
    schoolId.value = '';
    sexFilter.value = '';
    scoreMin.value = '';
    scoreMax.value = '';
    branchId.value = '';
    teacherId.value = '';
    schoolYearId.value = '';
    gradeLevelId.value = '';
    sectionId.value = '';
    gradeLevels.value = [];

    if (hadSchool) {
        loadBranches();
        loadSchoolYears();
    }

    if (hadBranch || hadSchool) {
        loadTeachers();
    }

    load();
}

watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(load, 300);
});

watch(() => scope.organizationId, () => {
    clearFilters();
    loadBranches();
    loadTeachers();
    loadSchoolYears();
});

onMounted(() => {
    loadOrganizations();
    load();
    loadBranches();
    loadTeachers();
    loadSchoolYears();
});

onBeforeUnmount(() => {
    clearTimeout(searchTimer);
});
</script>

<template>
    <div class="admin-students">
        <div class="page-header">
            <div>
                <h1>Students</h1>
                <p class="muted subtitle">Every student is enrolled in one section and shared by the teachers of that section.</p>
            </div>
            <div class="page-actions">
                <RouterLink :to="{ name: 'admin-student-change-requests' }" class="btn btn-secondary">
                    Proposed changes
                    <span v-if="pendingChanges > 0" class="count-badge" :title="pluralize(pendingChanges, 'pending proposal')">{{ pendingChanges }}</span>
                </RouterLink>
                <button type="button" class="btn" :disabled="!auth.canWrite" @click="openEnroll">Enroll student</button>
            </div>
        </div>

        <div class="card">
            <!-- Filters on the left; the student count and the colour key for the bars on the right. -->
            <div class="toolbar">
                <div class="toolbar-controls">
                    <input
                        v-model="search"
                        class="input search"
                        type="search"
                        placeholder="Search by name or student ID"
                        aria-label="Search students by name or student ID"
                    />
                    <SearchSelect
                        v-if="showSchoolFilter"
                        v-model="schoolId"
                        class="school-select"
                        :options="schoolFilterOptions"
                        aria-label="Filter by school"
                        empty-text="No school matches"
                        @change="onSchoolChange"
                    />
                    <select v-if="showBranchFilter" v-model="branchId" class="input select" aria-label="Filter by branch" @change="onBranchChange">
                        <option value="">All branches</option>
                        <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
                    </select>
                    <select v-model="teacherId" class="input select" aria-label="Filter by teacher" @change="onTeacherChange">
                        <option value="">All teachers</option>
                        <option v-for="teacher in teachers" :key="teacher.id" :value="teacher.id">
                            {{ teacher.name }}
                        </option>
                    </select>
                    <select
                        v-model="schoolYearId"
                        class="input select"
                        aria-label="Filter by school year"
                        :disabled="loadingSchoolYears"
                        @change="onSchoolYearChange"
                    >
                        <option value="">All school years</option>
                        <option v-for="year in schoolYears" :key="year.id" :value="year.id">
                            {{ year.name }}{{ year.isCurrent ? ' (current)' : '' }}
                        </option>
                    </select>
                    <select
                        v-model="gradeLevelId"
                        class="input select"
                        aria-label="Filter by grade level"
                        :disabled="!schoolYearId || loadingGradeLevels"
                        @change="onGradeLevelChange"
                    >
                        <option value="">
                            {{ schoolYearId ? 'All grade levels' : 'Pick a school year first' }}
                        </option>
                        <option v-for="level in gradeLevels" :key="level.id" :value="level.id">
                            {{ showBranchFilter && !branchId && level.branchName ? `${level.name} · ${level.branchName}` : level.name }}
                        </option>
                    </select>
                    <select
                        v-model="sectionId"
                        class="input select"
                        aria-label="Filter by section"
                        :disabled="!gradeLevelId"
                        @change="load"
                    >
                        <option value="">{{ gradeLevelId ? 'All sections' : 'Pick a grade level first' }}</option>
                        <option v-for="section in sectionOptions" :key="section.id" :value="section.id">{{ section.name }}</option>
                    </select>
                    <select v-model="sexFilter" class="input select select-sm" aria-label="Filter by sex">
                        <option value="">All sexes</option>
                        <option v-for="option in SEX_OPTIONS" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                    <div class="score-range" role="group" aria-label="Filter by average score">
                        <span class="muted score-range-label">Score</span>
                        <input
                            v-model="scoreMin"
                            class="input score-input"
                            type="number"
                            min="0"
                            max="100"
                            step="1"
                            inputmode="decimal"
                            placeholder="Min"
                            aria-label="Minimum average score"
                        />
                        <span class="muted" aria-hidden="true">–</span>
                        <input
                            v-model="scoreMax"
                            class="input score-input"
                            type="number"
                            min="0"
                            max="100"
                            step="1"
                            inputmode="decimal"
                            placeholder="Max"
                            aria-label="Maximum average score"
                        />
                    </div>
                    <button v-if="hasFilters" type="button" class="btn btn-link btn-sm" @click="clearFilters">
                        Clear
                    </button>
                </div>
                <div class="toolbar-meta">
                    <span class="muted count nowrap">
                        {{ loading ? 'Loading…' : pluralize(visibleStudents.length, 'student') }}
                    </span>
                    <PaceRecordsLegend v-if="visibleStudents.length > 0" :order="RECORD_ORDER" />
                </div>
            </div>

            <p v-if="loading && !loaded" class="muted state">Loading…</p>

            <EmptyState v-else-if="visibleStudents.length === 0" :message="emptyMessage">
                <template #action>
                    <button v-if="hasFilters" type="button" class="btn btn-secondary btn-sm" @click="clearFilters">Clear filters</button>
                    <button v-else type="button" class="btn btn-secondary btn-sm" :disabled="!auth.canWrite" @click="openEnroll">Enroll student</button>
                </template>
            </EmptyState>

            <div v-else class="table-wrap">
                <table class="table">
                    <!-- Fixed column widths so every column lines up down the page. -->
                    <colgroup>
                        <col class="col-student-id" />
                        <col class="col-name" />
                        <col class="col-grade" />
                        <col class="col-year" />
                        <col class="col-teacher" />
                        <col class="col-sex" />
                        <col class="col-age" />
                        <col class="col-birth" />
                        <col class="col-records" />
                        <col class="col-average" />
                        <col class="col-actions" />
                    </colgroup>
                    <thead>
                        <tr>
                            <th>Student ID</th>
                            <th>Name</th>
                            <th>Class</th>
                            <th>School year</th>
                            <th>Branch</th>
                            <th>Sex</th>
                            <th>Age</th>
                            <th>Birth date</th>
                            <th>PACE records</th>
                            <th class="text-right">Average score</th>
                            <th class="table-actions"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="student in visibleStudents" :key="student.id">
                            <td class="mono ellipsis" :title="student.studentNumber || ''">
                                <span v-if="student.studentNumber">{{ student.studentNumber }}</span>
                                <span v-else class="muted">—</span>
                            </td>
                            <td class="ellipsis">
                                <RouterLink :to="{ name: 'admin-student-detail', params: { id: student.id } }" class="name">
                                    {{ student.fullName }}
                                </RouterLink>
                            </td>
                            <td class="ellipsis" :title="student.classLabel">
                                <RouterLink v-if="student.sectionId" :to="{ name: 'admin-section-detail', params: { id: student.sectionId } }">
                                    {{ student.classLabel || '—' }}
                                </RouterLink>
                                <span v-else>—</span>
                            </td>
                            <td class="mono">{{ student.schoolYearName || '—' }}</td>
                            <td class="ellipsis" :title="student.registeredByName ? `Registered by ${student.registeredByName}` : ''">
                                {{ student.branchName || '—' }}
                            </td>
                            <td>{{ student.sexLabel || '—' }}</td>
                            <td class="mono">{{ student.age ?? '—' }}</td>
                            <td class="nowrap">{{ formatDate(student.birthDate) || '—' }}</td>
                            <td class="records">
                                <PaceRecordsBar
                                    :total="student.assignmentsCount"
                                    :tested="student.testedCount"
                                    :issued="student.issuedCount"
                                    :assigned="student.assignedCount"
                                    :order="RECORD_ORDER"
                                    tooltip
                                />
                            </td>
                            <td class="mono text-right" :title="student.averageScore === null ? '' : 'Across all PACE records of the enrolled school year; untested PACEs count as 0'">
                                <span v-if="student.averageScore !== null">{{ formatScore(student.averageScore) }}</span>
                                <span v-else class="muted">—</span>
                            </td>
                            <td class="table-actions">
                                <RouterLink
                                    :to="{ name: 'admin-student-detail', params: { id: student.id } }"
                                    class="btn btn-link btn-sm btn-icon"
                                    :title="`View ${student.displayName}`"
                                    :aria-label="`View ${student.displayName}`"
                                >
                                    <AppIcon name="eye" />
                                </RouterLink>
                                <button
                                    type="button"
                                    class="btn btn-link btn-sm btn-icon"
                                    :title="`Edit or move ${student.displayName}`"
                                    :aria-label="`Edit ${student.displayName}`"
                                    :disabled="!auth.canWrite"
                                    @click="openEdit(student)"
                                >
                                    <AppIcon name="pencil" />
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-link btn-sm btn-icon text-danger"
                                    :title="`Delete ${student.displayName}`"
                                    :aria-label="`Delete ${student.displayName}`"
                                    :disabled="!auth.canWrite"
                                    @click="deleting = student"
                                >
                                    <AppIcon name="trash" />
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <StudentFormModal
            v-model="formOpen"
            admin
            :student="editing"
            :section-id="sectionId || null"
            :sections="formSections"
            :loading-sections="loadingFormSections"
            :organizations="formOrganizations"
            :organization-id="formSchoolId || null"
            :branches="formBranchOptions"
            :branch-id="branchId || null"
            @school-change="onFormSchoolChange"
            @saved="load"
        />

        <ConfirmDialog
            :model-value="deleting !== null"
            title="Delete student"
            :busy="removing"
            @update:model-value="deleting = null"
            @confirm="confirmRemove"
        >
            Delete <strong v-if="deleting">{{ deleting.displayName }}</strong>? All of their PACE records will be deleted as well.
            This cannot be undone.
        </ConfirmDialog>
    </div>
</template>

<style scoped>
.admin-students {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.page-header {
    margin-bottom: 0;
}

.subtitle {
    margin: 4px 0 0;
}

.count-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 18px;
    height: 18px;
    padding: 0 5px;
    margin-left: 2px;
    border-radius: 999px;
    background: var(--danger);
    color: var(--on-danger);
    font-size: 0.75rem;
    font-weight: 600;
    line-height: 1;
}

.card {
    padding: 0;
    overflow: hidden;
}

.toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px 16px;
    flex-wrap: wrap;
    padding: 12px 16px;
    border-bottom: 1px solid var(--border);
}

.toolbar-controls {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    flex: 1 1 auto;
}

.toolbar-controls .input {
    width: auto;
}

.search {
    flex: 1 1 200px;
    max-width: 280px;
}

.select {
    flex: 0 1 190px;
}

.school-select {
    flex: 0 1 220px;
    min-width: 170px;
}

.select-sm {
    flex-basis: 130px;
}

.score-range {
    display: flex;
    align-items: center;
    gap: 6px;
}

.score-range-label {
    font-size: 0.9rem;
}

.toolbar-controls .score-input {
    width: 78px;
}

.toolbar-meta {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    margin-left: auto;
}

.count {
    font-size: 0.92rem;
}

.state {
    margin: 0;
    padding: 24px 16px;
    text-align: center;
}

.table-wrap {
    overflow-x: auto;
}

.table {
    min-width: 1350px;
    table-layout: fixed;
}

.col-student-id {
    width: 110px;
}

.col-grade {
    width: 180px;
}

.col-year {
    width: 110px;
}

.col-teacher {
    width: 140px;
}

.col-sex {
    width: 80px;
}

.col-age {
    width: 60px;
}

.col-birth {
    width: 120px;
}

.col-records {
    width: 150px;
}

.col-average {
    width: 120px;
}

.col-actions {
    width: 124px;
}

.table-actions .btn + .btn {
    margin-left: 2px;
}

.name {
    font-weight: 500;
}

.ellipsis {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.small {
    display: block;
    font-size: 0.8rem;
    overflow: hidden;
    text-overflow: ellipsis;
}

.records {
    line-height: 1;
}

.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    border: 0;
}
</style>
