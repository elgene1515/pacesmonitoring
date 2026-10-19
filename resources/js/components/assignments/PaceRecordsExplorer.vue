<script setup>
import { computed, ref, watch } from 'vue';
import { formatDate, formatScore, pluralize } from '../../core/format';
import { QUARTERS } from '../../core/schoolYear';
import EmptyState from '../ui/EmptyState.vue';
import PaceRecordsLegend from '../charts/PaceRecordsLegend.vue';

/**
 * Searchable, filterable table of PACE records (student, PACE, status, period,
 * dates, score). Filtering is done client-side on the list it is given.
 *
 *  props   records        PaceAssignment[]     records to show (with student and pace loaded)
 *          subjects       {id, name}[]|null    when given, adds a Subject column and filter
 *          showPace       boolean              show the PACE column (off on a PACE's own page)
 *          showGrade      boolean              show the student's grade level column
 *          yearFilter     boolean              add a school year filter (options come from the records)
 *          quarterFilter  boolean              add a quarter filter
 *          scoreFilter    boolean              add a score range filter (0-100; unscored records drop out once a bound is set)
 *          admin          boolean              link students / PACEs / subjects to the read-only admin pages
 *          order          string[]             legend order (defaults to tested / issued / assigned)
 *          title          string               card heading
 *  emits   filtered(PaceAssignment[])          the rows currently shown, whenever the filters change
 */
const props = defineProps({
    records: { type: Array, default: () => [] },
    subjects: { type: Array, default: null },
    showPace: { type: Boolean, default: true },
    showGrade: { type: Boolean, default: false },
    yearFilter: { type: Boolean, default: false },
    quarterFilter: { type: Boolean, default: false },
    scoreFilter: { type: Boolean, default: false },
    admin: { type: Boolean, default: false },
    order: { type: Array, default: () => ['tested', 'issued', 'assigned'] },
    title: { type: String, default: 'PACE records' },
    emptyMessage: { type: String, default: 'No PACE records have been filed yet.' },
});

const emit = defineEmits(['filtered']);

const STATUS_OPTIONS = [
    { value: '', label: 'All statuses' },
    { value: 'tested', label: 'Tested' },
    { value: 'issued', label: 'Issued' },
    { value: 'assigned', label: 'Assigned' },
];

const search = ref('');
const statusFilter = ref('');
const subjectFilter = ref('');
const schoolYearFilter = ref('');
const quarterFilterValue = ref('');
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

const showSubject = computed(() => Array.isArray(props.subjects));

/** Route names for the row links: the admin pages when `admin` is set, else the teacher's. */
const routes = computed(() => (props.admin
    ? { student: 'admin-student-detail', pace: 'admin-pace-detail', subject: 'admin-subject-detail' }
    : { student: 'student-detail', pace: 'pace-detail', subject: 'subject-detail' }));

/** School years present in the records, newest first. */
const yearOptions = computed(() =>
    [...new Set(props.records.map((record) => record.schoolYear).filter(Boolean))].sort((a, b) => b.localeCompare(a)),
);

const hasFilters = computed(
    () => search.value.trim() !== ''
        || statusFilter.value !== ''
        || subjectFilter.value !== ''
        || schoolYearFilter.value !== ''
        || quarterFilterValue.value !== ''
        || scoreRange.value.min !== null
        || scoreRange.value.max !== null,
);

function matchesSearch(record, needle) {
    const haystack = [
        record.student?.fullName,
        record.student?.displayName,
        record.student?.gradeLevelName,
        record.paceNumber ? `#${record.paceNumber}` : '',
        record.paceNumber,
        record.paceName,
        record.subjectName,
        record.schoolYear,
        record.quarterLabel,
        record.status,
    ]
        .filter(Boolean)
        .join(' ')
        .toLowerCase();

    return haystack.includes(needle);
}

