<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { studentService, paceService, assignmentService } from '../../services';
import { useReferenceStore } from '../../stores/reference';
import { useSchoolYearsStore } from '../../stores/schoolYears';
import { notify } from '../../stores/notifications';
import { PaceAssignment, Subject } from '../../models';
import { formatDate, formatScore, pluralize } from '../../core/format';
import { currentSchoolYear, QUARTERS, quarterLabel } from '../../core/schoolYear';
import StudentFormModal from '../students/StudentFormModal.vue';
import AssignPacesModal from '../assignments/AssignPacesModal.vue';
import AssignmentTable from '../assignments/AssignmentTable.vue';
import EmptyState from '../ui/EmptyState.vue';
import ConfirmDialog from '../ui/ConfirmDialog.vue';
import LayeredRings from '../charts/LayeredRings.vue';
import { buildRecordRings } from '../../core/recordRings';

const props = defineProps({
    id: { type: Number, required: true },
});

const reference = useReferenceStore();
const schoolYears = useSchoolYearsStore();

const student = ref(null);
const assignments = ref([]);
const paces = ref([]);
const loading = ref(true);
const loadError = ref('');

const schoolYear = ref(currentSchoolYear());
const quarter = ref(1);

const editOpen = ref(false);
const assignOpen = ref(false);
const assignSubject = ref(null);
const autoAssigning = ref(false);

/**
 * The user's registered school years (newest first), plus any other year that
 * already has records for this student (shown so old records stay reachable).
 */
const yearOptions = computed(() => {
    const registered = schoolYears.items.map((year) => ({
        value: year.name,
        label: year.isCurrent ? `${year.name} (current)` : year.name,
        registered: true,
    }));
    const known = new Set(registered.map((option) => option.value));

    const extra = [...new Set(assignments.value.map((item) => item.schoolYear).filter(Boolean))]
        .filter((year) => !known.has(year))
        .map((year) => ({ value: year, label: `${year} (not registered)`, registered: false }));

    return [...registered, ...extra].sort((a, b) => b.value.localeCompare(a.value));
});

/**
 * The school year the student's grade level (and so its subjects) is registered under.
 * PACEs can only be assigned when the selected school year is this one.
 */
const gradeLevelYear = computed(() => {
    const level = student.value?.gradeLevel;

    if (!level) {
        return null;
    }

    return level.schoolYear ?? schoolYears.byId(level.schoolYearId) ?? null;
});

const canAssign = computed(() => Boolean(gradeLevelYear.value) && gradeLevelYear.value.name === schoolYear.value);

/** Start on the grade level's school year (falling back to the user's current one). */
watch(
    [gradeLevelYear, () => schoolYears.current],
    ([levelYear, current]) => {
        const target = levelYear ?? current;

        if (target && !schoolYears.items.some((year) => year.name === schoolYear.value)) {
            schoolYear.value = target.name;
        }
    },
    { immediate: true },
);

watch(gradeLevelYear, (levelYear) => {
    if (levelYear) {
        schoolYear.value = levelYear.name;
    }
});

/**
 * The grade level the student was in during the selected school year.
 *
 * In the current school year that is the student's grade level. For an earlier
 * (or later) school year it is derived from that year's PACE records, whose
 * subjects belong to the grade level the student had back then, so a student
 * promoted from Grade 1 (2026-2027) to Grade 2 (2027-2028) is one record.
 */
const yearGradeLevel = computed(() => {
    if (!student.value) {
        return null;
    }

    if (canAssign.value) {
        return student.value.gradeLevel ?? reference.gradeLevelById(student.value.gradeLevelId);
    }

    const counts = new Map();

    for (const item of yearAssignments.value) {
        const id = item.subject?.gradeLevelId;

        if (id) {
            counts.set(id, (counts.get(id) ?? 0) + 1);
        }
    }

    let bestId = null;
    let best = 0;

    for (const [id, count] of counts) {
        if (count > best) {
            best = count;
            bestId = id;
        }
    }

    return bestId === null ? null : reference.gradeLevelById(bestId);
});

/** Name of the grade level for the selected school year ("" when unknown). */
const yearGradeLevelName = computed(() => yearGradeLevel.value?.name ?? '');

