<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { useReferenceStore } from '../../stores/reference';
import { useSchoolYearsStore } from '../../stores/schoolYears';
import { notify } from '../../stores/notifications';
import { pluralize } from '../../core/format';
import GradeLevelFormModal from '../gradeLevels/GradeLevelFormModal.vue';
import SubjectFormModal from '../gradeLevels/SubjectFormModal.vue';
import ConfirmDialog from '../ui/ConfirmDialog.vue';
import EmptyState from '../ui/EmptyState.vue';
import AppIcon from '../ui/AppIcon.vue';
import PaceRecordsBar from '../charts/PaceRecordsBar.vue';
import PaceRecordsLegend from '../charts/PaceRecordsLegend.vue';

const ALL_YEARS = '';

/** Bar segments and legend read tested / issued / assigned, left to right. */
const RECORD_ORDER = ['tested', 'issued', 'assigned'];

const reference = useReferenceStore();
const schoolYears = useSchoolYearsStore();
const route = useRoute();

/**
 * Selected school year id, or '' for all school years. Starts on the school year
 * named by `?school_year=` (links from the School years page) or, failing that,
 * the current school year.
 */
const yearFilter = ref(ALL_YEARS);
let filterInitialised = false;

const requestedYear = Number(route.query.school_year);

const levelModal = ref(false);
const editingLevel = ref(null);

const subjectModal = ref(false);
const subjectLevel = ref(null);
const editingSubject = ref(null);

const deletingLevel = ref(null);
const deletingSubject = ref(null);
const removing = ref(false);

const initialLoading = computed(() => (reference.loading && !reference.loaded) || (schoolYears.loading && !schoolYears.loaded));

/** Free-text search over grade level names and subject names (case-insensitive). */
const search = ref('');

const searchTerm = computed(() => search.value.trim().toLowerCase());

const hasSearch = computed(() => searchTerm.value !== '');

const yearLevels = computed(() => reference.gradeLevelsFor(yearFilter.value));

function matches(text) {
    return String(text ?? '').toLowerCase().includes(searchTerm.value);
}

/**
 * Subjects to show for a level: all of them, unless a search is active and
 * only some subjects match (the level itself does not), in which case just those.
 */
const visibleSubjectsByLevel = computed(() => {
    const map = new Map();

    for (const level of yearLevels.value) {
        if (!hasSearch.value || matches(level.name)) {
            map.set(level.id, level.subjects);
            continue;
        }

        const matching = level.subjects.filter((subject) => matches(subject.name));

        if (matching.length > 0) {
            map.set(level.id, matching);
        }
    }

    return map;
});

/** Levels in the selected school year that match the search (by level name or a subject name). */
const filteredLevels = computed(() => yearLevels.value.filter((level) => visibleSubjectsByLevel.value.has(level.id)));

function visibleSubjects(level) {
    return visibleSubjectsByLevel.value.get(level.id) ?? level.subjects;
}

const filterYear = computed(() => schoolYears.byId(yearFilter.value));

const showingAll = computed(() => yearFilter.value === ALL_YEARS);

function clearSearch() {
    search.value = '';
}

/** Group the levels by school year (newest first) when showing all years. */
const groups = computed(() => {
    const map = new Map();

    for (const level of filteredLevels.value) {
        const key = level.schoolYearId ?? 'none';

        if (!map.has(key)) {
            map.set(key, { key, name: level.schoolYearName || 'No school year', levels: [] });
        }

        map.get(key).levels.push(level);
    }

    return [...map.values()];
});

const emptyMessage = computed(() => {
    if (!schoolYears.hasItems) {
        return 'Your school administrator has to register a school year before grade levels and subjects can be created for it.';
    }

    if (hasSearch.value && yearLevels.value.length > 0) {
        const scope = filterYear.value ? ` in SY ${filterYear.value.name}` : '';
        return `No grade levels or subjects match “${search.value.trim()}”${scope}.`;
    }

    if (filterYear.value) {
        return `No grade levels in SY ${filterYear.value.name} yet. Create the grade levels you teach this school year, then add their subjects.`;
    }

    return 'No grade levels yet. Create your first grade level, then add its subjects, to start registering students.';
});

