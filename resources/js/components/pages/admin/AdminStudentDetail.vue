<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { adminService } from '../../../services';
import { useAuthStore } from '../../../stores/auth';
import { notify } from '../../../stores/notifications';
import { PaceAssignment, Subject } from '../../../models';
import { formatDate, formatScore, pluralize } from '../../../core/format';
import { currentSchoolYear, QUARTERS, quarterLabel } from '../../../core/schoolYear';
import { buildRecordRings } from '../../../core/recordRings';
import AssignmentTable from '../../assignments/AssignmentTable.vue';
import StudentFormModal from '../../students/StudentFormModal.vue';
import EnrollmentHistory from '../../students/EnrollmentHistory.vue';
import StudentDetailsCard from '../../students/StudentDetailsCard.vue';
import ConfirmDialog from '../../ui/ConfirmDialog.vue';
import EmptyState from '../../ui/EmptyState.vue';
import AppIcon from '../../ui/AppIcon.vue';
import LayeredRings from '../../charts/LayeredRings.vue';

const props = defineProps({
    id: { type: Number, required: true },
});

const auth = useAuthStore();
const router = useRouter();

const student = ref(null);
const schoolYears = ref([]);

/* Edit / move / delete */
const editOpen = ref(false);
const formSections = ref([]);
const loadingFormSections = ref(false);
const deleting = ref(false);
const removing = ref(false);

/** The student's teachers, by name. */
const teacherNames = computed(() => (student.value?.teachers ?? []).map((teacher) => teacher.name).join(', '));

/** Who teaches a subject in the student's section ("" when nobody does). */
function teacherOf(subject) {
    return student.value?.section?.teacherFor(subject.id)?.name ?? '';
}

