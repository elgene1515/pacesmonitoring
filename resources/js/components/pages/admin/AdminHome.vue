<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { adminService, ownerService } from '../../../services';
import AdminService from '../../../services/AdminService';
import { useAuthStore } from '../../../stores/auth';
import { useScopeStore } from '../../../stores/scope';
import { notify } from '../../../stores/notifications';
import { formatScore, pluralize } from '../../../core/format';
import { quarterLabel } from '../../../core/schoolYear';
import EmptyState from '../../ui/EmptyState.vue';
import SearchSelect from '../../ui/SearchSelect.vue';
import LayeredRings from '../../charts/LayeredRings.vue';
import QuarterTiles from '../../charts/QuarterTiles.vue';
import SexBarChart from '../../charts/SexBarChart.vue';
import BarList from '../../charts/BarList.vue';
import DualBarList from '../../charts/DualBarList.vue';
import StackedBarList from '../../charts/StackedBarList.vue';
import RecordList from '../../charts/RecordList.vue';
import RecentLists from '../../admin/RecentLists.vue';

/**
 * Analytics across every teacher of the organization (the owner: of every
 * organization, or of the one picked in the scope bar).
 */
const auth = useAuthStore();
const scope = useScopeStore();

const summary = ref(AdminService.emptyDashboard());
const loading = ref(true);

/** Filters: branch, teacher, school year (organization-wide), then that teacher's grade level, subject and PACE. */
const branches = ref([]);
const branchId = ref('');
const teachers = ref([]);
const teacherId = ref('');
const schoolYears = ref([]);
const schoolYearId = ref('');
const loadingSchoolYears = ref(false);
const gradeLevels = ref([]);
const gradeLevelId = ref('');
const loadingGradeLevels = ref(false);
const sectionId = ref('');
const subjectId = ref('');
const paces = ref([]);
const paceId = ref('');
const loadingPaces = ref(false);

/**
 * The owner can narrow the analytics to one organization here (the scope bar does the same for the
 * whole admin area; while it is set, this filter is hidden). School admins never see it.
 */
const organizations = ref([]);
const organizationId = ref('');
const showOrganizationFilter = computed(() => auth.isPlatform && !scope.isScoped);
const selectedOrganization = computed(() => organizations.value.find((organization) => Number(organization.id) === Number(organizationId.value)) ?? null);

/** The organization_id the page's own requests carry, when the organization filter is in use. */
const organizationParams = () => (showOrganizationFilter.value && organizationId.value !== '' ? { organization_id: organizationId.value } : {});

/** The organization filter's choices: every organization, or none picked (a dropdown you can type into). */
const organizationFilterOptions = computed(() => [{ value: '', label: 'All organizations' }, ...organizations.value.map((organization) => ({ value: organization.id, label: organization.name }))]);

/**
 * Every organization at once: branch and school year names repeat across organizations, so each one
 * says which organization it belongs to (not shown once one organization is picked or in the scope bar).
 */
const showWhere = computed(() => auth.isPlatform && !scope.isScoped && organizationId.value === '');

/** "Main Campus · Grace Christian Academy": the branch with the organization it belongs to (the organization alone when there is no branch). */
function whereLabel(branchName, organizationName) {
    return [branchName, showWhere.value ? organizationName : ''].filter(Boolean).join(' · ');
}

/** The name of an organization by id (for school year options). */
function organizationNameOf(id) {
    return organizations.value.find((organization) => Number(organization.id) === Number(id))?.name ?? '';
}

/** Branches only make sense within one organization. */
const showBranchFilter = computed(() => auth.isAdmin || scope.isScoped || organizationId.value !== '');

const hasScope = computed(() => organizationId.value !== '' || branchId.value !== '' || teacherId.value !== '' || schoolYearId.value !== '' || gradeLevelId.value !== '' || sectionId.value !== '' || subjectId.value !== '' || paceId.value !== '');

const visibleTeachers = computed(() => (branchId.value
    ? teachers.value.filter((teacher) => Number(teacher.branchId) === Number(branchId.value))
    : teachers.value));