watch(
    () => [schoolYears.loaded, schoolYears.current],
    ([loaded, current]) => {
        if (filterInitialised) {
            return;
        }

        if (requestedYear && loaded && schoolYears.byId(requestedYear)) {
            yearFilter.value = requestedYear;
            filterInitialised = true;
            return;
        }

        if (current && (!requestedYear || loaded)) {
            yearFilter.value = current.id;
            filterInitialised = true;
        }
    },
    { immediate: true },
);

function openCreateLevel() {
    editingLevel.value = null;
    levelModal.value = true;
}

function openEditLevel(level) {
    editingLevel.value = level;
    levelModal.value = true;
}

function openCreateSubject(level) {
    subjectLevel.value = level;
    editingSubject.value = null;
    subjectModal.value = true;
}

function openEditSubject(level, subject) {
    subjectLevel.value = level;
    editingSubject.value = subject;
    subjectModal.value = true;
}

function levelMeta(level) {
    const subjects = pluralize(level.subjects.length, 'subject');
    const students = pluralize(level.studentsCount ?? 0, 'student');
    return `${subjects} · ${students}`;
}

/** After creating a level in another school year, switch the filter so it is visible. */
function onLevelSaved(level) {
    if (!showingAll.value && level?.schoolYearId && Number(yearFilter.value) !== level.schoolYearId) {
        yearFilter.value = level.schoolYearId;
    }
}

async function confirmRemoveLevel() {
    if (removing.value || !deletingLevel.value) {
        return;
    }

    const target = deletingLevel.value;
    removing.value = true;

    try {
        await reference.removeGradeLevel(target.id);
        notify.success(`${target.name} deleted.`);
        deletingLevel.value = null;
    } catch (err) {
        notify.error(err?.message ?? 'Unable to delete the grade level.');
    } finally {
        removing.value = false;
    }
}

async function confirmRemoveSubject() {
    if (removing.value || !deletingSubject.value) {
        return;
    }

    const target = deletingSubject.value;
    removing.value = true;

    try {
        await reference.removeSubject(target.id);
        notify.success(`${target.name} deleted.`);
        deletingSubject.value = null;
    } catch (err) {
        notify.error(err?.message ?? 'Unable to delete the subject.');
    } finally {
        removing.value = false;
    }
}

onMounted(() => {
    schoolYears.load();
    reference.load(true);
});
</script>

