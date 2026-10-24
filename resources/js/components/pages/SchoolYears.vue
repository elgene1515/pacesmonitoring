<script setup>
import { computed, onMounted, ref } from 'vue';
import { useAuthStore } from '../../stores/auth';
import { useSchoolYearsStore } from '../../stores/schoolYears';
import { notify } from '../../stores/notifications';
import { formatDate } from '../../core/format';
import SchoolYearFormModal from '../schoolYears/SchoolYearFormModal.vue';
import EmptyState from '../ui/EmptyState.vue';
import AppIcon from '../ui/AppIcon.vue';

/**
 * The organization's school years. An admin registers and edits them and sets
 * the organization's default; a teacher sees the same list and picks the year
 * they are working in (the star), which only changes their own view.
 */
const auth = useAuthStore();
const schoolYears = useSchoolYearsStore();

const isAdmin = computed(() => auth.isAdmin);
const canWrite = computed(() => auth.canWrite);

const modalOpen = ref(false);
const editing = ref(null);
const settingCurrent = ref(null);

const initialLoading = computed(() => schoolYears.loading && !schoolYears.loaded);

const subtitle = computed(() => (isAdmin.value
    ? 'Register the school years your school runs. Every teacher picks from this list; the starred year is the default new teachers start on.'
    : 'School years are set up by your school administrator. Star the one you are working in: new students and PACEs are filed under it and the Home page totals follow it.'));

const emptyMessage = computed(() => (isAdmin.value
    ? 'No school years yet. Register the current school year so teachers can start filing students and PACE records under it.'
    : 'Your school administrator has not registered a school year yet.'));

function openCreate() {
    editing.value = null;
    modalOpen.value = true;
}

function openEdit(year) {
    editing.value = year;
    modalOpen.value = true;
}

function period(year) {
    const start = formatDate(year.startDate);
    const end = formatDate(year.endDate);

    if (start && end) {
        return `${start} – ${end}`;
    }

    return start || end || '—';
}

/** Admin: make it the organization's default. Teacher: make it my current year. */
async function makeCurrent(year) {
    if (settingCurrent.value || year.isCurrent) {
        return;
    }

    settingCurrent.value = year.id;

    try {
        if (isAdmin.value) {
            await schoolYears.update(year.id, { is_current: true });
            notify.success(`${year.label} is now the school's default school year.`);
        } else {
            await schoolYears.selectCurrent(year.id);
            notify.success(`You are now working in ${year.label}.`);
        }
    } catch (err) {
        notify.error(err?.message ?? 'Unable to change the current school year.');
    } finally {
        settingCurrent.value = null;
    }
}

function starTitle(year) {
    if (year.isCurrent) {
        return isAdmin.value ? "The school's default school year" : 'Your current school year';
    }

    if (settingCurrent.value === year.id) {
        return 'Saving…';
    }

    return isAdmin.value ? `Make SY ${year.name} the school's default` : `Work in SY ${year.name}`;
}

onMounted(() => {
    schoolYears.load(true);
});
</script>

