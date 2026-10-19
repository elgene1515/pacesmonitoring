<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { useAuthStore } from '../../stores/auth';
import { useSchoolYearsStore } from '../../stores/schoolYears';
import { useReferenceStore } from '../../stores/reference';
import { notify } from '../../stores/notifications';
import { dashboardService, paceService } from '../../services';
import DashboardService from '../../services/DashboardService';
import { formatDate, formatScore, pluralize } from '../../core/format';
import { quarterLabel } from '../../core/schoolYear';
import StudentFormModal from '../students/StudentFormModal.vue';
import PaceFormModal from '../paces/PaceFormModal.vue';
import EmptyState from '../ui/EmptyState.vue';
import LayeredRings from '../charts/LayeredRings.vue';
import QuarterTiles from '../charts/QuarterTiles.vue';
import SexBarChart from '../charts/SexBarChart.vue';
import BarList from '../charts/BarList.vue';
import StackedBarList from '../charts/StackedBarList.vue';
import RecordList from '../charts/RecordList.vue';

const auth = useAuthStore();
const schoolYears = useSchoolYearsStore();
const reference = useReferenceStore();

const summary = ref(DashboardService.empty());
const loading = ref(true);
const studentModal = ref(false);
const paceModal = ref(false);

/** Optional grade level / subject the figures are narrowed to ('' = all). */
const gradeLevelFilter = ref('');
const subjectFilter = ref('');
/** Optional PACE the figures are narrowed to ('' = all); the options are the teacher's own PACEs. */
const paceFilter = ref('');
const paces = ref([]);

const greeting = computed(() => {
    const name = auth.user?.firstName || auth.userName;
    return name ? `Welcome, ${name}` : 'Welcome';
});

/** The school year the totals are for: the selection, else the user's current one. */
const activeYear = computed(() => summary.value.schoolYear ?? schoolYears.selected);

/** Grade levels offered by the filter: those of the selected school year, or all (labelled with their year). */
const gradeLevelOptions = computed(() => (
    schoolYears.selectedId === 'all'
        ? reference.gradeLevels
        : reference.gradeLevelsFor(activeYear.value?.id ?? '')
));

const selectedGradeLevel = computed(() => reference.gradeLevelById(gradeLevelFilter.value));

/** Subjects offered by the filter: the chosen grade level's, else every subject of the offered grade levels. */
const subjectOptions = computed(() => {
    const levels = selectedGradeLevel.value ? [selectedGradeLevel.value] : gradeLevelOptions.value;
    const single = levels.length === 1;

    return levels.flatMap((level) => level.subjects.map((subject) => ({
        id: subject.id,
        label: single ? subject.name : `${subject.name} · ${level.name}`,
    })));
});

const selectedSubjectName = computed(() => reference.subjectName(subjectFilter.value));

const selectedPace = computed(() => paces.value.find((pace) => Number(pace.id) === Number(paceFilter.value)) ?? null);

const subtitle = computed(() => {
    let base;

    if (schoolYears.selectedId === 'all') {
        base = 'All-time totals across every school year';
    } else if (activeYear.value) {
        base = `Totals for school year ${activeYear.value.name}${activeYear.value.isCurrent ? ' (current)' : ''}`;
    } else {
        return 'Your administrator has not registered a school year yet.';
    }

    const scope = [selectedGradeLevel.value?.name, selectedSubjectName.value, selectedPace.value ? `PACE ${selectedPace.value.label}` : null].filter(Boolean);

    return scope.length ? `${base} · ${scope.join(' · ')}.` : `${base}.`;
});

const tiles = computed(() => [
    { key: 'students', label: 'Students', value: summary.value.students, to: { name: 'students' } },
    { key: 'paces', label: 'PACEs', value: summary.value.paces, to: { name: 'paces' } },
    { key: 'grade-levels', label: 'Grade levels', value: summary.value.gradeLevels, to: { name: 'grade-levels' } },
]);