const rows = computed(() => {
    const needle = search.value.trim().toLowerCase();
    const subjectId = subjectFilter.value === '' ? null : Number(subjectFilter.value);

    const quarter = quarterFilterValue.value === '' ? null : Number(quarterFilterValue.value);
    const { min, max } = scoreRange.value;

    return props.records.filter((record) => {
        if (statusFilter.value && record.status !== statusFilter.value) {
            return false;
        }

        if (subjectId !== null && Number(record.subjectId) !== subjectId) {
            return false;
        }

        if (schoolYearFilter.value && record.schoolYear !== schoolYearFilter.value) {
            return false;
        }

        if (quarter !== null && Number(record.quarter) !== quarter) {
            return false;
        }

        if (min !== null || max !== null) {
            const score = record.score === null || record.score === undefined ? null : Number(record.score);

            if (score === null || (min !== null && score < min) || (max !== null && score > max)) {
                return false;
            }
        }

        return needle === '' || matchesSearch(record, needle);
    });
});

/* Let the parent follow the shown rows (e.g. a chart that reflects the current filters). */
watch(rows, (value) => emit('filtered', value), { immediate: true });

const emptyText = computed(() => (hasFilters.value ? 'No PACE records match your filters.' : props.emptyMessage));

function scoreClass(record) {
    if (record.score === null || record.score === undefined) {
        return '';
    }

    return record.isPassed ? 'text-success' : 'text-danger';
}

function clearFilters() {
    search.value = '';
    statusFilter.value = '';
    subjectFilter.value = '';
    schoolYearFilter.value = '';
    quarterFilterValue.value = '';
    scoreMin.value = '';
    scoreMax.value = '';
}
</script>