const selectedBranch = computed(() => branches.value.find((branch) => Number(branch.id) === Number(branchId.value)) ?? null);
const selectedTeacher = computed(() => teachers.value.find((teacher) => Number(teacher.id) === Number(teacherId.value)) ?? null);
const selectedYear = computed(() => schoolYears.value.find((year) => Number(year.id) === Number(schoolYearId.value)) ?? null);
const selectedGradeLevel = computed(() => gradeLevels.value.find((level) => Number(level.id) === Number(gradeLevelId.value)) ?? null);

/** Sections of the chosen grade level (the section filter waits for one). */
const sectionOptions = computed(() => selectedGradeLevel.value?.sections ?? []);
const selectedSection = computed(() => sectionOptions.value.find((section) => Number(section.id) === Number(sectionId.value)) ?? null);

/** Subjects offered: the chosen grade level's, else every subject of the loaded grade levels. */
const subjectOptions = computed(() => {
    const levels = selectedGradeLevel.value ? [selectedGradeLevel.value] : gradeLevels.value;
    const single = levels.length === 1;

    return levels.flatMap((level) => level.subjects.map((subject) => ({
        id: subject.id,
        label: single ? subject.name : `${subject.name} · ${level.name}`,
    })));
});

const selectedSubjectName = computed(() => subjectOptions.value.find((subject) => Number(subject.id) === Number(subjectId.value))?.label ?? '');

const selectedPace = computed(() => paces.value.find((pace) => Number(pace.id) === Number(paceId.value)) ?? null);

const title = computed(() => (auth.isPlatform ? 'Analytics' : 'Admin dashboard'));

const baseline = computed(() => {
    if (auth.isAdmin) {
        return `Totals across every teacher in ${auth.user?.organizationName || 'your school'}.`;
    }

    return scope.isScoped
        ? `Totals across every teacher in ${scope.organizationName || 'this organization'}.`
        : 'Platform-wide totals across every organization and teacher.';
});

const subtitle = computed(() => {
    const parts = [
        selectedOrganization.value?.name,
        selectedBranch.value?.name,
        selectedTeacher.value?.name,
        selectedYear.value ? `SY ${selectedYear.value.name}` : null,
        selectedGradeLevel.value?.name,
        selectedSection.value?.name,
        selectedSubjectName.value,
        selectedPace.value ? `PACE ${selectedPace.value.label}` : null,
    ].filter(Boolean);

    return parts.length ? `Totals for ${parts.join(' · ')}.` : baseline.value;
});

/** "SY 2026-2027" / "All teachers", used as a suffix on each chart's title. */
const periodLabel = computed(() => {
    if (selectedYear.value) {
        return `SY ${selectedYear.value.name}`;
    }

    if (selectedTeacher.value) {
        return selectedTeacher.value.name;
    }

    if (selectedBranch.value) {
        return selectedBranch.value.name;
    }

    return selectedOrganization.value ? selectedOrganization.value.name : 'All teachers';
});

const tiles = computed(() => [
    { key: 'teachers', label: 'Teachers', value: summary.value.teachers, to: { name: 'admin-users' } },
    { key: 'students', label: 'Students', value: summary.value.students, to: { name: 'admin-students' } },
    { key: 'paces', label: 'PACEs', value: summary.value.paces, to: null },
]);