/** "SY 2026-2027" / "All school years", used as a suffix on each chart's title. */
const periodLabel = computed(() => {
    if (schoolYears.selectedId === 'all') {
        return 'All school years';
    }

    return activeYear.value ? `SY ${activeYear.value.name}` : 'This year';
});

const subjectBarItems = computed(() => summary.value.bySubject.map((subject) => ({
    id: subject.id,
    label: subject.name,
    sublabel: subject.averageScore === null
        ? subject.gradeLevelName
        : `${subject.gradeLevelName} · avg ${formatScore(subject.averageScore)}`,
    total: subject.total,
    tested: subject.tested,
    issued: subject.issued,
    assigned: subject.assigned,
})));

const gradeLevelBarItems = computed(() => summary.value.byGradeLevel.map((level) => ({
    id: level.id,
    label: level.name,
    total: level.total,
    male: level.male,
    female: level.female,
    to: { name: 'grade-level-detail', params: { id: level.id } },
})));

/**
 * Layered progress rings, outer to inner. Assigned / issued / tested / retakes
 * are shares of every PACE record in the period; pass rate and fails are
 * shares of the records that have a score.
 */
const progressRings = computed(() => {
    const s = summary.value;

    return [
        { key: 'assigned', label: 'Assigned', value: s.assigned, total: s.assignments, color: 'var(--status-assigned)', hint: 'Assigned but not yet issued' },
        { key: 'issued', label: 'Issued', value: s.issued, total: s.assignments, color: 'var(--status-issued)', hint: 'Issued but not yet tested' },
        { key: 'tested', label: 'Tested', value: s.tested, total: s.assignments, color: 'var(--status-tested)', hint: 'Tested PACEs' },
        { key: 'pass', label: 'Pass rate', value: s.passed, total: s.scored, color: '#7c3aed', hint: 'Scored 80 or above, out of scored PACEs' },
        { key: 'retake', label: 'Retakes', value: s.retakes, total: s.assignments, color: '#d67014', hint: 'PACEs flagged as a retake' },
        { key: 'fails', label: 'Fails', value: s.failedCount, total: s.scored, color: 'var(--danger)', hint: 'Scored below 80, out of scored PACEs' },
    ];
});

/** '' (all) | 'fails' | 'retakes': narrows the Fails and retakes list. */
const flaggedFilter = ref('');

const FLAGGED_OPTIONS = [
    { value: '', label: 'All' },
    { value: 'fails', label: 'Fails' },
    { value: 'retakes', label: 'Retakes' },
];

const failedItems = computed(() => summary.value.failed
    .filter((item) => {
        if (flaggedFilter.value === 'fails') {
            return item.failed;
        }

        if (flaggedFilter.value === 'retakes') {
            return item.retake;
        }

        return true;
    })
    .map((item) => {
        const tags = [item.failed ? 'Failed' : null, item.retake ? 'Retake' : null].filter(Boolean);

        return {
            id: item.id,
            to: { name: 'student-detail', params: { id: item.studentId } },
            primary: item.studentName,
            secondary: `${item.subjectName} · #${item.paceNumber} ${item.paceName}`,
            value: item.score === null ? '—' : formatScore(item.score),
            valueClass: item.failed ? 'danger' : '',
            caption: `${tags.join(' · ')} · ${quarterLabel(item.quarter)} · SY ${item.schoolYear}`,
        };
    }));

const flaggedEmptyMessage = computed(() => {
    if (flaggedFilter.value === 'fails') {
        return 'No failed PACEs yet.';
    }

    if (flaggedFilter.value === 'retakes') {
        return 'No PACEs flagged as retake yet.';
    }

    return 'No failed or retake PACEs yet.';
});

