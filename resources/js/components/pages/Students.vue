<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { SEX_OPTIONS } from '../../models';
import { useStudentsStore } from '../../stores/students';
import { useReferenceStore } from '../../stores/reference';
import { useSchoolYearsStore } from '../../stores/schoolYears';
import { useStudentChangeRequestsStore } from '../../stores/studentChangeRequests';
import { useAuthStore } from '../../stores/auth';
import { formatDate, formatScore, pluralize } from '../../core/format';
import StudentFormModal from '../students/StudentFormModal.vue';
import StudentChangeModal from '../students/StudentChangeModal.vue';
import EmptyState from '../ui/EmptyState.vue';
import AppIcon from '../ui/AppIcon.vue';
import PaceRecordsBar from '../charts/PaceRecordsBar.vue';
import PaceRecordsLegend from '../charts/PaceRecordsLegend.vue';

/** Bar segments and legend read tested / issued / assigned, left to right (same as the Grade levels page). */
const RECORD_ORDER = ['tested', 'issued', 'assigned'];

const students = useStudentsStore();
const reference = useReferenceStore();
const schoolYears = useSchoolYearsStore();
const changeRequests = useStudentChangeRequestsStore();
const auth = useAuthStore();
const route = useRoute();

/** `?school_year=` (links from the School years page) preselects the school year filter. */
const requestedYear = Number(route.query.school_year) || null;

const modalOpen = ref(false);
const changeOpen = ref(false);
const proposing = ref(null);

let searchTimer = null;

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

const hasFilters = computed(() => students.filters.search.trim() !== ''
    || students.filters.section_id !== ''
    || students.filters.school_year_id !== ''
    || sexFilter.value !== ''
    || scoreRange.value.min !== null
    || scoreRange.value.max !== null);

/** The loaded students narrowed by the sex and score-range filters (the rest are filtered by the server). */
const visibleStudents = computed(() => {
    let list = students.items;

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

/** Sections offered by the filter: the teacher's classes of the chosen school year, or all (labelled with their year). */
const sectionOptions = computed(() => reference.sectionsForYear(students.filters.school_year_id));

/** Changing the school year filter drops a section filter from another year. */
function onYearFilterChange() {
    const section = reference.sectionById(students.filters.section_id);

    if (section && students.filters.school_year_id !== '' && section.gradeLevel?.schoolYearId !== Number(students.filters.school_year_id)) {
        students.filters.section_id = '';
    }

    students.fetch();
}

const emptyMessage = computed(() => (hasFilters.value ? 'No students match your filters.' : 'No students yet.'));

function className(student) {
    return student.classLabel || reference.sectionById(student.sectionId)?.label || '—';
}

function openCreate() {
    modalOpen.value = true;
}

function openPropose(student) {
    proposing.value = student;
    changeOpen.value = true;
}

function onSaved() {
    students.fetch();
}

function clearFilters() {
    sexFilter.value = '';
    scoreMin.value = '';
    scoreMax.value = '';
    students.resetFilters();
    students.fetch();
}

watch(
    () => students.filters.search,
    () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => students.fetch(), 300);
    },
);

onMounted(() => {
    reference.load();
    schoolYears.load();
    changeRequests.fetch();
    students.fetch(requestedYear ? { school_year_id: requestedYear, section_id: '', grade_level_id: '' } : {});
});

onBeforeUnmount(() => {
    clearTimeout(searchTimer);
});
</script>