/**
 * Layered progress rings, outer to inner. Assigned / issued / tested / retakes
 * are shares of every PACE record in scope; pass rate and fails are shares of
 * the records that have a score.
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
            to: { name: 'admin-student-detail', params: { id: item.studentId } },
            primary: item.studentName,
            secondary: `${item.subjectName} · #${item.paceNumber} ${item.paceName} · ${item.teacherName}`,
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
    to: { name: 'admin-student-detail', params: { id: item.studentId } },
    primary: item.studentName,
    secondary: `${pluralize(item.scoredCount, 'PACE')} scored · ${item.passedCount} pass · ${item.failedCount} fail · ${pluralize(item.retakeCount, 'retake')}${item.gradeLevelName ? ` · ${[item.gradeLevelName, item.sectionName].filter(Boolean).join(' · ')}` : ''}`,
    value: formatScore(item.averageScore),
    valueClass: 'success',
    caption: 'avg score',
})));

const scoreDistributionItems = computed(() => summary.value.scoreDistribution.map((band) => ({
    id: band.label,
    label: band.label,
    value: band.total,
})));

const subjectBarItems = computed(() => summary.value.bySubject.map((subject) => ({
    id: subject.id,
    label: subject.name,
    sublabel: [
        subject.gradeLevelName,
        whereLabel('', subject.organizationName),
        subject.averageScore === null ? '' : `avg ${formatScore(subject.averageScore)}`,
    ].filter(Boolean).join(' · '),
    total: subject.total,
    tested: subject.tested,
    issued: subject.issued,
    assigned: subject.assigned,
})));

/** One bar per grade level; with a section filter the bar says which section its students are in. */
const gradeLevelBarItems = computed(() => summary.value.byGradeLevel.map((level) => ({
    id: level.id,
    label: [
        sectionId.value && selectedSection.value ? `${level.name} · ${selectedSection.value.name}` : level.name,
        whereLabel(branchId.value ? '' : level.branchName, level.organizationName),
    ].filter(Boolean).join(' · '),
    total: level.total,
    male: level.male,
    female: level.female,
})));

const teacherActivityItems = computed(() => summary.value.teacherActivity.map((teacher) => ({
    id: teacher.id,
    label: teacher.name,
    sublabel: [pluralize(teacher.studentsCount, 'student'), whereLabel(teacher.branchName, teacher.organizationName)].filter(Boolean).join(' · '),
    total: teacher.assignmentsCount,
    tested: teacher.testedCount,
    issued: teacher.issuedCount,
    assigned: teacher.assignedCount,
    to: { name: 'admin-user-detail', params: { id: teacher.id } },
})));

/** Two bars per PACE: fail rate (red) and retake rate (orange), with the counts behind each rate underneath. */
const hardestPaceItems = computed(() => summary.value.hardestPaces.map((item) => ({
    id: item.paceId,
    label: `#${item.paceNumber} ${item.paceName}`,
    sublabel: [whereLabel(item.branchName, item.organizationName), `${item.failedCount} of ${item.scoredCount} failed · ${item.retakeCount} of ${item.totalCount} retakes`].filter(Boolean).join(' · '),
    first: item.failRate,
    second: item.retakeRate,
    to: { name: 'admin-pace-detail', params: { id: item.paceId } },
})));

async function load() {
    loading.value = true;

    try {
        summary.value = await adminService.getDashboardSummary({
            branchId: branchId.value || null,
            teacherId: teacherId.value || null,
            schoolYearId: schoolYearId.value || null,
            gradeLevelId: gradeLevelId.value || null,
            sectionId: sectionId.value || null,
            subjectId: subjectId.value || null,
            paceId: paceId.value || null,
            organizationId: showOrganizationFilter.value ? organizationId.value || null : null,
        });
    } catch (err) {
        notify.error(err?.message ?? 'Failed to load the dashboard.');
    } finally {
        loading.value = false;
    }
}