const topPerformerItems = computed(() => summary.value.topPerformers.map((item) => ({
    id: item.studentId,
    to: { name: 'student-detail', params: { id: item.studentId } },
    primary: item.studentName,
    secondary: `${pluralize(item.scoredCount, 'PACE')} scored · ${item.passedCount} pass · ${item.failedCount} fail · ${pluralize(item.retakeCount, 'retake')}`,
    value: formatScore(item.averageScore),
    valueClass: 'success',
    caption: 'avg score',
})));

const scoreDistributionItems = computed(() => summary.value.scoreDistribution.map((band) => ({
    id: band.label,
    label: band.label,
    value: band.total,
})));

/**
 * First-run checklist, based on all-time totals (not the selected school year): shown until
 * the user has a school year, a grade level, a PACE and a student.
 */
const steps = computed(() => {
    const totals = summary.value.totals;

    return [
        {
            key: 'school-years',
            done: totals.schoolYears > 0,
            title: 'Pick your current school year',
            detail: 'School years are set up by your school administrator; students and PACE records are filed under one.',
        },
        {
            key: 'grade-levels',
            done: totals.gradeLevels > 0,
            title: 'Create grade levels and their subjects',
            detail: 'Students are enrolled in a grade level; PACEs are assigned under its subjects.',
        },
        {
            key: 'paces',
            done: totals.paces > 0,
            title: 'Register the PACEs you issue',
            detail: 'Each PACE has a name and a number; register one at a time or as a range.',
        },
        {
            key: 'students',
            done: totals.students > 0,
            title: 'Add your students',
            detail: 'Then open a student to assign PACEs per subject, school year and quarter.',
        },
    ];
});

const setupComplete = computed(() => steps.value.every((step) => step.done));

async function load() {
    loading.value = true;

    try {
        summary.value = await dashboardService.summary(schoolYears.selectedId, {
            gradeLevelId: gradeLevelFilter.value || null,
            subjectId: subjectFilter.value || null,
            paceId: paceFilter.value || null,
        });

        // Remember which year the API settled on so the selector shows it.
        if (summary.value.schoolYear && schoolYears.selectedId === null) {
            schoolYears.select(summary.value.schoolYear.id);
        }
    } catch (err) {
        notify.error(err?.message ?? 'Failed to load the dashboard.');
    } finally {
        loading.value = false;
    }
}

function onYearChange(event) {
    schoolYears.select(event.target.value);
    // Grade levels belong to a school year, so a change clears the narrower filters.
    gradeLevelFilter.value = '';
    subjectFilter.value = '';
    load();
}

function onGradeLevelChange() {
    const level = selectedGradeLevel.value;

    // Drop a subject filter that belongs to another grade level.
    if (subjectFilter.value && (!level || !level.subjectById(subjectFilter.value))) {
        subjectFilter.value = '';
    }

    load();
}

function onSubjectChange() {
    load();
}

/** The teacher's PACEs for the PACE filter, by number. */
async function loadPaces() {
    try {
        const list = await paceService.list();

        paces.value = [...list].sort((a, b) => {
            const left = String(a.number);
            const right = String(b.number);

            return left.length - right.length || left.localeCompare(right);
        });
    } catch (err) {
        notify.error(err?.message ?? 'Failed to load PACEs.');
    }
}

function clearScope() {
    gradeLevelFilter.value = '';
    subjectFilter.value = '';
    paceFilter.value = '';
    load();
}

watch(() => schoolYears.items.length, () => {
    // A school year registered elsewhere in the session: refresh so the selector and totals agree.
    if (!loading.value) {
        load();
    }
});

onMounted(() => {
    schoolYears.load();
    reference.load();
    loadPaces();
    load();
});
</script>