<template>
    <div class="grade-levels">
        <div class="page-header">
            <div class="title-row">
                <h1>Grade levels</h1>
                <button type="button" class="btn" :disabled="!schoolYears.hasItems" @click="openCreateLevel">
                    Add grade level
                </button>
            </div>
            <p class="muted subtitle">
                Each school year has its own set of grade levels and subjects. Create the grade levels you
                teach in a school year, then add the subjects taught in each one.
            </p>
        </div>

        <!-- Filters on the left; the grade level count and the colour key for the bars on the right. -->
        <div class="toolbar">
            <div class="toolbar-controls">
                <input
                    v-model="search"
                    class="input search"
                    type="search"
                    placeholder="Search grade level or subject"
                    aria-label="Search grade levels and subjects"
                    :disabled="!schoolYears.hasItems"
                />
                <label class="year">
                    <span class="muted">School year</span>
                    <select v-model="yearFilter" class="input" aria-label="Filter by school year" :disabled="!schoolYears.hasItems">
                        <option :value="ALL_YEARS">All school years</option>
                        <option v-for="year in schoolYears.items" :key="year.id" :value="year.id">
                            {{ year.name }}{{ year.isCurrent ? ' (current)' : '' }}
                        </option>
                    </select>
                </label>
            </div>
            <div v-if="!initialLoading && filteredLevels.length > 0" class="toolbar-meta">
                <span class="muted count nowrap">{{ pluralize(filteredLevels.length, 'grade level') }}</span>
                <PaceRecordsLegend :order="RECORD_ORDER" />
            </div>
        </div>

        <p v-if="initialLoading" class="muted">Loading…</p>

        <EmptyState v-else-if="filteredLevels.length === 0" :message="emptyMessage">
            <template #action>
                <RouterLink v-if="!schoolYears.hasItems" :to="{ name: 'school-years' }" class="btn btn-secondary btn-sm">
                    School years
                </RouterLink>
                <button v-else-if="hasSearch && yearLevels.length > 0" type="button" class="btn btn-secondary btn-sm" @click="clearSearch">
                    Clear search
                </button>
                <button v-else type="button" class="btn btn-secondary btn-sm" @click="openCreateLevel">Add grade level</button>
            </template>
        </EmptyState>

        <template v-else>
            <div class="groups">
                <section v-for="group in groups" :key="group.key" class="group">
                    <h2 v-if="showingAll" class="group-title">
                        <span class="muted">School year</span> <span class="mono">{{ group.name }}</span>
                        <span class="muted group-count">· {{ pluralize(group.levels.length, 'grade level') }}</span>
                    </h2>

                    <div class="levels">
                        <section v-for="level in group.levels" :key="level.id" class="card level">
                            <div class="level-header">
                                <div>
                                    <h3 class="level-name">
                                        {{ level.name }}
                                        <span v-if="!showingAll" class="muted level-year mono">SY {{ level.schoolYearName }}</span>
                                    </h3>
                                    <p class="muted level-meta">
                                        {{ levelMeta(level) }}
                                        <template v-if="hasSearch && visibleSubjects(level).length !== level.subjects.length">
                                            · showing {{ visibleSubjects(level).length }} of {{ level.subjects.length }}
                                        </template>
                                    </p>
                                </div>
                                <div class="level-actions">
                                    <button type="button" class="btn btn-secondary btn-sm" @click="openCreateSubject(level)">
                                        Add subject
                                    </button>
                                    <RouterLink
                                        :to="{ name: 'grade-level-detail', params: { id: level.id } }"
                                        class="btn btn-link btn-sm btn-icon"
                                        :title="`View ${level.name} records`"
                                        :aria-label="`View ${level.name} records`"
                                    >
                                        <AppIcon name="eye" />
                                    </RouterLink>
                                    <button
                                        type="button"
                                        class="btn btn-link btn-sm btn-icon"
                                        :title="`Edit ${level.name}`"
                                        :aria-label="`Edit ${level.name}`"
                                        @click="openEditLevel(level)"
                                    >
                                        <AppIcon name="pencil" />
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-link btn-sm btn-icon text-danger"
                                        :title="level.hasStudents ? 'Move or delete its students first' : `Delete ${level.name}`"
                                        :aria-label="`Delete ${level.name}`"
                                        @click="deletingLevel = level"
                                    >
                                        <AppIcon name="trash" />
                                    </button>
                                </div>
                            </div>

                            <EmptyState v-if="visibleSubjects(level).length === 0" compact message="No subjects yet.">
                                <template #action>
                                    <button type="button" class="btn btn-link btn-sm" @click="openCreateSubject(level)">
                                        Add the first subject
                                    </button>
                                </template>
                            </EmptyState>

                            <table v-else class="table subjects">
                                <!-- Fixed column widths so the columns line up across every grade level card. -->
                                <colgroup>
                                    <col class="col-subject" />
                                    <col class="col-records" />
                                    <col class="col-actions" />
                                </colgroup>
                                <thead>
                                    <tr>
                                        <th>Subject</th>
                                        <th>PACE records</th>
                                        <th class="table-actions"><span class="sr-only">Actions</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="subject in visibleSubjects(level)" :key="subject.id">
                                        <td class="subject-name">{{ subject.name }}</td>
                                        <td class="records">
                                            <PaceRecordsBar
                                                :total="subject.assignmentsCount"
                                                :tested="subject.testedCount"
                                                :issued="subject.issuedCount"
                                                :assigned="subject.assignedCount"
                                                :order="RECORD_ORDER"
                                                tooltip
                                            />
                                        </td>
                                        <td class="table-actions">
                                            <RouterLink
                                                :to="{ name: 'subject-detail', params: { id: subject.id } }"
                                                class="btn btn-link btn-sm btn-icon"
                                                :title="`View ${subject.name} records`"
                                                :aria-label="`View ${subject.name} records`"
                                            >
                                                <AppIcon name="eye" />
                                            </RouterLink>
                                            <button
                                                type="button"
                                                class="btn btn-link btn-sm btn-icon"
                                                :title="`Edit ${subject.name}`"
                                                :aria-label="`Edit ${subject.name}`"
                                                @click="openEditSubject(level, subject)"
                                            >
                                                <AppIcon name="pencil" />
                                            </button>
                                            <button
                                                type="button"
                                                class="btn btn-link btn-sm btn-icon text-danger"
                                                :title="`Delete ${subject.name}`"
                                                :aria-label="`Delete ${subject.name}`"
                                                @click="deletingSubject = subject"
                                            >
                                                <AppIcon name="trash" />
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </section>
                    </div>
                </section>
            </div>
        </template>

        <GradeLevelFormModal
            v-model="levelModal"
            :grade-level="editingLevel"
            :school-year-id="showingAll ? null : yearFilter"
            @saved="onLevelSaved"
        />

        <SubjectFormModal v-model="subjectModal" :grade-level="subjectLevel" :subject="editingSubject" />

        <ConfirmDialog
            :model-value="deletingLevel !== null"
            title="Delete grade level"
            :busy="removing"
            @update:model-value="deletingLevel = null"
            @confirm="confirmRemoveLevel"
        >
            Delete <strong v-if="deletingLevel">{{ deletingLevel.labelWithYear }}</strong>?
            <template v-if="deletingLevel && deletingLevel.hasStudents">
                It still has {{ pluralize(deletingLevel.studentsCount, 'student') }} enrolled, so it cannot be deleted
                until they are moved to another grade level or removed.
            </template>
            <template v-else-if="deletingLevel && deletingLevel.subjects.length">
                Its {{ pluralize(deletingLevel.subjects.length, 'subject') }} and any PACE records filed under them
                will be deleted as well. This cannot be undone.
            </template>
            <template v-else>This cannot be undone.</template>
        </ConfirmDialog>

        <ConfirmDialog
            :model-value="deletingSubject !== null"
            title="Delete subject"
            :busy="removing"
            @update:model-value="deletingSubject = null"
            @confirm="confirmRemoveSubject"
        >
            Delete <strong v-if="deletingSubject">{{ deletingSubject.name }}</strong>?
            <template v-if="deletingSubject && deletingSubject.assignmentsCount">
                {{ pluralize(deletingSubject.assignmentsCount, 'PACE record') }} filed under it will be deleted as well.
            </template>
            This cannot be undone.
        </ConfirmDialog>
    </div>