<template>
    <div class="students">
        <div class="page-header">
            <div>
                <h1>Students</h1>
                <p class="muted subtitle">The students of the sections you teach, shared with their other teachers.</p>
            </div>
            <div class="page-actions">
                <RouterLink :to="{ name: 'student-change-requests' }" class="btn btn-secondary">
                    Proposed changes
                    <span
                        v-if="changeRequests.pendingCount > 0"
                        class="count-badge"
                        :title="pluralize(changeRequests.pendingCount, 'pending proposal')"
                    >
                        {{ changeRequests.pendingCount }}
                    </span>
                </RouterLink>
                <button type="button" class="btn" :disabled="!auth.canWrite" @click="openCreate">Enroll student</button>
            </div>
        </div>

        <div class="card">
            <!-- Filters on the left; the student count and the colour key for the bars on the right. -->
            <div class="toolbar">
                <div class="toolbar-controls">
                    <input
                        v-model="students.filters.search"
                        class="input search"
                        type="search"
                        placeholder="Search by name or student ID"
                        aria-label="Search students by name or student ID"
                    />
                    <select
                        v-model="students.filters.school_year_id"
                        class="input select"
                        aria-label="Filter by school year"
                        @change="onYearFilterChange"
                    >
                        <option value="">All school years</option>
                        <option v-for="year in schoolYears.items" :key="year.id" :value="year.id">
                            {{ year.name }}{{ year.isCurrent ? ' (current)' : '' }}
                        </option>
                    </select>
                    <select
                        v-model="students.filters.section_id"
                        class="input select"
                        aria-label="Filter by section"
                        @change="students.fetch()"
                    >
                        <option value="">All my sections</option>
                        <option v-for="section in sectionOptions" :key="section.id" :value="section.id">
                            {{ students.filters.school_year_id ? section.label : section.labelWithYear }}
                        </option>
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
                        {{ students.loading ? 'Loading…' : pluralize(visibleStudents.length, 'student') }}
                    </span>
                    <PaceRecordsLegend v-if="visibleStudents.length > 0" :order="RECORD_ORDER" />
                </div>
            </div>

            <p v-if="students.loading && !students.loaded" class="muted state">Loading…</p>

            <EmptyState
                v-else-if="students.items.length === 0 && !hasFilters && reference.loaded && !reference.hasSections"
                message="You are not teaching any class yet. Your school admin assigns classes; you can also ask for an open one."
            >
                <template #action>
                    <RouterLink :to="{ name: 'teaching-requests' }" class="btn btn-secondary btn-sm">
                        Request a class
                    </RouterLink>
                </template>
            </EmptyState>

            <EmptyState v-else-if="visibleStudents.length === 0" :message="emptyMessage">
                <template v-if="!hasFilters" #action>
                    <button type="button" class="btn btn-secondary btn-sm" :disabled="!auth.canWrite" @click="openCreate">Enroll student</button>
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
                            <td class="name-cell">
                                <RouterLink :to="{ name: 'student-detail', params: { id: student.id } }" class="name">
                                    {{ student.fullName }}
                                </RouterLink>
                            </td>
                            <td class="ellipsis" :title="className(student)">{{ className(student) }}</td>
                            <td class="mono">{{ student.schoolYearName || '—' }}</td>
                            <td>{{ student.sexLabel }}</td>
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
                            <td class="mono text-right" :title="student.averageScore === null ? '' : 'Across all PACE records; untested PACEs count as 0'">
                                <span v-if="student.averageScore !== null">{{ formatScore(student.averageScore) }}</span>
                                <span v-else class="muted">—</span>
                            </td>
                            <td class="table-actions">
                                <RouterLink
                                    :to="{ name: 'student-detail', params: { id: student.id } }"
                                    class="btn btn-link btn-sm btn-icon"
                                    :title="`View ${student.displayName}`"
                                    :aria-label="`View ${student.displayName}`"
                                >
                                    <AppIcon name="eye" />
                                </RouterLink>
                                <button
                                    type="button"
                                    class="btn btn-link btn-sm btn-icon"
                                    :title="changeRequests.pendingFor(student.id) ? 'Changes already proposed; waiting for your admin' : `Propose changes to ${student.displayName}`"
                                    :aria-label="`Propose changes to ${student.displayName}`"
                                    :disabled="!auth.canWrite || Boolean(changeRequests.pendingFor(student.id))"
                                    @click="openPropose(student)"
                                >
                                    <AppIcon name="pencil" />
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <StudentFormModal v-model="modalOpen" :section-id="students.filters.section_id || null" @saved="onSaved" />

        <StudentChangeModal v-model="changeOpen" :student="proposing" />

    </div>
</template>

<style scoped>
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
    flex: 0 1 200px;
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
    min-width: 1150px;
    table-layout: fixed;
}

.col-student-id {
    width: 110px;
}

.col-grade {
    width: 170px;
}

.col-year {
    width: 110px;
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
    width: 90px;
}

.name {
    font-weight: 500;
}

.name-cell,
.ellipsis {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.records {
    line-height: 1;
}

.table-actions .btn + .btn {
    margin-left: 2px;
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