<template>
    <div class="school-years">
        <div class="page-header">
            <div>
                <h1>School years</h1>
                <p class="muted subtitle">{{ subtitle }}</p>
            </div>
            <div v-if="isAdmin" class="page-actions">
                <button type="button" class="btn" :disabled="!canWrite" :title="canWrite ? '' : 'Read-only: the subscription is not active'" @click="openCreate">
                    Add school year
                </button>
            </div>
        </div>

        <div class="card">
            <p v-if="initialLoading" class="muted state">Loading…</p>

            <EmptyState v-else-if="schoolYears.items.length === 0" :message="emptyMessage">
                <template v-if="isAdmin && canWrite" #action>
                    <button type="button" class="btn btn-secondary btn-sm" @click="openCreate">Add school year</button>
                </template>
            </EmptyState>

            <div v-else class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>School year</th>
                            <th>Period</th>
                            <th class="text-right">Grade levels</th>
                            <th class="text-right">Students</th>
                            <th class="text-right">PACE records</th>
                            <th class="table-actions"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="year in schoolYears.items" :key="year.id">
                            <td>
                                <span class="name mono">{{ year.name }}</span>
                                <span v-if="year.isCurrent" class="badge">{{ isAdmin ? 'Default' : 'Current' }}</span>
                                <span v-if="!isAdmin && year.isOrganizationDefault && !year.isCurrent" class="badge subtle" title="New teachers start on this school year">School default</span>
                            </td>
                            <td class="nowrap">{{ period(year) }}</td>
                            <td class="mono text-right">
                                <RouterLink
                                    v-if="!isAdmin && year.gradeLevelsCount"
                                    :to="{ name: 'classes', query: { school_year: year.id } }"
                                    :title="`View your classes in SY ${year.name}`"
                                >
                                    {{ year.gradeLevelsCount }}
                                </RouterLink>
                                <RouterLink
                                    v-else-if="isAdmin && year.gradeLevelsCount"
                                    :to="{ name: 'admin-grade-levels', query: { school_year: year.id } }"
                                    :title="`View the grade levels of SY ${year.name}`"
                                >
                                    {{ year.gradeLevelsCount }}
                                </RouterLink>
                                <span v-else>{{ year.gradeLevelsCount ?? '—' }}</span>
                            </td>
                            <td class="mono text-right">
                                <RouterLink
                                    v-if="!isAdmin && year.studentsCount"
                                    :to="{ name: 'students', query: { school_year: year.id } }"
                                    :title="`View the students in SY ${year.name}`"
                                >
                                    {{ year.studentsCount }}
                                </RouterLink>
                                <RouterLink
                                    v-else-if="isAdmin && year.studentsCount"
                                    :to="{ name: 'admin-students' }"
                                    :title="`${year.studentsCount} students enrolled in SY ${year.name}`"
                                >
                                    {{ year.studentsCount }}
                                </RouterLink>
                                <span v-else>{{ year.studentsCount ?? '—' }}</span>
                            </td>
                            <td class="mono text-right">
                                <RouterLink
                                    v-if="!isAdmin && year.assignmentsCount"
                                    :to="{ name: 'paces' }"
                                    :title="`View the PACEs (${year.assignmentsCount} records filed in SY ${year.name})`"
                                >
                                    {{ year.assignmentsCount }}
                                </RouterLink>
                                <span v-else>{{ year.assignmentsCount ?? '—' }}</span>
                            </td>
                            <td class="table-actions">
                                <span
                                    v-if="year.isCurrent"
                                    class="btn btn-link btn-sm btn-icon is-current"
                                    :title="starTitle(year)"
                                    :aria-label="starTitle(year)"
                                >
                                    <AppIcon name="star-filled" />
                                </span>
                                <button
                                    v-else
                                    type="button"
                                    class="btn btn-link btn-sm btn-icon"
                                    :disabled="settingCurrent !== null || (isAdmin && !canWrite)"
                                    :title="starTitle(year)"
                                    :aria-label="starTitle(year)"
                                    @click="makeCurrent(year)"
                                >
                                    <AppIcon name="star" />
                                </button>
                                <button
                                    v-if="isAdmin"
                                    type="button"
                                    class="btn btn-link btn-sm btn-icon"
                                    :disabled="!canWrite"
                                    :title="`Edit SY ${year.name}`"
                                    :aria-label="`Edit SY ${year.name}`"
                                    @click="openEdit(year)"
                                >
                                    <AppIcon name="pencil" />
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <SchoolYearFormModal v-if="isAdmin" v-model="modalOpen" :school-year="editing" />
    </div>
</template>

<style scoped>
.school-years {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.page-header {
    margin-bottom: 0;
}

.subtitle {
    margin: 4px 0 0;
    max-width: 680px;
}

.card {
    padding: 0;
    overflow: hidden;
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
    min-width: 640px;
}

.name {
    font-weight: 500;
}

/* Filled star marking the current year: looks like the action buttons but is not clickable. */
.is-current {
    color: var(--success);
    cursor: default;
}

.is-current:hover {
    background: transparent;
}

.badge {
    display: inline-block;
    margin-left: 8px;
    padding: 1px 8px;
    border-radius: 999px;
    font-size: 0.78rem;
    color: var(--success);
    background: rgba(var(--success-rgb), 0.1);
    border: 1px solid rgba(var(--success-rgb), 0.35);
    vertical-align: middle;
}

.badge.subtle {
    color: var(--muted);
    background: transparent;
    border-color: var(--border);
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