</template>

<style scoped>
.grade-levels {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

/* Title and description take the whole width; the controls live in the toolbar below. */
.page-header {
    display: block;
    margin-bottom: 0;
}

.page-header h1 {
    margin: 0;
}

/* Title on the left, primary action on the right, on one line. */
.title-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}

.subtitle {
    margin: 4px 0 0;
}

.toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px 16px;
    flex-wrap: wrap;
    margin-top: -4px;
}

.toolbar-controls {
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

.count {
    font-size: 0.92rem;
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

.search {
    width: auto;
    flex: 1 1 200px;
    max-width: 260px;
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
    font-size: 1.05rem;
    font-weight: 600;
    margin: 0;
}

.level-year {
    margin-left: 8px;
    font-size: 0.85rem;
    font-weight: 400;
}

.level-meta {
    margin: 2px 0 0;
    font-size: 0.85rem;
}

.level-actions {
    display: flex;
    align-items: center;
    gap: 4px;
}

/*
 * Every grade level card shows its own table, so the column widths are fixed
 * (the subject column takes whatever is left) and the cards line up vertically.
 */
.subjects {
    table-layout: fixed;
}

.col-records {
    width: 160px;
}

.col-actions {
    width: 124px;
}

.subject-name {
    font-weight: 500;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.records {
    line-height: 1;
}

.subjects .table-actions .btn + .btn {
    margin-left: 2px;
}

@media (max-width: 560px) {
    .col-records {
        width: 120px;
    }
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
