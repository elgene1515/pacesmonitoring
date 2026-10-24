<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { useAuthStore } from '../../stores/auth';
import { useReferenceStore } from '../../stores/reference';
import { useSchoolYearsStore } from '../../stores/schoolYears';
import { useTeachingStore } from '../../stores/teaching';
import { pluralize } from '../../core/format';
import EmptyState from '../ui/EmptyState.vue';
import AppIcon from '../ui/AppIcon.vue';

/**
 * "My classes": the sections the teacher teaches, grouped by school year and
 * grade level, with the subjects they handle in each. The school admin sets
 * the curriculum up; a teacher asks for open slots on the Class requests page.
 */
const ALL_YEARS = '';

const auth = useAuthStore();
const reference = useReferenceStore();
const schoolYears = useSchoolYearsStore();
const teaching = useTeachingStore();
const route = useRoute();

/**
 * Selected school year id, or '' for all school years. Starts on the school year
 * named by `?school_year=` (links from the School years page) or, failing that,
 * the current school year.
 */
const yearFilter = ref(ALL_YEARS);
let filterInitialised = false;

const requestedYear = Number(route.query.school_year);

const initialLoading = computed(() => (reference.loading && !reference.loaded) || (schoolYears.loading && !schoolYears.loaded));

/** Free-text search over grade level, section and subject names (case-insensitive). */
const search = ref('');

const searchTerm = computed(() => search.value.trim().toLowerCase());

const hasSearch = computed(() => searchTerm.value !== '');

const yearLevels = computed(() => reference.gradeLevelsFor(yearFilter.value));

function matches(text) {
    return String(text ?? '').toLowerCase().includes(searchTerm.value);
}

/** The subjects the teacher handles in a section, in curriculum order. */
function mySubjects(level, section) {
    const ids = section.mySubjectIds ?? [];
    return level.subjects.filter((subject) => ids.includes(subject.id));
}

/** The other teachers of a section (everyone but the signed-in teacher). */
function coTeachers(section) {
    return section.teachers.filter((teacher) => Number(teacher.id) !== Number(auth.user?.id));
}

/**
 * Sections to show for a level: all of the teacher's, unless a search is active
 * and only some sections match (the level itself does not), in which case just those.
 */
const visibleSectionsByLevel = computed(() => {
    const map = new Map();

    for (const level of yearLevels.value) {
        if (!hasSearch.value || matches(level.name)) {
            map.set(level.id, level.sections);
            continue;
        }

        const matching = level.sections.filter((section) => matches(section.name)
            || mySubjects(level, section).some((subject) => matches(subject.name)));

        if (matching.length > 0) {
            map.set(level.id, matching);
        }
    }

    return map;
});

const filteredLevels = computed(() => yearLevels.value.filter((level) => visibleSectionsByLevel.value.has(level.id)));

function visibleSections(level) {
    return visibleSectionsByLevel.value.get(level.id) ?? level.sections;
}

const filterYear = computed(() => schoolYears.byId(yearFilter.value));

const showingAll = computed(() => yearFilter.value === ALL_YEARS);

const sectionCount = computed(() => filteredLevels.value.reduce((sum, level) => sum + visibleSections(level).length, 0));

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
        return 'Your school administrator has not registered a school year yet.';
    }

    if (hasSearch.value && yearLevels.value.length > 0) {
        const scope = filterYear.value ? ` in SY ${filterYear.value.name}` : '';
        return `No classes match “${search.value.trim()}”${scope}.`;
    }

    if (filterYear.value) {
        return `You are not teaching any class in SY ${filterYear.value.name} yet. Your school admin assigns classes; you can also ask for an open one.`;
    }

    return 'You are not teaching any class yet. Your school admin assigns classes; you can also ask for an open one.';
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

function levelMeta(level) {
    const sections = pluralize(level.sections.length, 'section');
    const students = pluralize(level.sections.reduce((sum, section) => sum + Number(section.studentsCount ?? 0), 0), 'student');

    return `${sections} you teach · ${students}`;
}

onMounted(() => {
    schoolYears.load();
    reference.load(true);
    teaching.fetch();
});
</script>

