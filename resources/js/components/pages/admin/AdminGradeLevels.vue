<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { adminService, ownerService } from '../../../services';
import { useAuthStore } from '../../../stores/auth';
import { useScopeStore } from '../../../stores/scope';
import { notify } from '../../../stores/notifications';
import { formatDate, pluralize } from '../../../core/format';
import GradeLevelFormModal from '../../gradeLevels/GradeLevelFormModal.vue';
import ConfirmDialog from '../../ui/ConfirmDialog.vue';
import ReasonDialog from '../../ui/ReasonDialog.vue';
import EmptyState from '../../ui/EmptyState.vue';
import StatusBadge from '../../ui/StatusBadge.vue';
import AppIcon from '../../ui/AppIcon.vue';
import SearchSelect from '../../ui/SearchSelect.vue';

/**
 * The school's curriculum: per branch and school year, the grade levels with
 * their subjects and sections, how many classes still need a teacher, and the
 * teachers' requests for those classes.
 */
const auth = useAuthStore();
const scope = useScopeStore();
const route = useRoute();

const levels = ref([]);
const loading = ref(true);
const loaded = ref(false);

const branches = ref([]);
const branchId = ref('');
/**
 * The owner can narrow the page to one school here (the scope bar does the same for the whole admin
 * area; while it is set, this filter is hidden). It also feeds the Add grade level form's school dropdown.
 */
const organizations = ref([]);
const organizationId = ref('');
const showOrganizationFilter = computed(() => auth.isPlatform && !scope.isScoped);
const formOrganizations = computed(() => (showOrganizationFilter.value ? organizations.value : []));
const schoolFilterOptions = computed(() => [{ value: '', label: 'All schools' }, ...organizations.value.map((school) => ({ value: school.id, label: school.name }))]);

/** The organization_id the page's own requests carry, when the school filter is in use. */
const organizationParams = () => (showOrganizationFilter.value && organizationId.value !== '' ? { organization_id: organizationId.value } : {});

/** Branch names and school years repeat across schools, so the owner picks a school before filtering by them. */
const needsSchool = computed(() => showOrganizationFilter.value && organizationId.value === '');

/** Every branch and school year in reach (not narrowed by the school filter): the Add grade level form picks from these. */
const allBranches = ref([]);
const allSchoolYears = ref([]);
const schoolYears = ref([]);
const schoolYearId = ref('');
const search = ref('');

const requestedYear = Number(route.query.school_year) || null;

const levelModal = ref(false);
const editingLevel = ref(null);
const deletingLevel = ref(null);
const removing = ref(false);

/* Class requests */
const requests = ref([]);
const requestStatus = ref('pending');
const loadingRequests = ref(true);
const decidingId = ref(null);
const declining = ref(null);

const showBranchFilter = computed(() => showOrganizationFilter.value || branches.value.length > 1);

const searchTerm = computed(() => search.value.trim().toLowerCase());

function matches(level) {
    if (searchTerm.value === '') {
        return true;
    }

    const term = searchTerm.value;
    const texts = [level.name, level.branchName, ...level.subjects.map((subject) => subject.name), ...level.sections.map((section) => section.name)];

    return texts.some((text) => String(text ?? '').toLowerCase().includes(term));
}

const visibleLevels = computed(() => levels.value.filter(matches));

/** Group by school (the owner's view), then school year (newest first), then branch, so each branch's grade levels sit together. */
const groups = computed(() => {
    const map = new Map();

    for (const level of visibleLevels.value) {
        const key = `${level.schoolYearId ?? 'none'}-${level.branchId ?? 'none'}`;

        if (!map.has(key)) {
            map.set(key, {
                key,
                school: auth.isPlatform ? level.organizationName : '',
                year: level.schoolYearName || 'No school year',
                branch: level.branchName,
                levels: [],
            });
        }

        map.get(key).levels.push(level);
    }

    return [...map.values()].sort((a, b) => String(a.school ?? '').localeCompare(String(b.school ?? '')) || String(b.year).localeCompare(String(a.year)) || String(a.branch ?? '').localeCompare(String(b.branch ?? '')));
});