<template>
    <div class="home">
        <!-- The greeting, actions and filters stay pinned under the top bar while the page scrolls. -->
        <div class="sticky-top">
            <div class="page-header">
                <div>
                    <h1>{{ greeting }}</h1>
                    <p class="muted subtitle">{{ subtitle }}</p>
                </div>
                <div class="page-actions">
                    <button type="button" class="btn" @click="studentModal = true">Add student</button>
                    <button type="button" class="btn btn-secondary" @click="paceModal = true">Register PACE</button>
                </div>
            </div>

            <!-- Filters on their own row under the greeting. -->
            <div v-if="schoolYears.hasItems || reference.hasGradeLevels || paces.length > 0" class="filters">
                <div class="filters-controls">
                    <label v-if="schoolYears.hasItems" class="year">
                        <span class="muted">School year</span>
                        <select
                            class="input"
                            aria-label="School year"
                            :value="schoolYears.selectedId === 'all' ? 'all' : (activeYear?.id ?? '')"
                            :disabled="loading"
                            @change="onYearChange"
                        >
                            <option value="all">All school years</option>
                            <option v-for="year in schoolYears.items" :key="year.id" :value="year.id">
                                {{ year.name }}{{ year.isCurrent ? ' (current)' : '' }}
                            </option>
                        </select>
                    </label>
                    <label v-if="reference.hasGradeLevels" class="year">
                        <span class="muted">Grade level</span>
                        <select v-model="gradeLevelFilter" class="input" aria-label="Grade level" :disabled="loading" @change="onGradeLevelChange">
                            <option value="">All grade levels</option>
                            <option v-for="level in gradeLevelOptions" :key="level.id" :value="level.id">
                                {{ schoolYears.selectedId === 'all' ? level.labelWithYear : level.name }}
                            </option>
                        </select>
                    </label>
                    <label v-if="reference.hasGradeLevels" class="year">
                        <span class="muted">Subject</span>
                        <select v-model="subjectFilter" class="input" aria-label="Subject" :disabled="loading || subjectOptions.length === 0" @change="onSubjectChange">
                            <option value="">All subjects</option>
                            <option v-for="subject in subjectOptions" :key="subject.id" :value="subject.id">{{ subject.label }}</option>
                        </select>
                    </label>
                    <label v-if="paces.length > 0" class="year">
                        <span class="muted">PACE</span>
                        <select v-model="paceFilter" class="input" aria-label="PACE" :disabled="loading" @change="load">
                            <option value="">All PACEs</option>
                            <option v-for="pace in paces" :key="pace.id" :value="pace.id">{{ pace.label }}</option>
                        </select>
                    </label>
                    <button v-if="gradeLevelFilter || subjectFilter || paceFilter" type="button" class="btn btn-link btn-sm" :disabled="loading" @click="clearScope">
                        Clear
                    </button>
                </div>
            </div>
        </div>

        <!-- Count tiles stacked on the left; the progress rings on the right, stretched to the same height. -->
        <div class="overview">
            <section class="stats" aria-label="Summary">
                <component
                    :is="tile.to ? RouterLink : 'div'"
                    v-for="tile in tiles"
                    :key="tile.key"
                    :to="tile.to ?? undefined"
                    class="card tile"
                    :class="{ link: tile.to }"
                >
                    <span class="tile-label muted">{{ tile.label }}</span>
                    <span class="tile-value mono">
                        <span v-if="loading" class="muted">…</span>
                        <template v-else>{{ tile.value }}</template>
                    </span>
                </component>
            </section>

            <section class="card progress">
                <h2 class="card-title">Progress · {{ periodLabel }}</h2>
                <div class="progress-body">
                    <p v-if="loading" class="muted">Loading…</p>
                    <EmptyState v-else-if="summary.assignments === 0" compact message="No PACE records in this period yet." />
                    <LayeredRings v-else :rings="progressRings" :center="summary.assignments" />
                </div>
                <p v-if="!loading && summary.assignments > 0" class="muted progress-hint">
                    Assigned, issued, tested and retakes are shares of all PACE records; pass rate and fails are shares of
                    the {{ pluralize(summary.scored, 'scored PACE') }}.
                </p>
            </section>

            <section class="card distribution">
                <h2 class="card-title">Score distribution · {{ periodLabel }}</h2>
                <div class="distribution-body">
                    <p v-if="loading" class="muted">Loading…</p>
                    <BarList v-else :items="scoreDistributionItems" color="var(--primary)" empty-message="No scored PACEs yet." />
                </div>
            </section>
        </div>

        <section v-if="!loading && !setupComplete" class="card setup" aria-label="Getting started">
            <h2 class="card-title">Getting started</h2>
            <ol class="steps">
                <li v-for="(step, index) in steps" :key="step.key" class="step" :class="{ done: step.done }">
                    <span class="step-marker mono" aria-hidden="true">{{ step.done ? '✓' : index + 1 }}</span>
                    <div class="step-body">
                        <p class="step-title">{{ step.title }}</p>
                        <p class="muted step-detail">{{ step.detail }}</p>
                    </div>
                    <div class="step-action">
                        <RouterLink v-if="step.key === 'school-years' && !step.done" :to="{ name: 'school-years' }" class="btn btn-secondary btn-sm">
                            School years
                        </RouterLink>
                        <RouterLink v-else-if="step.key === 'grade-levels' && !step.done" :to="{ name: 'grade-levels' }" class="btn btn-secondary btn-sm">
                            Grade levels
                        </RouterLink>
                        <button v-else-if="step.key === 'paces' && !step.done" type="button" class="btn btn-secondary btn-sm" @click="paceModal = true">
                            Register PACE
                        </button>
                        <button v-else-if="step.key === 'students' && !step.done" type="button" class="btn btn-secondary btn-sm" @click="studentModal = true">
                            Add student
                        </button>
                        <span v-else class="muted step-done">Done</span>
                    </div>
                </li>
            </ol>
        </section>

        <!-- Two equal cards, stretched to the same height. -->
        <div v-if="!loading" class="grid-2 pair">
            <section class="card">
                <h2 class="card-title">Top performing students · {{ periodLabel }}</h2>
                <p class="muted top-performers-hint">
                    Ranked by average score across all their PACEs (untested count as 0), among students with at least 3 scored PACEs.
                </p>
                <RecordList :items="topPerformerItems" empty-message="No student has 3 or more scored PACEs yet." />
            </section>

            <section class="card">
                <div class="flagged-header">
                    <h2 class="card-title">
                        Fails and retakes · {{ periodLabel }}
                        <span class="muted fails-note">(score below 80, or flagged as retake)</span>
                    </h2>
                    <select v-model="flaggedFilter" class="input flagged-filter" aria-label="Show fails, retakes or all">
                        <option v-for="option in FLAGGED_OPTIONS" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                </div>
                <RecordList :items="failedItems" :empty-message="flaggedEmptyMessage" />
            </section>
        </div>

        <section v-if="!loading" class="card">
            <h2 class="card-title">PACEs by quarter · {{ periodLabel }}</h2>
            <QuarterTiles :rows="summary.byQuarter" />
        </section>

        <!-- A breakdown narrowed to a single subject / grade level would be one bar, so that card is hidden. -->
        <div v-if="!loading && (!subjectFilter || !gradeLevelFilter)" :class="{ 'grid-2 pair': !subjectFilter && !gradeLevelFilter }">
            <section v-if="!subjectFilter" class="card">
                <h2 class="card-title">PACEs by subject · {{ periodLabel }}</h2>
                <StackedBarList :items="subjectBarItems" empty-message="No PACEs assigned yet." scrollable />
            </section>

            <section v-if="!gradeLevelFilter" class="card">
                <h2 class="card-title">Students by grade level · {{ periodLabel }}</h2>
                <SexBarChart :items="gradeLevelBarItems" empty-message="No grade levels yet." />
            </section>
        </div>

        <StudentFormModal v-model="studentModal" @saved="load" />
        <PaceFormModal v-model="paceModal" @saved="load" />
    </div>