<template>
    <div class="grade-levels">
        <div class="page-header">
            <div class="title-row">
                <h1>My classes</h1>
                <RouterLink :to="{ name: 'teaching-requests' }" class="btn">
                    Request a class
                    <span v-if="teaching.pendingCount > 0" class="count-badge" :title="pluralize(teaching.pendingCount, 'pending request')">
                        {{ teaching.pendingCount }}
                    </span>
                </RouterLink>
            </div>
            <p class="muted subtitle">
                The sections you teach and the subjects you handle in each. Your school admin sets up the grade levels,
                subjects and sections; ask for an open class when you take on another one.
            </p>
        </div>

        <div class="toolbar">
            <div class="toolbar-controls">
                <input
                    v-model="search"
                    class="input search"
                    type="search"
                    placeholder="Search grade level, section or subject"
                    aria-label="Search classes"
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
                <span class="muted count nowrap">{{ pluralize(sectionCount, 'section') }}</span>
            </div>
        </div>

        <p v-if="initialLoading" class="muted">Loading…</p>

        <EmptyState v-else-if="filteredLevels.length === 0" :message="emptyMessage">
            <template #action>
                <button v-if="hasSearch && yearLevels.length > 0" type="button" class="btn btn-secondary btn-sm" @click="clearSearch">
                    Clear search
                </button>
                <RouterLink v-else-if="schoolYears.hasItems" :to="{ name: 'teaching-requests' }" class="btn btn-secondary btn-sm">
                    See open classes
                </RouterLink>
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
                                    <p class="muted level-meta">{{ levelMeta(level) }}</p>
                                </div>
                                <div class="level-actions">
                                    <RouterLink
                                        :to="{ name: 'grade-level-detail', params: { id: level.id } }"
                                        class="btn btn-link btn-sm btn-icon"
                                        :title="`View ${level.name} records`"
                                        :aria-label="`View ${level.name} records`"
                                    >
                                        <AppIcon name="eye" />
                                    </RouterLink>
                                </div>
                            </div>

                            <table class="table sections">
                                <colgroup>
                                    <col class="col-section" />
                                    <col class="col-subjects" />
                                    <col class="col-students" />
                                    <col class="col-actions" />
                                </colgroup>
                                <thead>
                                    <tr>
                                        <th>Section</th>
                                        <th>Your subjects</th>
                                        <th class="text-right">Students</th>
                                        <th class="table-actions"><span class="sr-only">Actions</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="section in visibleSections(level)" :key="section.id">
                                        <td class="ellipsis">
                                            <RouterLink :to="{ name: 'section-detail', params: { id: section.id } }" class="section-name">
                                                {{ section.name }}
                                            </RouterLink>
                                            <span v-if="coTeachers(section).length" class="muted small">
                                                With {{ coTeachers(section).map((teacher) => teacher.name).join(', ') }}
                                            </span>
                                        </td>
                                        <td class="subjects-cell">
                                            <span v-if="mySubjects(level, section).length === 0" class="muted">—</span>
                                            <template v-else>
                                                <RouterLink
                                                    v-for="subject in mySubjects(level, section)"
                                                    :key="subject.id"
                                                    :to="{ name: 'subject-detail', params: { id: subject.id } }"
                                                    class="subject-chip"
                                                >
                                                    {{ subject.name }}
                                                </RouterLink>
                                            </template>
                                        </td>
                                        <td class="mono text-right">{{ section.studentsCount ?? 0 }}</td>
                                        <td class="table-actions">
                                            <RouterLink
                                                :to="{ name: 'section-detail', params: { id: section.id } }"
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
        </template>
    </div>
</template>

<style scoped>
.grade-levels {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.page-header {
    display: block;
    margin-bottom: 0;
}

.page-header h1 {
    margin: 0;
}

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

.count-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 18px;
    height: 18px;
    padding: 0 5px;
    margin-left: 4px;
    border-radius: 999px;
    background: var(--surface);
    color: var(--primary);
    font-size: 0.75rem;
    font-weight: 600;
    line-height: 1;
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
    max-width: 280px;
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

.sections {
    table-layout: fixed;
}

.col-section {
    width: 32%;
}

.col-students {
    width: 100px;
}

.col-actions {
    width: 60px;
}

.section-name {
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

.subjects-cell {
    line-height: 1.9;
}

.subject-chip {
    display: inline-block;
    margin: 0 6px 0 0;
    padding: 0 8px;
    border: 1px solid var(--border);
    border-radius: 999px;
    font-size: 0.85rem;
    line-height: 1.6;
    white-space: nowrap;
}

.subject-chip:hover {
    text-decoration: none;
    border-color: var(--primary);
}

@media (max-width: 560px) {
    .col-section {
        width: 40%;
    }

    .col-students {
        width: 80px;
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