function openSlots(level) {
    return level.sections.reduce(
        (sum, section) => sum + sectionOpenSlots(level, section),
        0,
    );
}

function sectionOpenSlots(level, section) {
    return level.subjects.filter((subject) => section.offers(subject.id) && !section.assignmentFor(subject.id)).length;
}

function levelMeta(level) {
    const students = level.sections.reduce((sum, section) => sum + Number(section.studentsCount ?? 0), 0);

    return [
        pluralize(level.subjects.length, 'subject'),
        pluralize(level.sections.length, 'section'),
        pluralize(level.studentsCount ?? students, 'student'),
    ].join(' · ');
}

const emptyMessage = computed(() => {
    if (allSchoolYears.value.length === 0) {
        return 'Register a school year first: grade levels, subjects and sections are set up per school year.';
    }

    if (searchTerm.value !== '' && levels.value.length > 0) {
        return `No grade level, subject or section matches “${search.value.trim()}”.`;
    }

    return 'No grade levels yet. Add the grade levels this branch teaches, then their subjects and sections.';
});

async function load() {
    loading.value = true;

    try {
        levels.value = await adminService.listGradeLevels({
            ...organizationParams(),
            branch_id: branchId.value,
            school_year_id: schoolYearId.value,
        });
        loaded.value = true;
    } catch (err) {
        notify.error(err?.message ?? 'Failed to load the grade levels.');
    } finally {
        loading.value = false;
    }
}