</template>

<style scoped>
.home {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

/* Heading, actions and filters pinned under the top bar (like the detail pages). */
.sticky-top {
    position: sticky;
    top: var(--nav-height, 0px);
    z-index: 20;
    display: flex;
    flex-direction: column;
    gap: 12px;
    padding: 12px 0;
    background: var(--bg);
    border-bottom: 1px solid var(--border);
}

.page-header {
    margin-bottom: 0;
}

.subtitle {
    margin: 4px 0 0;
}

.filters {
    display: flex;
    align-items: center;
    gap: 8px 16px;
    flex-wrap: wrap;
}

.filters-controls {
    display: flex;
    align-items: center;
    gap: 8px 16px;
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
    min-width: 170px;
}

.overview {
    display: flex;
    align-items: stretch;
    gap: 16px;
}

/* Three count tiles stacked vertically; the progress card grows to match their combined height. */
.stats {
    display: flex;
    flex-direction: column;
    gap: 16px;
    flex: 0 0 220px;
}

.tile {
    flex: 1 1 0;
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: 6px;
    color: var(--text);
}

.progress,
.distribution {
    flex: 1 1 0;
    min-width: 0;
    display: flex;
    flex-direction: column;
}

.progress {
    flex-grow: 1.4;
}

.progress-body,
.distribution-body {
    flex: 1 1 auto;
    display: flex;
    align-items: center;
}

.progress-body > *,
.distribution-body > * {
    width: 100%;
}

.progress-body p,
.distribution-body p {
    margin: 0;
}

/* Side-by-side cards share the row's height (grid items stretch by default). */
.pair > .card {
    min-width: 0;
}

.tile.link:hover {
    text-decoration: none;
    border-color: var(--primary);
}

.tile-label {
    font-size: 0.9rem;
}

.tile-value {
    font-size: 1.8rem;
    font-weight: 600;
    line-height: 1.1;
}

.progress-hint {
    margin: 12px 0 0;
    font-size: 0.82rem;
}

.fails-note {
    font-weight: 400;
    font-size: 0.85rem;
}

.flagged-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 12px;
}