async function loadFilters() {
    try {
        const [teacherList, branchList] = await Promise.all([
            adminService.listTeachers(organizationParams()),
            showBranchFilter.value ? adminService.listBranches(organizationParams()) : Promise.resolve([]),
        ]);
        teachers.value = teacherList;
        branches.value = branchList;
    } catch (err) {
        notify.error(err?.message ?? 'Failed to load teachers.');
    }

    loadingSchoolYears.value = true;

    try {
        schoolYears.value = await adminService.listSchoolYears(null, organizationParams());
    } catch (err) {
        notify.error(err?.message ?? 'Failed to load school years.');
    } finally {
        loadingSchoolYears.value = false;
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

/** Changing the organization drops every narrower filter and reloads the branch, teacher and school year options. */
function onOrganizationChange() {
    branchId.value = '';
    teacherId.value = '';
    schoolYearId.value = '';
    gradeLevelId.value = '';
    sectionId.value = '';
    gradeLevels.value = [];
    subjectId.value = '';
    paces.value = [];
    paceId.value = '';

    load();
    loadFilters();
}

/** Changing the branch drops the teacher (and everything under it) when they are not in that branch. */
function onBranchChange() {
    if (teacherId.value && !visibleTeachers.value.some((teacher) => Number(teacher.id) === Number(teacherId.value))) {
        teacherId.value = '';
        gradeLevelId.value = '';
        sectionId.value = '';
        gradeLevels.value = [];
        subjectId.value = '';
        paces.value = [];
        paceId.value = '';
    }

    gradeLevelId.value = '';

    sectionId.value = '';
    subjectId.value = '';
    reloadGradeLevels();

    load();
}

/** Changing the teacher drops the grade level / subject / PACE filters and loads that teacher's grade levels and PACEs. */
async function onTeacherChange() {
    gradeLevelId.value = '';
    sectionId.value = '';
    gradeLevels.value = [];
    subjectId.value = '';
    paces.value = [];
    paceId.value = '';

    if (teacherId.value) {
        loadingGradeLevels.value = true;
        loadingPaces.value = true;

        try {
            [, paces.value] = await Promise.all([
                reloadGradeLevels(),
                adminService.listPaces({ teacher_id: teacherId.value }),
            ]);
        } catch (err) {
            notify.error(err?.message ?? 'Failed to load the teacher\'s grade levels and PACEs.');
        } finally {
            loadingGradeLevels.value = false;
            loadingPaces.value = false;
        }
    }

    load();
}

/**
 * The grade levels offered by the filter: those of the chosen branch and school year,
 * narrowed to the chosen teacher's (none until a school year or a teacher is picked).
 */
async function reloadGradeLevels() {
    gradeLevels.value = [];

    if (!teacherId.value && !schoolYearId.value) {
        return;
    }

    loadingGradeLevels.value = true;

    try {
        gradeLevels.value = await adminService.listGradeLevels({
            ...organizationParams(),
            branch_id: branchId.value || '',
            teacher_id: teacherId.value || '',
            school_year_id: schoolYearId.value || '',
        });
    } catch (err) {
        notify.error(err?.message ?? 'Failed to load grade levels.');
    } finally {
        loadingGradeLevels.value = false;
    }
}

/** Changing the school year drops the grade level / subject filters and reloads that year's grade levels. */
async function onSchoolYearChange() {
    gradeLevelId.value = '';
    sectionId.value = '';
    subjectId.value = '';

    await reloadGradeLevels();

    load();
}

function onGradeLevelChange() {
    const level = selectedGradeLevel.value;

    sectionId.value = '';

    if (subjectId.value && (!level || !level.subjectById(subjectId.value))) {
        subjectId.value = '';
    }

    load();
}

function clearScope() {
    const hadOrganization = organizationId.value !== '';

    organizationId.value = '';
    branchId.value = '';
    teacherId.value = '';
    schoolYearId.value = '';
    gradeLevelId.value = '';
    sectionId.value = '';
    gradeLevels.value = [];
    subjectId.value = '';
    paces.value = [];
    paceId.value = '';
    load();

    if (hadOrganization) {
        loadFilters();
    }
}

watch(() => scope.organizationId, () => {
    organizationId.value = '';
    branchId.value = '';
    teacherId.value = '';
    schoolYearId.value = '';
    gradeLevelId.value = '';
    sectionId.value = '';
    gradeLevels.value = [];
    subjectId.value = '';
    paces.value = [];
    paceId.value = '';
    load();
    loadFilters();
});

onMounted(() => {
    loadOrganizations();
    load();
    loadFilters();
});
</script>

<template>
    <div class="admin-home">
        <!-- The heading and filters stay pinned under the top bar while the page scrolls. -->
        <div class="sticky-top">
            <div class="page-header">
                <div>
                    <h1>{{ title }}</h1>
                    <p class="muted subtitle">{{ subtitle }}</p>
                </div>
            </div>

            <!-- Filters on their own row under the heading: branch, teacher, school year, then that teacher's grade level / subject / PACE. -->
            <div class="filters">
                <div v-if="showOrganizationFilter" class="filter">
                    <span class="muted">Organization</span>
                    <SearchSelect
                        v-model="organizationId"
                        class="organization-select"
                        :options="organizationFilterOptions"
                        aria-label="Organization"
                        empty-text="No organization matches"
                        :disabled="loading"
                        @change="onOrganizationChange"
                    />
                </div>
                <label v-if="showBranchFilter" class="filter">
                    <span class="muted">Branch</span>
                    <select v-model="branchId" class="input" aria-label="Branch" :disabled="loading" @change="onBranchChange">
                        <option value="">All branches</option>
                        <option v-for="branch in branches" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
                    </select>
                </label>
                <label class="filter">
                    <span class="muted">Teacher</span>
                    <select v-model="teacherId" class="input" aria-label="Teacher" :disabled="loading" @change="onTeacherChange">
                        <option value="">All teachers</option>
                        <option v-for="teacher in visibleTeachers" :key="teacher.id" :value="teacher.id">{{ teacher.name }}{{ showWhere && teacher.organizationName ? ` · ${teacher.organizationName}` : '' }}</option>
                    </select>
                </label>
                <label class="filter">
                    <span class="muted">School year</span>
                    <select
                        v-model="schoolYearId"
                        class="input"
                        aria-label="School year"
                        :disabled="loading || loadingSchoolYears"
                        @change="onSchoolYearChange"
                    >
                        <option value="">All school years</option>
                        <option v-for="year in schoolYears" :key="year.id" :value="year.id">
                            {{ year.name }}{{ year.isCurrent ? ' (default)' : '' }}{{ showWhere && organizationNameOf(year.organizationId) ? ` · ${organizationNameOf(year.organizationId)}` : '' }}
                        </option>
                    </select>
                </label>
                <label class="filter">
                    <span class="muted">Grade level</span>
                    <select
                        v-model="gradeLevelId"
                        class="input"
                        aria-label="Grade level"
                        :disabled="loading || (!teacherId && !schoolYearId) || loadingGradeLevels"
                        @change="onGradeLevelChange"
                    >
                        <option value="">{{ teacherId || schoolYearId ? 'All grade levels' : 'Select a school year first' }}</option>
                        <option v-for="level in gradeLevels" :key="level.id" :value="level.id">
                            {{ schoolYearId ? level.name : level.labelWithYear }}{{ !branchId && level.branchName && showBranchFilter ? ` · ${level.branchName}` : '' }}{{ showWhere && level.organizationName ? ` · ${level.organizationName}` : '' }}
                        </option>
                    </select>
                </label>
                <label class="filter">
                    <span class="muted">Section</span>
                    <select v-model="sectionId" class="input" aria-label="Section" :disabled="loading || !gradeLevelId" @change="load">
                        <option value="">{{ gradeLevelId ? 'All sections' : 'Select a grade level first' }}</option>
                        <option v-for="section in sectionOptions" :key="section.id" :value="section.id">{{ section.name }}</option>
                    </select>
                </label>
                <label class="filter">
                    <span class="muted">Subject</span>
                    <select v-model="subjectId" class="input" aria-label="Subject" :disabled="loading || subjectOptions.length === 0" @change="load">
                        <option value="">{{ subjectOptions.length ? 'All subjects' : 'Select a grade level first' }}</option>
                        <option v-for="subject in subjectOptions" :key="subject.id" :value="subject.id">{{ subject.label }}</option>
                    </select>
                </label>
                <label class="filter">
                    <span class="muted">PACE</span>
                    <select
                        v-model="paceId"
                        class="input"
                        aria-label="PACE"
                        :disabled="loading || !teacherId || loadingPaces"
                        @change="load"
                    >
                        <option value="">{{ teacherId ? 'All PACEs' : 'Select a teacher first' }}</option>
                        <option v-for="pace in paces" :key="pace.id" :value="pace.id">{{ pace.label }}</option>
                    </select>
                </label>
                <button v-if="hasScope" type="button" class="btn btn-link btn-sm" :disabled="loading" @click="clearScope">Clear</button>
            </div>
        </div>

        <!-- Count tiles stacked on the left; progress rings and score distribution on the right, same height. -->
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
                    <EmptyState v-else-if="summary.assignments === 0" compact message="No PACE records in this scope yet." />
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

        <!-- Two equal cards, stretched to the same height. -->
        <div v-if="!loading" class="grid-2 pair">
            <section class="card">
                <h2 class="card-title">Top performing students · {{ periodLabel }}</h2>
                <p class="muted card-hint">
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

        <!-- The subject breakdown would be one bar under a subject filter, so it hides; the grade level card always shows. -->
        <div v-if="!loading" :class="{ 'grid-2 pair': !subjectId }">
            <section v-if="!subjectId" class="card">
                <h2 class="card-title">PACEs by subject · {{ periodLabel }}</h2>
                <StackedBarList :items="subjectBarItems" empty-message="No PACEs assigned yet." scrollable />
            </section>

            <section class="card">
                <h2 class="card-title">Students by grade level · {{ periodLabel }}</h2>
                <SexBarChart :items="gradeLevelBarItems" empty-message="No grade levels yet." />
            </section>
        </div>

        <div v-if="!loading" class="grid-2 pair">
            <section class="card">
                <h2 class="card-title">Most active teachers · {{ selectedBranch ? selectedBranch.name : (showWhere ? 'All organizations' : 'All branches') }}</h2>
                <p class="muted card-hint">PACE records per teacher, busiest first. Follows every filter above: the teachers who teach in what is left, with their students and records there.</p>
                <StackedBarList :items="teacherActivityItems" empty-message="No teachers have registered any records yet." />
            </section>

            <section class="card">
                <h2 class="card-title">Hardest PACEs · {{ periodLabel }}</h2>
                <p class="muted card-hint">
                    Every PACE with a fail or a retake, ranked by fail rate (%), then by retake rate, among PACEs with at
                    least 3 scored attempts. The fail rate is the share of scored records below 80; the retake rate is the
                    share of all records of that PACE flagged as a retake.
                </p>
                <DualBarList
                    :items="hardestPaceItems"
                    first-label="Fail rate"
                    first-color="var(--danger)"
                    second-label="Retake rate"
                    second-color="#d67014"
                    unit="%"
                    empty-message="No PACE with 3 or more scored attempts has a fail or a retake yet."
                    scrollable
                    :max-height="360"
                />
            </section>
        </div>

        <!-- School admins find these at the bottom of their Organization page instead. -->
        <RecentLists v-if="!auth.isAdmin" :students="summary.recentStudents" :users="summary.recentUsers" :loading="loading" :show-organization="showWhere" />
    </div>
</template>

<style scoped>
.admin-home {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

/* Heading and filters pinned under the top bar (like the detail pages). */
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

.filter {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.92rem;
    max-width: 100%;
}

/* A long option ("2026-2027 · Grace Christian Academy") must not stretch the filter past the screen. */
.filter .input {
    width: auto;
    min-width: 150px;
    max-width: 100%;
    flex: 0 1 auto;
    text-overflow: ellipsis;
}

.organization-select {
    min-width: 220px;
}

.overview {
    display: flex;
    align-items: stretch;
    gap: 16px;
}

/* Three count tiles stacked vertically; the cards beside them grow to match their combined height. */
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

.progress-hint {
    margin: 12px 0 0;
    font-size: 0.82rem;
}

.pair > .card {
    min-width: 0;
}

.card-hint {
    margin: -4px 0 12px;
    font-size: 0.85rem;
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

@media (max-width: 960px) {
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
}

@media (max-width: 400px) {
    .stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
</style>