<template>
    <section class="card explorer">
        <div class="explorer-header">
            <h2 class="card-title">{{ title }}</h2>
        </div>

        <!-- One row: search and filters on the left, the record count and colour key on the right. -->
        <div class="toolbar">
            <div class="tools">
                <input
                    v-model="search"
                    class="input search"
                    type="search"
                    :placeholder="showPace ? 'Search student, PACE or subject' : 'Search student, grade or subject'"
                    aria-label="Search PACE records"
                />
                <select v-if="showSubject" v-model="subjectFilter" class="input select" aria-label="Filter by subject">
                    <option value="">All subjects</option>
                    <option v-for="subject in subjects" :key="subject.id" :value="subject.id">{{ subject.name }}</option>
                </select>
                <select v-if="yearFilter" v-model="schoolYearFilter" class="input select select-year" aria-label="Filter by school year">
                    <option value="">All school years</option>
                    <option v-for="year in yearOptions" :key="year" :value="year">{{ year }}</option>
                </select>
                <select v-if="quarterFilter" v-model="quarterFilterValue" class="input select" aria-label="Filter by quarter">
                    <option value="">All quarters</option>
                    <option v-for="item in QUARTERS" :key="item.value" :value="item.value">{{ item.label }}</option>
                </select>
                <select v-model="statusFilter" class="input select" aria-label="Filter by status">
                    <option v-for="option in STATUS_OPTIONS" :key="option.value" :value="option.value">{{ option.label }}</option>
                </select>
                <div v-if="scoreFilter" class="score-range" role="group" aria-label="Filter by score">
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
                        aria-label="Minimum score"
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
                        aria-label="Maximum score"
                    />
                </div>
                <button v-if="hasFilters" type="button" class="btn btn-link btn-sm" @click="clearFilters">Clear</button>
            </div>
            <div class="toolbar-meta">
                <span class="muted count nowrap">{{ pluralize(rows.length, 'record') }}</span>
                <PaceRecordsLegend v-if="rows.length > 0" :order="order" />
            </div>
        </div>

        <EmptyState v-if="rows.length === 0" compact :message="emptyText">
            <template v-if="hasFilters" #action>
                <button type="button" class="btn btn-link btn-sm" @click="clearFilters">Show all records</button>
            </template>
        </EmptyState>

        <template v-else>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th v-if="showGrade">Grade</th>
                            <th v-if="showSubject">Subject</th>
                            <th v-if="showPace">PACE</th>
                            <th>Status</th>
                            <th>SY</th>
                            <th>Quarter</th>
                            <th>Issued</th>
                            <th>Tested</th>
                            <th class="text-right">Score</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="record in rows" :key="record.id">
                            <td>
                                <RouterLink v-if="record.student" :to="{ name: routes.student, params: { id: record.student.id } }">
                                    {{ record.student.fullName }}
                                </RouterLink>
                                <span v-else class="muted">—</span>
                            </td>
                            <td v-if="showGrade">{{ record.student?.gradeLevelName || '—' }}</td>
                            <td v-if="showSubject">
                                <RouterLink v-if="record.subjectId && routes.subject" :to="{ name: routes.subject, params: { id: record.subjectId } }">
                                    {{ record.subjectName || '—' }}
                                </RouterLink>
                                <span v-else-if="record.subjectId">{{ record.subjectName || '—' }}</span>
                                <span v-else class="muted">—</span>
                            </td>
                            <td v-if="showPace" class="nowrap">
                                <RouterLink v-if="record.pace" :to="{ name: routes.pace, params: { id: record.pace.id } }">
                                    <span class="mono">#{{ record.paceNumber }}</span>
                                    <span class="muted pace-name"> · {{ record.paceName }}</span>
                                </RouterLink>
                                <span v-else class="muted">—</span>
                            </td>
                            <td class="nowrap">
                                <span :class="['status-pill', record.status]"><span class="swatch" />{{ record.status }}</span>
                            </td>
                            <td class="mono nowrap">{{ record.schoolYear }}</td>
                            <td class="nowrap">{{ record.quarterLabel }}</td>
                            <td class="nowrap">
                                <span v-if="record.issuedDate">{{ formatDate(record.issuedDate) }}</span>
                                <span v-else class="muted">—</span>
                            </td>
                            <td class="nowrap">
                                <span v-if="record.dateTested">{{ formatDate(record.dateTested) }}</span>
                                <span v-else class="muted">—</span>
                            </td>
                            <td class="mono text-right" :class="scoreClass(record)">
                                <span v-if="record.score !== null">{{ formatScore(record.score) }}</span>
                                <span v-else class="muted">—</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
    </section>
</template>

<style scoped>
.explorer {
    padding: 0;
    overflow: hidden;
}

.explorer-header {
    padding: 12px 16px;
    border-bottom: 1px solid var(--border);
}

.explorer .card-title {
    margin: 0;
}

.toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px 16px;
    flex-wrap: wrap;
    padding: 10px 16px;
    border-bottom: 1px solid var(--border);
}

.tools {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    flex: 1 1 auto;
}

.toolbar-meta {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    margin-left: auto;
}

.tools .input {
    width: auto;
    padding-top: 4px;
    padding-bottom: 4px;
}

.search {
    flex: 1 1 200px;
    max-width: 260px;
}

.select {
    min-width: 130px;
}

.select-year {
    min-width: 150px;
}

.score-range {
    display: flex;
    align-items: center;
    gap: 6px;
}

.score-range-label {
    font-size: 0.9rem;
}

.tools .score-input {
    width: 78px;
}

.count {
    font-size: 0.92rem;
}

.table-wrap {
    overflow-x: auto;
}

.table {
    min-width: 860px;
}

.pace-name {
    font-weight: 400;
}

.status-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    text-transform: capitalize;
}

.status-pill .swatch {
    width: 8px;
    height: 8px;
    border-radius: 2px;
}

.status-pill.assigned .swatch {
    background: var(--status-assigned);
}

.status-pill.issued .swatch {
    background: var(--status-issued);
}

.status-pill.tested .swatch {
    background: var(--status-tested);
}
</style>