.flagged-header .card-title {
    margin-bottom: 0;
}

.flagged-filter {
    width: auto;
    min-width: 110px;
    padding-top: 4px;
    padding-bottom: 4px;
}

.top-performers-hint {
    margin: -4px 0 12px;
    font-size: 0.85rem;
}

.steps {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
}

.step {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 12px 0;
    border-top: 1px solid var(--border);
}

.step:first-child {
    border-top: none;
    padding-top: 4px;
}

.step-marker {
    flex: 0 0 28px;
    height: 28px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 999px;
    border: 1px solid var(--border);
    background: var(--bg);
    color: var(--muted);
    font-size: 0.9rem;
}

.step.done .step-marker {
    color: var(--success);
    border-color: var(--success);
    background: rgba(var(--success-rgb), 0.08);
}

.step-body {
    flex: 1;
    min-width: 0;
}

.step-title {
    margin: 0;
    font-weight: 500;
}

.step.done .step-title {
    color: var(--muted);
    text-decoration: line-through;
}

.step-detail {
    margin: 2px 0 0;
    font-size: 0.85rem;
}

.step-action {
    flex: 0 0 auto;
}

.step-done {
    font-size: 0.9rem;
}


@media (max-width: 960px) {
    /* Stack the overview: tiles in a row above the progress and distribution cards. */
    .overview {
        flex-wrap: wrap;
    }

    .stats {
        flex: 1 1 100%;
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

@media (max-width: 720px) {
    .sticky-top {
        display: contents;
    }

    .page-header {
        position: sticky;
        top: var(--nav-height, 0px);
        z-index: 20;
        padding: 12px 0;
        background: var(--bg);
        border-bottom: 1px solid var(--border);
    }

    .overview {
        flex-direction: column;
    }

    .stats {
        flex: 0 0 auto;
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

@media (max-width: 640px) {
    .step {
        flex-wrap: wrap;
    }

    .step-action {
        flex-basis: 100%;
        padding-left: 42px;
    }
}

@media (max-width: 400px) {
    .stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
</style>