async function loadFilters() {
    try {
        const [branchList, yearList] = await Promise.all([
            adminService.listBranches(organizationParams()),
            adminService.listSchoolYears(null, organizationParams()),
        ]);
        branches.value = branchList;
        schoolYears.value = yearList;

        if (organizationId.value === '') {
            allBranches.value = branchList;
            allSchoolYears.value = yearList;
        }

        // Years of different schools are not alike: the owner sees every school's levels until one is picked.
        if (needsSchool.value) {
            schoolYearId.value = '';
        } else if (schoolYearId.value === '' || !yearList.some((year) => year.id === Number(schoolYearId.value))) {
            const requested = requestedYear ? yearList.find((year) => year.id === requestedYear) : null;
            schoolYearId.value = (requested ?? yearList.find((year) => year.isCurrent) ?? yearList[0])?.id ?? '';
        }
    } catch (err) {
        notify.error(err?.message ?? 'Failed to load the branches and school years.');
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

async function loadRequests() {
    loadingRequests.value = true;

    try {
        requests.value = await adminService.listTeachingRequests({ ...organizationParams(), status: requestStatus.value, branch_id: branchId.value });
    } catch (err) {
        notify.error(err?.message ?? 'Failed to load the class requests.');
    } finally {
        loadingRequests.value = false;
    }
}

/** Changing the school drops the branch and school year filters, which then offer only that school's. */
async function onOrganizationChange() {
    branchId.value = '';
    schoolYearId.value = '';
    await loadFilters();
    load();
    loadRequests();
}

function onBranchChange() {
    load();
    loadRequests();
}

function openCreate() {
    editingLevel.value = null;
    levelModal.value = true;
}

function openEdit(level) {
    editingLevel.value = level;
    levelModal.value = true;
}

function onLevelSaved(level) {
    if (level?.schoolYearId && Number(schoolYearId.value) !== level.schoolYearId && schoolYears.value.some((year) => year.id === level.schoolYearId) && !needsSchool.value) {
        schoolYearId.value = level.schoolYearId;
    }

    load();
}

async function confirmRemoveLevel() {
    const target = deletingLevel.value;

    if (removing.value || !target) {
        return;
    }

    removing.value = true;

    try {
        await adminService.removeGradeLevel(target.id);
        notify.success(`${target.name} deleted.`);
        deletingLevel.value = null;
        load();
    } catch (err) {
        notify.error(err?.isValidation ? (Object.values(err.errors ?? {})[0]?.[0] ?? err.message) : (err?.message ?? 'Unable to delete the grade level.'));
    } finally {
        removing.value = false;
    }
}

async function accept(request) {
    if (decidingId.value !== null) {
        return;
    }

    decidingId.value = request.id;

    try {
        await adminService.acceptTeachingRequest(request.id);
        notify.success(`${request.teacherName} now teaches ${request.label}.`);
        await Promise.all([loadRequests(), load()]);
        auth.refresh().catch(() => {});
    } catch (err) {
        notify.error(err?.isValidation ? (Object.values(err.errors ?? {})[0]?.[0] ?? err.message) : (err?.message ?? 'Unable to accept the request.'));
        loadRequests();
    } finally {
        decidingId.value = null;
    }
}

async function confirmDecline(reason) {
    const request = declining.value;

    if (!request || decidingId.value !== null) {
        return;
    }

    decidingId.value = request.id;

    try {
        await adminService.declineTeachingRequest(request.id, reason);
        notify.info(`${request.teacherName}'s request for ${request.label} was declined.`);
        declining.value = null;
        await loadRequests();
        auth.refresh().catch(() => {});
    } catch (err) {
        notify.error(err?.message ?? 'Unable to decline the request.');
    } finally {
        decidingId.value = null;
    }
}

/** "Accepted by Ana Cruz · Oct 19, 2026" */
function decisionLine(request) {
    if (request.isPending) {
        return '';
    }

    const by = request.deciderName ? ` by ${request.deciderName}` : '';
    const when = formatDate(request.decidedAt);

    return `${request.statusLabel}${by}${when ? ` · ${when}` : ''}`;
}

watch(() => scope.organizationId, async () => {
    branchId.value = '';
    schoolYearId.value = '';
    organizationId.value = '';
    await loadFilters();
    load();
    loadRequests();
});

onMounted(async () => {
    loadOrganizations();
    loadRequests();
    await loadFilters();
    load();
});
</script>

<template>
    <div class="admin-grade-levels">
        <div class="page-header">
            <div>
                <h1>Grade Levels</h1>
                <p class="muted subtitle">
                    Each branch has its own grade levels per school year. Subjects belong to a grade level, and each
                    student takes their own, so students of one section may differ. Pick who teaches each subject in each
                    section, or answer the teachers' requests.
                </p>
            </div>
            <div class="page-actions">
                <button type="button" class="btn" :disabled="!auth.canWrite || allSchoolYears.length === 0" @click="openCreate">Add grade level</button>
            </div>
        </div>

        <section class="card requests-card">
            <div class="card-header">
                <h2 class="card-title">Class requests</h2>
                <select v-model="requestStatus" class="input status-select" aria-label="Filter class requests by status" @change="loadRequests">
                    <option value="pending">Pending</option>
                    <option value="">All requests</option>
                    <option value="accepted">Accepted</option>
                    <option value="declined">Declined</option>
                    <option value="cancelled">Withdrawn</option>
                </select>
            </div>

            <p v-if="loadingRequests && requests.length === 0" class="muted state">Loading…</p>

            <EmptyState
                v-else-if="requests.length === 0"
                compact
                :message="requestStatus === 'pending' ? 'No teacher is waiting for an answer.' : 'No class requests yet.'"
            />

            <div v-else class="table-wrap">
                <table class="table requests">
                    <colgroup>
                        <col class="col-teacher" />
                        <col />
                        <col class="col-date" />
                        <col class="col-status" />
                        <col class="col-actions" />
                    </colgroup>
                    <thead>
                        <tr>
                            <th>Teacher</th>
                            <th>Class</th>
                            <th>Requested</th>
                            <th>Status</th>
                            <th class="table-actions"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="request in requests" :key="request.id">
                            <td class="ellipsis">
                                <RouterLink v-if="request.teacher" :to="{ name: 'admin-user-detail', params: { id: request.teacher.id } }" class="name">
                                    {{ request.teacherName }}
                                </RouterLink>
                                <span v-if="request.teacherBranchName" class="muted small">{{ request.teacherBranchName }}</span>
                            </td>
                            <td class="ellipsis">
                                <RouterLink v-if="request.sectionId" :to="{ name: 'admin-section-detail', params: { id: request.sectionId } }">
                                    {{ request.label }}
                                </RouterLink>
                                <span class="muted small">
                                    SY {{ request.schoolYearName || '—' }}<template v-if="request.message"> · “{{ request.message }}”</template>
                                </span>
                            </td>
                            <td class="nowrap">{{ formatDate(request.createdAt) || '—' }}</td>
                            <td>
                                <StatusBadge :tone="request.tone">{{ request.statusLabel }}</StatusBadge>
                                <span v-if="decisionLine(request)" class="muted small">{{ decisionLine(request) }}</span>
                            </td>
                            <td class="table-actions">
                                <template v-if="request.isPending">
                                    <button type="button" class="btn btn-sm" :disabled="!auth.canWrite || decidingId !== null" @click="accept(request)">
                                        Accept
                                    </button>
                                    <button type="button" class="btn btn-secondary btn-sm" :disabled="!auth.canWrite || decidingId !== null" @click="declining = request">
                                        Decline
                                    </button>
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <div class="toolbar">
            <div class="toolbar-controls">
                <input v-model="search" class="input search" type="search" placeholder="Search grade level, subject or section" aria-label="Search grade levels" />
                <SearchSelect
                    v-if="showOrganizationFilter"
                    v-model="organizationId"
                    class="school-select"
                    :options="schoolFilterOptions"
                    aria-label="Filter by school"
                    empty-text="No school matches"
                    @change="onOrganizationChange"
                />
                <select v-if="showBranchFilter" v-model="branchId" class="input select" aria-label="Filter by branch" :disabled="needsSchool" @change="onBranchChange">
                    <option value="">{{ needsSchool ? 'Pick a school first' : 'All branches' }}</option>
                    <option v-for="branch in (needsSchool ? [] : branches)" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
                </select>
                <label class="year">
                    <span class="muted">School year</span>
                    <select v-model="schoolYearId" class="input" aria-label="Filter by school year" :disabled="needsSchool" @change="load">
                        <option value="">{{ needsSchool ? 'Pick a school first' : 'All school years' }}</option>
                        <option v-for="year in (needsSchool ? [] : schoolYears)" :key="year.id" :value="year.id">
                            {{ year.name }}{{ year.isCurrent ? ' (current)' : '' }}
                        </option>
                    </select>
                </label>
            </div>
            <span v-if="loaded" class="muted count nowrap">{{ pluralize(visibleLevels.length, 'grade level') }}</span>
        </div>

        <p v-if="loading && !loaded" class="muted">Loading…</p>

        <EmptyState v-else-if="visibleLevels.length === 0" :message="emptyMessage">
            <template #action>
                <RouterLink v-if="allSchoolYears.length === 0" :to="{ name: 'admin-school-years' }" class="btn btn-secondary btn-sm">School years</RouterLink>
                <button v-else-if="searchTerm !== ''" type="button" class="btn btn-secondary btn-sm" @click="search = ''">Clear search</button>
                <button v-else type="button" class="btn btn-secondary btn-sm" :disabled="!auth.canWrite" @click="openCreate">Add grade level</button>
            </template>
        </EmptyState>

        <div v-else class="groups">
            <section v-for="group in groups" :key="group.key" class="group">
                <h2 class="group-title">
                    <template v-if="group.school">{{ group.school }}<span class="muted"> · </span></template>
                    <span class="muted">SY</span> <span class="mono">{{ group.year }}</span>
                    <template v-if="group.branch"><span class="muted"> · </span>{{ group.branch }}</template>
                    <span class="muted group-count">· {{ pluralize(group.levels.length, 'grade level') }}</span>
                </h2>

                <div class="levels">
                    <section v-for="level in group.levels" :key="level.id" class="card level">
                        <div class="level-header">
                            <div>
                                <h3 class="level-name">
                                    <RouterLink :to="{ name: 'admin-grade-level-detail', params: { id: level.id } }">{{ level.name }}</RouterLink>
                                </h3>
                                <p class="muted level-meta">
                                    {{ levelMeta(level) }}
                                    <template v-if="openSlots(level) > 0">
                                        · <span class="text-danger">{{ pluralize(openSlots(level), 'class', 'classes') }} without a teacher</span>
                                    </template>
                                </p>
                            </div>
                            <div class="level-actions">
                                <RouterLink
                                    :to="{ name: 'admin-grade-level-detail', params: { id: level.id } }"
                                    class="btn btn-secondary btn-sm"
                                >
                                    Subjects, sections and teachers
                                </RouterLink>
                                <button
                                    type="button"
                                    class="btn btn-link btn-sm btn-icon"
                                    :title="`Edit ${level.name}`"
                                    :aria-label="`Edit ${level.name}`"
                                    :disabled="!auth.canWrite"
                                    @click="openEdit(level)"
                                >
                                    <AppIcon name="pencil" />
                                </button>
                                <button
                                    type="button"
                                    class="btn btn-link btn-sm btn-icon text-danger"
                                    :title="level.hasStudents ? 'Move or remove its students first' : `Delete ${level.name}`"
                                    :aria-label="`Delete ${level.name}`"
                                    :disabled="!auth.canWrite"
                                    @click="deletingLevel = level"
                                >
                                    <AppIcon name="trash" />
                                </button>
                            </div>
                        </div>

                        <EmptyState v-if="level.sections.length === 0" compact message="No sections yet.">
                            <template #action>
                                <RouterLink :to="{ name: 'admin-grade-level-detail', params: { id: level.id } }" class="btn btn-link btn-sm">
                                    Add sections
                                </RouterLink>
                            </template>
                        </EmptyState>

                        <table v-else class="table sections">
                            <colgroup>
                                <col class="col-section" />
                                <col />
                                <col class="col-students" />
                                <col class="col-open" />
                                <col class="col-view" />
                            </colgroup>
                            <thead>
                                <tr>
                                    <th>Section</th>
                                    <th>Teachers</th>
                                    <th class="text-right">Students</th>
                                    <th class="text-right">No teacher</th>
                                    <th class="table-actions"><span class="sr-only">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="section in level.sections" :key="section.id">
                                    <td class="ellipsis">
                                        <RouterLink :to="{ name: 'admin-section-detail', params: { id: section.id } }" class="section-name">{{ section.name }}</RouterLink>
                                    </td>
                                    <td class="ellipsis" :title="section.teachers.map((teacher) => teacher.name).join(', ')">
                                        {{ section.teachers.map((teacher) => teacher.name).join(', ') || '—' }}
                                    </td>
                                    <td class="mono text-right">{{ section.studentsCount ?? 0 }}</td>
                                    <td class="mono text-right" :class="{ 'text-danger': sectionOpenSlots(level, section) > 0 }">
                                        {{ sectionOpenSlots(level, section) || '—' }}
                                    </td>
                                    <td class="table-actions">
                                        <RouterLink
                                            :to="{ name: 'admin-section-detail', params: { id: section.id } }"
                                            class="btn btn-link btn-sm btn-icon"
                                            :title="`View ${section.name}`"
                                            :aria-label="`View ${level.name} ${section.name}`"
                                        >
                                            <AppIcon name="eye" />
                                        </RouterLink>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </section>
                </div>
            </section>
        </div>

        <GradeLevelFormModal
            v-model="levelModal"
            :grade-level="editingLevel"
            :branches="allBranches"
            :school-years="allSchoolYears"
            :branch-id="branchId || null"
            :school-year-id="schoolYearId || null"
            :organizations="formOrganizations"
            @saved="onLevelSaved"
        />

        <ConfirmDialog
            :model-value="deletingLevel !== null"
            title="Delete grade level"
            :busy="removing"
            @update:model-value="deletingLevel = null"
            @confirm="confirmRemoveLevel"
        >
            Delete <strong v-if="deletingLevel">{{ deletingLevel.labelWithBranch }}</strong>?
            <template v-if="deletingLevel && deletingLevel.hasStudents">
                It still has {{ pluralize(deletingLevel.studentsCount, 'student') }} enrolled, so it cannot be deleted until
                they are moved to another section or removed.
            </template>
            <template v-else>Its subjects, sections and teaching assignments go with it. This cannot be undone.</template>
        </ConfirmDialog>

        <ReasonDialog
            :model-value="declining !== null"
            title="Decline class request"
            :busy="decidingId !== null"
            @update:model-value="declining = null"
            @confirm="confirmDecline"
        >
            Decline <strong v-if="declining">{{ declining.teacherName }}</strong>'s request to teach
            <strong v-if="declining">{{ declining.label }}</strong>?
        </ReasonDialog>
    </div>
</template>

<style scoped>
.admin-grade-levels {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.page-header {
    margin-bottom: 0;
}

.subtitle {
    margin: 4px 0 0;
    max-width: 720px;
}

.card-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    padding: 12px 16px;
    border-bottom: 1px solid var(--border);
}

.card-header .card-title {
    margin: 0;
}

.requests-card {
    padding: 0;
    overflow: hidden;
}

.status-select {
    width: auto;
    min-width: 150px;
    padding-top: 4px;
    padding-bottom: 4px;
}

.state {
    margin: 0;
    padding: 24px 16px;
    text-align: center;
}

.table-wrap {
    overflow-x: auto;
}

.requests {
    table-layout: fixed;
    min-width: 820px;
}

.col-teacher {
    width: 200px;
}

.col-date {
    width: 120px;
}

.col-status {
    width: 210px;
}

.col-actions {
    width: 170px;
}

.toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px 16px;
    flex-wrap: wrap;
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
    flex: 1 1 220px;
    max-width: 300px;
}

.select {
    min-width: 160px;
}

.school-select {
    flex: 0 1 220px;
    min-width: 170px;
}

.year {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.92rem;
}

.year .input {
    min-width: 170px;
}

.count {
    font-size: 0.92rem;
}

.groups {
    display: flex;
    flex-direction: column;
    gap: 24px;
}

.group {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.group-title {
    margin: 0;
    font-size: 1rem;
    font-weight: 600;
}

.group-count {
    font-weight: 400;
    font-size: 0.9rem;
}

.levels {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.level {
    padding: 0;
    overflow: hidden;
}

.level-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    padding: 12px 16px;
    border-bottom: 1px solid var(--border);
}

.level-name {
    margin: 0;
    font-size: 1.05rem;
    font-weight: 600;
}

.level-meta {
    margin: 2px 0 0;
    font-size: 0.85rem;
}

.level-actions {
    display: flex;
    align-items: center;
    gap: 4px;
    flex-wrap: wrap;
}

.sections {
    table-layout: fixed;
}

.col-section {
    width: 26%;
}

.col-students {
    width: 100px;
}

.col-open {
    width: 110px;
}

.col-view {
    width: 60px;
}

.name,
.section-name {
    font-weight: 500;
}

.ellipsis {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

td .small {
    display: block;
    font-size: 0.82rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.table-actions .btn + .btn {
    margin-left: 4px;
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