/** Every section of the school for the edit form (moving the student). */
async function openEdit() {
    editOpen.value = true;
    loadingFormSections.value = true;

    try {
        const levels = await adminService.listGradeLevels();

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

async function confirmRemove() {
    if (removing.value || !student.value) {
        return;
    }

    removing.value = true;

    try {
        await adminService.removeStudent(student.value.id);
        notify.success(`${student.value.displayName} deleted.`);
        deleting.value = false;
        router.push({ name: 'admin-students' });
    } catch (err) {
        notify.error(err?.message ?? 'Unable to delete the student.');
    } finally {
        removing.value = false;
    }
}
const assignments = ref([]);
const loading = ref(true);
const loadError = ref('');

const schoolYear = ref(currentSchoolYear());
const quarter = ref(1);

/**
 * The school year options for this student's own teacher (newest first), plus
 * any other year that already has records for this student.
 */
const yearOptions = computed(() => {
    const registered = schoolYears.value.map((year) => ({
        value: year.name,
        label: year.isCurrent ? `${year.name} (current)` : year.name,
    }));
    const known = new Set(registered.map((option) => option.value));

    const extra = [...new Set(assignments.value.map((item) => item.schoolYear).filter(Boolean))]
        .filter((year) => !known.has(year))
        .map((year) => ({ value: year, label: `${year} (not registered)` }));

    return [...registered, ...extra].sort((a, b) => b.value.localeCompare(a.value));
});

/** The school year the student's current grade level (and so its subjects) is registered under. */
/** "Main Campus · Grace Christian Academy": the platform owner looks at students of many schools, so the branch says which school it belongs to. */
const branchLabel = computed(() => [student.value?.branchName, auth.isPlatform ? student.value?.organizationName : ''].filter(Boolean).join(' · '));

const gradeLevelYear = computed(() => student.value?.gradeLevel?.schoolYear ?? null);

/** True when the selected school year is the student's current grade-level year. */
const isCurrentYear = computed(() => Boolean(gradeLevelYear.value) && gradeLevelYear.value.name === schoolYear.value);

/** True while looking at a school year the student is not currently registered in. */
const viewingHistory = computed(() => Boolean(student.value) && !isCurrentYear.value);

/**
 * Subjects shown for the selected school year: the subjects this student takes
 * for the current year, otherwise the subjects that have records in
 * that year (this is a read-only view, so there is no "live" distinction).
 */
const subjects = computed(() => {
    if (!student.value) {
        return [];
    }

    if (isCurrentYear.value) {
        return student.value.subjects ?? [];
    }

    const seen = new Map();

    for (const item of yearAssignments.value) {
        if (item.subject && !seen.has(item.subject.id)) {
            seen.set(item.subject.id, item.subject);
        }
    }

    return [...seen.values()].sort(Subject.compare);
});

const yearAssignments = computed(() => assignments.value.filter((item) => item.schoolYear === schoolYear.value));

const quarterAssignments = computed(() => yearAssignments.value.filter((item) => Number(item.quarter) === Number(quarter.value)));

const summary = computed(() => PaceAssignment.summarize(quarterAssignments.value));

/** Summary across every quarter of the selected school year (not just the active tab). */
const yearSummary = computed(() => PaceAssignment.summarize(yearAssignments.value));

/** Average score per quarter of the selected school year (untested PACEs count as 0), null for an empty quarter. */
const quarterAverages = computed(() => Object.fromEntries(QUARTERS.map((item) => [
    item.value,
    PaceAssignment.averageAll(yearAssignments.value.filter((assignment) => Number(assignment.quarter) === item.value)),
])));

/**
 * Ring chart scope: the school year selected in the PACE records filter below
 * (default; it starts on the year the student is enrolled in) or every school year.
 */
const ringScope = ref('year');

const ringAssignments = computed(() => {
    if (ringScope.value === 'all' || !schoolYear.value) {
        return assignments.value;
    }

    return yearAssignments.value;
});

/** Layered rings (assigned / issued / tested / pass / retakes / fail) for the records in the ring chart's scope. */
const recordRings = computed(() => buildRecordRings(ringAssignments.value));

/** Average score over the records in the ring chart's scope (untested PACEs count as 0). */
const overallAverage = computed(() => PaceAssignment.averageAll(ringAssignments.value));

function quarterCount(value) {
    return yearAssignments.value.filter((item) => Number(item.quarter) === Number(value)).length;
}

function comparePaceNumber(a, b) {
    const left = String(a.paceNumber);
    const right = String(b.paceNumber);
    return left.length - right.length || left.localeCompare(right) || a.id - b.id;
}

function forSubject(subject) {
    return quarterAssignments.value.filter((item) => item.subjectId === subject.id).sort(comparePaceNumber);
}

function subjectSummary(subject) {
    return PaceAssignment.summarize(forSubject(subject));
}

/**
 * PACE Projection: for each subject (row) and quarter (column) of the selected
 * school year, every PACE assigned in that cell, colored like its status badge
 * in the PACE Records table below. Hovering a number shows its full record.
 */
const projectionRows = computed(() => subjects.value.map((subject) => ({
    subject,
    cells: QUARTERS.map((item) => ({
        quarter: item.value,
        entries: yearAssignments.value
            .filter((assignment) => assignment.subjectId === subject.id && Number(assignment.quarter) === item.value)
            .sort(comparePaceNumber),
    })),
})));

function statusLabel(assignment) {
    switch (assignment.status) {
        case 'tested':
            return 'Tested';
        case 'issued':
            return 'Issued';
        default:
            return 'Assigned';
    }
}

async function load() {
    loading.value = true;
    loadError.value = '';

    try {
        const result = await adminService.getStudent(props.id);
        student.value = result.student;
        schoolYears.value = result.schoolYears;
        assignments.value = [...result.student.assignments];

        schoolYear.value = gradeLevelYear.value?.name
            ?? schoolYears.value.find((year) => year.isCurrent)?.name
            ?? currentSchoolYear();
    } catch (err) {
        student.value = null;
        loadError.value = err?.isNotFound ? 'This student could not be found.' : (err?.message ?? 'Failed to load the student.');
    } finally {
        loading.value = false;
    }
}

/** Keep the local copy in step when the shared table reports a change (it is read-only here, but stays safe). */
function onUpdated(updated) {
    const index = assignments.value.findIndex((item) => item.id === updated.id);

    if (index !== -1) {
        assignments.value.splice(index, 1, updated);
    }
}

watch(() => props.id, load);

onMounted(load);
</script>

<template>
    <div class="admin-student-detail">
        <p class="back">
            <RouterLink :to="{ name: 'admin-students' }">&larr; Students</RouterLink>
        </p>

        <p v-if="loading" class="muted">Loading…</p>

        <EmptyState v-else-if="!student" :message="loadError">
            <template #action>
                <RouterLink :to="{ name: 'admin-students' }" class="btn btn-secondary btn-sm">Back to students</RouterLink>
            </template>
        </EmptyState>

        <template v-else>
            <div class="page-header">
                <div>
                    <h1>{{ student.displayName }}</h1>
                    <p class="muted subtitle">
                        {{ student.classLabel || 'No section' }}
                        · Registered in SY {{ student.schoolYearName || gradeLevelYear?.name || '—' }}
                        <template v-if="branchLabel">· {{ branchLabel }}</template>
                        · {{ pluralize(assignments.length, 'PACE record') }}
                    </p>
                </div>
                <div class="page-actions">
                    <button type="button" class="btn btn-secondary" :disabled="!auth.canWrite" @click="openEdit">Edit or move</button>
                    <button type="button" class="btn btn-secondary text-danger" :disabled="!auth.canWrite" @click="deleting = true">Delete</button>
                </div>
            </div>

            <!-- Student information on the left; the records ring chart on the right, stretched to the same height. -->
            <div class="info-row">
                <section class="card info-card">
                    <h2 class="card-title">Student information</h2>
                    <dl class="info">
                        <div class="info-item">
                            <dt>Student ID number</dt>
                            <dd class="mono">{{ student.studentNumber || '—' }}</dd>
                        </div>
                        <div class="info-item">
                            <dt>Full name</dt>
                            <dd>{{ student.fullName }}</dd>
                        </div>
                        <div class="info-item">
                            <dt>First name</dt>
                            <dd>{{ student.firstName || '—' }}</dd>
                        </div>
                        <div class="info-item">
                            <dt>Middle name</dt>
                            <dd>{{ student.middleName || '—' }}</dd>
                        </div>
                        <div class="info-item">
                            <dt>Last name</dt>
                            <dd>{{ student.lastName || '—' }}</dd>
                        </div>
                        <div class="info-item">
                            <dt>Extension</dt>
                            <dd>{{ student.extension || '—' }}</dd>
                        </div>
                        <div class="info-item">
                            <dt>Date of birth</dt>
                            <dd>{{ formatDate(student.birthDate) || '—' }}</dd>
                        </div>
                        <div class="info-item">
                            <dt>Age</dt>
                            <dd>{{ student.age ?? '—' }}</dd>
                        </div>
                        <div class="info-item">
                            <dt>Sex</dt>
                            <dd>{{ student.sexLabel || '—' }}</dd>
                        </div>
                        <div class="info-item">
                            <dt>Grade level</dt>
                            <dd>{{ student.gradeLevelName || '—' }}</dd>
                        </div>
                        <div class="info-item">
                            <dt>School year</dt>
                            <dd class="mono">{{ student.schoolYearName || gradeLevelYear?.name || '—' }}</dd>
                        </div>
                        <div class="info-item">
                            <dt>Section</dt>
                            <dd>
                                <RouterLink v-if="student.sectionId" :to="{ name: 'admin-section-detail', params: { id: student.sectionId } }">
                                    {{ student.sectionName || 'Section' }}
                                </RouterLink>
                                <span v-else>—</span>
                            </dd>
                        </div>
                        <div class="info-item">
                            <dt>Branch</dt>
                            <dd>{{ student.branchName || '—' }}</dd>
                        </div>
                        <div class="info-item">
                            <dt>Teachers</dt>
                            <dd>{{ teacherNames || '—' }}</dd>
                        </div>
                        <div class="info-item info-wide">
                            <dt>Subjects taken</dt>
                            <dd>{{ student.subjectNames || '—' }}</dd>
                        </div>
                        <div class="info-item">
                            <dt>Registered by</dt>
                            <dd>
                                <RouterLink v-if="student.registeredBy" :to="{ name: 'admin-user-detail', params: { id: student.registeredBy.id } }">
                                    {{ student.registeredByName }}
                                </RouterLink>
                                <span v-else>—</span>
                            </dd>
                        </div>
                        <div class="info-item">
                            <dt>Registered</dt>
                            <dd>{{ formatDate(student.createdAt) || '—' }}</dd>
                        </div>
                    </dl>
                </section>

                <section class="card rings-card">
                    <div class="rings-header">
                        <h2 class="card-title">PACE records</h2>
                        <div v-if="schoolYear" class="scope-toggle" role="group" aria-label="Ring chart scope">
                            <button
                                type="button"
                                class="scope-option"
                                :class="{ active: ringScope === 'year' }"
                                :aria-pressed="ringScope === 'year'"
                                :title="`Records filed in SY ${schoolYear} (the school year selected in PACE Records below)`"
                                @click="ringScope = 'year'"
                            >
                                SY {{ schoolYear }}
                            </button>
                            <button
                                type="button"
                                class="scope-option"
                                :class="{ active: ringScope === 'all' }"
                                :aria-pressed="ringScope === 'all'"
                                title="Records from every school year"
                                @click="ringScope = 'all'"
                            >
                                All years
                            </button>
                        </div>
                    </div>
                    <div class="rings-body">
                        <EmptyState v-if="ringAssignments.length === 0" compact message="No PACE records in this scope yet." />
                        <LayeredRings v-else :rings="recordRings" :center="ringAssignments.length" :size="176" :stroke-width="8" />
                    </div>
                    <p class="muted rings-caption">
                        {{ ringScope === 'all' || !schoolYear ? 'All school years' : `SY ${schoolYear}` }} · average score
                        <span class="mono">{{ overallAverage === null ? '—' : formatScore(overallAverage) }}</span>
                        <template v-if="overallAverage !== null"> (untested PACEs count as 0)</template>
                    </p>
                </section>
            </div>

            <StudentDetailsCard :student="student" />

            <EnrollmentHistory :enrollments="student.enrollments" :show-branch="(auth.organization?.branchesCount ?? 0) > 1 || auth.isPlatform" />

            <section class="card projection">
                <h2 class="card-title">PACE Projection · SY {{ schoolYear }}</h2>

                <p class="muted projection-summary">
                    <template v-if="yearSummary.total === 0">
                        No PACEs assigned yet for SY {{ schoolYear }}.
                    </template>
                    <template v-else>
                        {{ pluralize(yearSummary.total, 'PACE') }} this year
                        · {{ yearSummary.tested }} tested
                        · average score
                        <span class="mono" title="Across all PACEs this year; untested PACEs count as 0">
                            {{ yearSummary.averageAll === null ? '—' : formatScore(yearSummary.averageAll) }}
                        </span>
                    </template>
                </p>

                <EmptyState v-if="subjects.length === 0" compact message="No subjects to project PACEs for." />

                <div v-else class="table-wrap">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Subject</th>
                                <th v-for="item in QUARTERS" :key="item.value">
                                    {{ item.label }}
                                    <span
                                        v-if="quarterAverages[item.value] !== null"
                                        class="quarter-avg mono"
                                        title="Average score for this quarter; untested PACEs count as 0"
                                    >
                                        · avg {{ formatScore(quarterAverages[item.value]) }}
                                    </span>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in projectionRows" :key="row.subject.id">
                                <td>{{ row.subject.name }}</td>
                                <td v-for="cell in row.cells" :key="cell.quarter" class="mono projection-cell">
                                    <template v-if="cell.entries.length">
                                        <span v-for="(entry, index) in cell.entries" :key="entry.id" class="proj-entry">
                                            <span class="proj-number" :class="[`status-text-${entry.status}`, { retake: entry.retake }]" tabindex="0">
                                                {{ entry.paceNumber }}<span v-if="entry.retake" class="proj-retake" aria-hidden="true">↻</span>
                                                <span class="proj-tooltip" role="tooltip">
                                                    <strong>#{{ entry.paceNumber }} · {{ entry.paceName || '—' }}</strong>
                                                    <span>Issued: {{ formatDate(entry.issuedDate) || '—' }}</span>
                                                    <span>Tested: {{ formatDate(entry.dateTested) || '—' }}</span>
                                                    <span>Status: {{ statusLabel(entry) }}</span>
                                                    <span>Score: {{ entry.score === null ? '—' : formatScore(entry.score) }}</span>
                                                    <span :class="{ 'retake-flag': entry.retake }">Retake: {{ entry.retake ? 'Yes' : 'No' }}</span>
                                                </span>
                                            </span><template v-if="index < cell.entries.length - 1">, </template>
                                        </span>
                                    </template>
                                    <span v-else class="muted">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="records">
                <div class="records-header">
                    <h2>PACE Records</h2>
                    <div class="records-actions">
                        <label class="year">
                            <span class="muted">School year</span>
                            <select v-model="schoolYear" class="input" aria-label="School year">
                                <option v-if="yearOptions.length === 0" :value="schoolYear">{{ schoolYear }}</option>
                                <option v-for="option in yearOptions" :key="option.value" :value="option.value">
                                    {{ option.label }}
                                </option>
                            </select>
                        </label>
                    </div>
                </div>

                <p v-if="viewingHistory" class="notice">
                    Viewing SY {{ schoolYear }}, read-only.
                </p>

                <div class="tabs" role="tablist" aria-label="Quarter">
                    <button
                        v-for="item in QUARTERS"
                        :key="item.value"
                        type="button"
                        role="tab"
                        class="tab"
                        :class="{ active: quarter === item.value }"
                        :aria-selected="quarter === item.value"
                        @click="quarter = item.value"
                    >
                        {{ item.label }}
                        <span v-if="quarterCount(item.value)" class="tab-count mono">{{ quarterCount(item.value) }}</span>
                    </button>
                </div>

                <p class="summary muted">
                    <template v-if="summary.total === 0">
                        No PACEs assigned for the {{ quarterLabel(quarter) }} of SY {{ schoolYear }}.
                    </template>
                    <template v-else>
                        {{ quarterLabel(quarter) }} · {{ pluralize(summary.total, 'PACE') }} assigned
                        · {{ summary.tested }} tested
                        · average score
                        <span class="mono" title="Across all PACEs this quarter; untested PACEs count as 0">
                            {{ summary.averageAll === null ? '—' : formatScore(summary.averageAll) }}
                        </span>
                    </template>
                </p>

                <EmptyState v-if="subjects.length === 0" message="No PACE records for this school year." />

                <div v-else class="subjects">
                    <section v-for="subject in subjects" :key="subject.id" class="card subject">
                        <div class="subject-header">
                            <div>
                                <h3>
                                    <RouterLink :to="{ name: 'admin-subject-detail', params: { id: subject.id } }" :title="`View ${subject.name}`">{{ subject.name }}</RouterLink>
                                </h3>
                                <p class="muted subject-meta">
                                    <template v-if="isCurrentYear">{{ teacherOf(subject) ? `Taught by ${teacherOf(subject)}` : 'No teacher yet' }} · </template>
                                    <template v-if="subjectSummary(subject).total === 0">No PACEs assigned</template>
                                    <template v-else>
                                        {{ pluralize(subjectSummary(subject).total, 'PACE') }}
                                        · {{ subjectSummary(subject).tested }} tested
                                        <template v-if="subjectSummary(subject).averageAll !== null">
                                            · avg
                                            <span class="mono" title="Across all PACEs; untested PACEs count as 0">
                                                {{ formatScore(subjectSummary(subject).averageAll) }}
                                            </span>
                                        </template>
                                    </template>
                                </p>
                            </div>
                            <RouterLink
                                :to="{ name: 'admin-subject-detail', params: { id: subject.id } }"
                                class="btn btn-link btn-sm btn-icon"
                                :title="`View ${subject.name}`"
                                :aria-label="`View ${subject.name}`"
                            >
                                <AppIcon name="eye" />
                            </RouterLink>
                        </div>

                        <AssignmentTable
                            :assignments="forSubject(subject)"
                            readonly
                            pace-route="admin-pace-detail"
                            empty-message="No PACEs assigned"
                            @updated="onUpdated"
                        />
                    </section>
                </div>
            </section>

            <StudentFormModal
                v-model="editOpen"
                admin
                :student="student"
                :sections="formSections"
                :loading-sections="loadingFormSections"
                @saved="load"
            />

            <ConfirmDialog
                :model-value="deleting"
                title="Delete student"
                :busy="removing"
                @update:model-value="deleting = false"
                @confirm="confirmRemove"
            >
                Delete <strong>{{ student.displayName }}</strong>? All of their PACE records will be deleted as well.
                This cannot be undone.
            </ConfirmDialog>
        </template>
    </div>
</template>

<style scoped>
.admin-student-detail {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.back {
    margin: 0;
    font-size: 0.92rem;
}

.page-header {
    position: sticky;
    top: var(--nav-height, 0px);
    z-index: 20;
    padding: 12px 0;
    margin-bottom: 0;
    background: var(--bg);
    border-bottom: 1px solid var(--border);
}

.subtitle {
    margin: 4px 0 0;
}

/* Information card and ring chart card side by side, stretched to the same height. */
.info-row {
    display: flex;
    align-items: stretch;
    gap: 16px;
    flex-wrap: wrap;
}

.info-card {
    flex: 1 1 520px;
    min-width: 0;
}

.rings-card {
    flex: 0 1 460px;
    display: flex;
    flex-direction: column;
}

.rings-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    flex-wrap: wrap;
}

.rings-header .card-title {
    margin-bottom: 0;
}

.scope-toggle {
    display: inline-flex;
    border: 1px solid var(--border);
    border-radius: 999px;
    overflow: hidden;
}

.scope-option {
    padding: 2px 10px;
    border: none;
    background: var(--surface);
    color: var(--muted);
    font-size: 0.8rem;
    line-height: 1.6;
    cursor: pointer;
    white-space: nowrap;
}

.scope-option + .scope-option {
    border-left: 1px solid var(--border);
}

.scope-option:hover {
    color: var(--text);
}

.scope-option.active {
    background: var(--primary);
    color: var(--on-primary);
}

.scope-option:focus-visible {
    outline: 2px solid var(--primary);
    outline-offset: -2px;
}

.rings-body {
    flex: 1 1 auto;
    display: flex;
    align-items: center;
    margin-top: 12px;
}

.rings-caption {
    margin: 12px 0 0;
    font-size: 0.85rem;
}

dl.info {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 14px 24px;
    margin: 0;
}

.info-item dt {
    font-size: 0.85rem;
    color: var(--muted);
    margin-bottom: 2px;
}

.info-item dd {
    margin: 0;
}

.info-wide {
    grid-column: 1 / -1;
}

.projection-summary {
    margin: -6px 0 12px;
    font-size: 0.92rem;
}

.projection .table-wrap {
    /* Visible, not auto: an "auto" overflow here would also clip the
       absolutely-positioned tooltip below, since a container can't scroll
       one axis without clipping the other. The table is narrow enough
       (5 columns) that horizontal scrolling isn't needed anyway. */
    overflow: visible;
}

.projection-cell {
    white-space: nowrap;
}

.quarter-avg {
    font-weight: 400;
    color: var(--muted);
    white-space: nowrap;
}

/* Phones: the projection scrolls sideways (hover tooltips are not usable on touch anyway) and the quarter average drops under its name. */
@media (max-width: 720px) {
    .projection .table-wrap {
        overflow-x: auto;
    }

    .quarter-avg {
        display: block;
    }
}

.proj-entry {
    display: inline-block;
}

.proj-number {
    position: relative;
    cursor: default;
}

.status-text-assigned {
    color: var(--muted);
}

.status-text-issued {
    color: var(--primary);
}

.status-text-tested {
    color: var(--success);
}

/* Small "redo" mark after a PACE number flagged as a retake. */
.proj-retake {
    margin-left: 2px;
    font-size: 0.8em;
    color: var(--warn-text);
}

.retake-flag {
    color: var(--warn-text);
    font-weight: 500;
}

.proj-tooltip {
    position: absolute;
    bottom: calc(100% + 8px);
    left: 50%;
    transform: translateX(-50%);
    z-index: 20;
    display: flex;
    flex-direction: column;
    gap: 2px;
    width: 220px;
    padding: 10px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--surface);
    box-shadow: var(--shadow);
    color: var(--text);
    font-size: 0.8rem;
    font-weight: 400;
    text-align: left;
    white-space: normal;
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
    transition: opacity 0.12s ease;
}

.proj-tooltip strong {
    margin-bottom: 2px;
}

.proj-number:hover .proj-tooltip,
.proj-number:focus-visible .proj-tooltip {
    opacity: 1;
    visibility: visible;
}

.records {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.records-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
}

.records-actions {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}

.year {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.92rem;
}

.year .input {
    width: auto;
    min-width: 180px;
}

.tabs {
    display: flex;
    gap: 4px;
    border-bottom: 1px solid var(--border);
    overflow-x: auto;
}

.tab {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    margin-bottom: -1px;
    border: none;
    border-bottom: 2px solid transparent;
    background: transparent;
    color: var(--muted);
    cursor: pointer;
    white-space: nowrap;
}

.tab:hover {
    color: var(--text);
}

.tab.active {
    color: var(--primary);
    border-bottom-color: var(--primary);
    font-weight: 500;
}

.tab:focus-visible {
    outline: 2px solid var(--primary);
    outline-offset: -2px;
    border-radius: 4px;
}

.tab-count {
    font-size: 0.8rem;
    padding: 0 6px;
    border-radius: 999px;
    background: var(--bg);
    border: 1px solid var(--border);
    color: var(--muted);
}

.tab.active .tab-count {
    color: var(--primary);
    border-color: rgba(var(--primary-rgb), 0.35);
}

.summary {
    margin: 0;
    font-size: 0.92rem;
}

.notice {
    margin: 0;
    padding: 10px 14px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--bg);
    color: var(--muted);
    font-size: 0.92rem;
}

.subjects {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.subject {
    padding: 0;
    overflow: hidden;
}

.subject-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 12px 16px;
    border-bottom: 1px solid var(--border);
}

.subject-meta {
    margin: 2px 0 0;
    font-size: 0.85rem;
}
</style>