/**
 * Subjects shown for the selected school year: the current grade level's subjects
 * when assigning is possible, otherwise the subjects that have records in that
 * year (viewing only).
 */
const subjects = computed(() => {
    if (!student.value) {
        return [];
    }

    if (canAssign.value) {
        const loaded = student.value.gradeLevel?.subjects ?? [];
        return loaded.length ? loaded : reference.subjectsFor(student.value.gradeLevelId);
    }

    const seen = new Map();

    for (const item of yearAssignments.value) {
        if (item.subject && !seen.has(item.subject.id)) {
            seen.set(item.subject.id, item.subject);
        }
    }

    return [...seen.values()].sort(Subject.compare);
});

/** True while looking at a school year the student is not currently registered in. */
const viewingHistory = computed(() => Boolean(student.value) && !canAssign.value);

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

function assignedPaceIds(subject) {
    return forSubject(subject).map((item) => item.paceId).filter(Boolean);
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

/**
 * Auto-assign fills the 2nd to 4th quarter from the 1st quarter in one go. It is
 * available in a school year where assigning is allowed, once the 1st quarter
 * has at least one PACE.
 */
const canAutoAssign = computed(() => canAssign.value && quarterCount(1) > 0);

const AUTO_QUARTERS = [2, 3, 4];

function paceNameOf(item) {
    return item.paceName || paces.value.find((pace) => pace.id === item.paceId)?.name || null;
}

function highestPaceNumber(items) {
    return Math.max(...items.map((item) => Number(item.paceNumber)));
}

/**
 * For every subject that has PACEs in the 1st quarter, work out the next
 * consecutive PACE numbers for the 2nd, 3rd and 4th quarter: same count as the
 * 1st quarter and the same PACE name, each quarter continuing after the
 * previous one (1013-1015 in Q1 gives 1016-1018 in Q2, 1019-1021 in Q3, ...).
 * A quarter that already has PACEs for the subject is left alone.
 */
function buildAutoPlan() {
    const plan = [];
    const skipped = [];

    for (const subject of subjects.value) {
        const subjectItems = yearAssignments.value.filter((item) => item.subjectId === subject.id);
        const first = subjectItems.filter((item) => Number(item.quarter) === 1).sort(comparePaceNumber);

        if (first.length === 0) {
            continue;
        }

        const name = paceNameOf(first[0]);
        let last = highestPaceNumber(first);

        if (!Number.isFinite(last) || !name) {
            skipped.push(`${subject.name}: PACE numbers are not numeric`);
            continue;
        }

        for (const target of AUTO_QUARTERS) {
            const existing = subjectItems.filter((item) => Number(item.quarter) === target);

            if (existing.length > 0) {
                skipped.push(`${subject.name} ${quarterLabel(target)}: already has PACEs`);
                last = Math.max(last, highestPaceNumber(existing));
                continue;
            }

            const found = [];

            for (let i = 1; i <= first.length; i++) {
                const number = String(last + i);
                const pace = paces.value.find((p) => p.name === name && String(p.number) === number);

                if (!pace) {
                    skipped.push(`${subject.name} ${quarterLabel(target)}: "${name} ${number}" is not registered`);
                    break;
                }

                found.push(pace);
            }

            if (found.length < first.length) {
                // Later quarters would no longer be consecutive, so stop this subject.
                break;
            }

            plan.push({ subject, quarter: target, paces: found });
            last += first.length;
        }
    }

    return { plan, skipped };
}

const autoAssignOpen = ref(false);
const autoAssignPreview = ref(null); // { plan, skipped } while the confirm dialog is open

const autoAssignLines = computed(() => (autoAssignPreview.value?.plan ?? []).map(
    ({ subject, quarter: target, paces: list }) =>
        `${subject.name} · ${quarterLabel(target)}: ${list.map((p) => p.number).join(', ')}`,
));

function autoAssign() {
    const { plan, skipped } = buildAutoPlan();

    if (plan.length === 0) {
        notify.error(skipped.join('\n') || 'Nothing to auto-assign.');
        return;
    }

    autoAssignPreview.value = { plan, skipped };
    autoAssignOpen.value = true;
}

async function confirmAutoAssign() {
    const { plan, skipped } = autoAssignPreview.value;

    autoAssigning.value = true;

    try {
        for (const { subject, quarter: target, paces: list } of plan) {
            const created = await assignmentService.assign(student.value.id, {
                subject_id: subject.id,
                pace_ids: list.map((p) => p.id),
                school_year: schoolYear.value,
                quarter: target,
            });

            onAssigned(created);
        }

        autoAssignOpen.value = false;
        notify.success('PACEs auto-assigned.');

        if (skipped.length) {
            notify.error(`Skipped:\n${skipped.join('\n')}`);
        }
    } catch (err) {
        notify.error(err?.message ?? 'Auto-assign failed.');
    } finally {
        autoAssigning.value = false;
    }
}

async function loadPaces() {
    try {
        paces.value = await paceService.list();
    } catch (err) {
        notify.error(err?.message ?? 'Failed to load PACEs.');
    }
}

async function load() {
    loading.value = true;
    loadError.value = '';

    try {
        const record = await studentService.get(props.id);
        student.value = record;
        assignments.value = [...record.assignments];
    } catch (err) {
        student.value = null;
        assignments.value = [];
        loadError.value = err?.isNotFound ? 'This student could not be found.' : (err?.message ?? 'Failed to load the student.');
    } finally {
        loading.value = false;
    }
}

function openAssign(subject) {
    assignSubject.value = subject;
    assignOpen.value = true;
}

/*
 * Issuing, un-issuing and deleting records move booklets in and out of the
 * PACEs' stock, so the PACE list (shown in the assign modal) is refreshed after
 * every change.
 */
function onAssigned(created) {
    assignments.value = [...assignments.value, ...created];
    loadPaces();
}

function onUpdated(updated) {
    const index = assignments.value.findIndex((item) => item.id === updated.id);

    if (index === -1) {
        assignments.value = [...assignments.value, updated];
    } else {
        assignments.value.splice(index, 1, updated);
    }

    loadPaces();
}

function onRemoved(removed) {
    assignments.value = assignments.value.filter((item) => item.id !== removed.id);
    loadPaces();
}

function onStudentSaved() {
    load();
}

watch(() => props.id, load);

onMounted(() => {
    reference.load();
    schoolYears.load();
    load();
    loadPaces();
});
</script>

<template>
    <div class="student-detail">
        <p class="back">
            <RouterLink :to="{ name: 'students' }">&larr; Students</RouterLink>
        </p>

        <p v-if="loading" class="muted">Loading…</p>

        <EmptyState v-else-if="!student" :message="loadError">
            <template #action>
                <RouterLink :to="{ name: 'students' }" class="btn btn-secondary btn-sm">Back to students</RouterLink>
            </template>
        </EmptyState>

        <template v-else>
            <div class="page-header">
                <div>
                    <h1>{{ student.displayName }}</h1>
                    <p class="muted subtitle">
                        {{ student.gradeLevelName || reference.gradeLevelName(student.gradeLevelId) || 'No grade level' }}
                        · Registered in SY {{ student.schoolYearName || gradeLevelYear?.name || '—' }}
                        · {{ pluralize(assignments.length, 'PACE record') }}
                    </p>
                </div>
                <div class="page-actions">
                    <button type="button" class="btn btn-secondary" @click="editOpen = true">Edit</button>
                </div>
            </div>

            <!-- Student information on the left; the records ring chart on the right, stretched to the same height. -->
            <div class="info-row">
            <section class="card info-card">
                <h2 class="card-title">Student information</h2>
                <dl class="info">
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
                        <dd>{{ student.gradeLevelName || reference.gradeLevelName(student.gradeLevelId) || '—' }}</dd>
                    </div>
                    <div class="info-item">
                        <dt>School year</dt>
                        <dd class="mono">{{ student.schoolYearName || gradeLevelYear?.name || '—' }}</dd>
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
                        <button
                            v-if="!viewingHistory"
                            type="button"
                            class="btn btn-secondary btn-sm"
                            :disabled="!canAutoAssign || autoAssigning"
                            :title="canAutoAssign ? '' : 'Assign PACEs in the 1st quarter first'"
                            @click="autoAssign"
                        >
                            {{ autoAssigning ? 'Assigning…' : 'Auto-assign 2nd to 4th quarter' }}
                        </button>

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

                <p v-if="schoolYears.loaded && !canAssign" class="notice">
                    <template v-if="!gradeLevelYear">
                        This student's grade level is not registered under a school year, so PACEs cannot be assigned.
                        <RouterLink :to="{ name: 'grade-levels' }">Manage grade levels</RouterLink>
                    </template>
                    <template v-else>
                        Viewing SY {{ schoolYear }}<template v-if="yearGradeLevelName"> · {{ student.firstName }} was in {{ yearGradeLevelName }}</template>.
                        Records here are read-only; PACEs can only be assigned in SY {{ gradeLevelYear.name }},
                        where {{ student.firstName }} is currently registered in {{ student.gradeLevelName }}.
                        <button type="button" class="btn btn-link btn-sm" @click="schoolYear = gradeLevelYear.name">
                            Switch to SY {{ gradeLevelYear.name }}
                        </button>
                    </template>
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
                        No PACEs assigned for the {{ quarterLabel(quarter) }} of SY {{ schoolYear }} yet.
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

                <EmptyState
                    v-if="viewingHistory && subjects.length === 0"
                    :message="`No record. ${student.firstName} has no PACE records in SY ${schoolYear}.`"
                />

                <EmptyState v-else-if="subjects.length === 0" message="This grade level has no subjects yet, so no PACEs can be assigned.">
                    <template #action>
                        <RouterLink :to="{ name: 'grade-levels' }" class="btn btn-secondary btn-sm">Add subjects</RouterLink>
                    </template>
                </EmptyState>

                <div v-else class="subjects">
                    <section v-for="subject in subjects" :key="subject.id" class="card subject">
                        <div class="subject-header">
                            <div>
                                <h3>
                                    {{ subject.name }}
                                    <span v-if="viewingHistory && yearGradeLevelName" class="muted subject-level">{{ yearGradeLevelName }}</span>
                                </h3>
                                <p class="muted subject-meta">
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
                            <button
                                v-if="!viewingHistory"
                                type="button"
                                class="btn btn-secondary btn-sm"
                                :disabled="!canAssign"
                                :title="canAssign ? '' : 'Select a registered school year first'"
                                @click="openAssign(subject)"
                            >
                                Assign PACEs
                            </button>
                        </div>

                        <AssignmentTable
                            :assignments="forSubject(subject)"
                            :paces="paces"
                            :readonly="viewingHistory"
                            empty-message="No PACEs assigned"
                            @updated="onUpdated"
                            @removed="onRemoved"
                        />
                    </section>
                </div>
            </section>

            <StudentFormModal v-model="editOpen" :student="student" @saved="onStudentSaved" />

            <AssignPacesModal
                v-model="assignOpen"
                :student="student"
                :subject="assignSubject"
                :school-year="schoolYear"
                :quarter="quarter"
                :paces="paces"
                :assigned-pace-ids="assignSubject ? assignedPaceIds(assignSubject) : []"
                @assigned="onAssigned"
            />

            <ConfirmDialog
                v-model="autoAssignOpen"
                title="Auto-assign PACEs"
                confirm-text="Assign"
                :danger="false"
                :busy="autoAssigning"
                @confirm="confirmAutoAssign"
            >
                <p>This will assign the following PACEs:</p>
                <ul class="auto-assign-list">
                    <li v-for="line in autoAssignLines" :key="line">{{ line }}</li>
                </ul>
            </ConfirmDialog>
        </template>
    </div>
</template>

<style scoped>
.student-detail {
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

/* Segmented control switching the ring chart between the enrolled year and all years. */
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
    border: 1px solid rgba(var(--danger-rgb), 0.35);
    border-radius: var(--radius);
    background: rgba(var(--danger-rgb), 0.06);
    color: var(--danger);
    font-size: 0.92rem;
}

.notice a {
    margin-left: 6px;
    font-weight: 500;
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

.subject-level {
    margin-left: 8px;
    font-size: 0.85rem;
    font-weight: 400;
}

.auto-assign-list {
    margin: 8px 0 0;
    padding-left: 20px;
    max-height: 220px;
    overflow-y: auto;
}

.auto-assign-list li {
    margin-bottom: 4px;
}
</style>