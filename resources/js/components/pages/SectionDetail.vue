<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { adminService, referenceService } from '../../services';
import { useAuthStore } from '../../stores/auth';
import { useTeachingStore } from '../../stores/teaching';
import { notify } from '../../stores/notifications';
import { SEX_OPTIONS } from '../../models';
import { formatDate, formatScore, pluralize } from '../../core/format';
import StudentFormModal from '../students/StudentFormModal.vue';
import SubjectFormModal from '../gradeLevels/SubjectFormModal.vue';
import PromoteSectionModal from '../sections/PromoteSectionModal.vue';
import TeachingRequestModal from '../teaching/TeachingRequestModal.vue';
import ConfirmDialog from '../ui/ConfirmDialog.vue';
import EmptyState from '../ui/EmptyState.vue';
import StatusBadge from '../ui/StatusBadge.vue';
import AppIcon from '../ui/AppIcon.vue';
import PaceRecordsBar from '../charts/PaceRecordsBar.vue';
import PaceRecordsLegend from '../charts/PaceRecordsLegend.vue';

/**
 * One section (class): who teaches each subject and the roster.
 *
 * Teachers see a section they teach in, enroll students into it and ask for
 * the subjects nobody teaches yet. The admin view (`admin`) assigns a teacher
 * to each subject, renames or deletes the section and enrolls students.
 */
const props = defineProps({
    id: { type: Number, required: true },
    admin: { type: Boolean, default: false },
});

const RECORD_ORDER = ['tested', 'issued', 'assigned'];

const auth = useAuthStore();
const teaching = useTeachingStore();
const router = useRouter();

const section = ref(null);
const loading = ref(true);
const loadError = ref('');

const enrollOpen = ref(false);
const editOpen = ref(false);
/** Admin: the year-end promotion dialog. */
const promoteOpen = ref(false);
const deleting = ref(false);
const removing = ref(false);

const requestOpen = ref(false);
const requestSubject = ref(null);

/** Admin: the branch's teachers for the assignment selects, and the row being saved. */
const teachers = ref([]);
const savingSubjectId = ref(null);

const gradeLevel = computed(() => section.value?.gradeLevel ?? null);
/** The admin sees every subject of the grade level (to offer or withdraw them); a teacher only the offered ones. */
const subjects = computed(() => (props.admin ? (gradeLevel.value?.subjects ?? []) : (section.value?.offeredSubjects ?? [])));
const students = computed(() => section.value?.students ?? []);
const myId = computed(() => Number(auth.user?.id ?? 0));

const studentRoute = computed(() => (props.admin ? 'admin-student-detail' : 'student-detail'));

/* ---------- roster filters (the same ones as the Students page) ---------- */

/** Search by name or student ID; '' (any) | 'male' | 'female'; the subject a student takes; an average-score range. */
const search = ref('');
const sexFilter = ref('');
const subjectFilter = ref('');
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

/** The subjects the section offers, for the subject filter (students of one section take different ones). */
const subjectOptions = computed(() => subjects.value.filter((subject) => section.value?.offers(subject.id)));

const hasRosterFilters = computed(() => search.value.trim() !== ''
    || sexFilter.value !== ''
    || subjectFilter.value !== ''
    || scoreRange.value.min !== null
    || scoreRange.value.max !== null);

/** The roster after the filters; the search matches the name or the student ID (part of either). */
const visibleStudents = computed(() => {
    const needle = search.value.trim().toLowerCase();
    const { min, max } = scoreRange.value;

    return students.value.filter((student) => {
        if (needle !== '') {
            const haystack = [student.studentNumber, student.fullName, student.displayName, student.firstName, student.middleName, student.lastName]
                .filter(Boolean)
                .join(' ')
                .toLowerCase();

            if (!haystack.includes(needle)) {
                return false;
            }
        }

        if (sexFilter.value !== '' && student.sex !== sexFilter.value) {
            return false;
        }

        if (subjectFilter.value !== '' && !student.takes(subjectFilter.value)) {
            return false;
        }

        if (min !== null || max !== null) {
            const score = student.averageScore;

            if (score === null || score === undefined || (min !== null && score < min) || (max !== null && score > max)) {
                return false;
            }
        }

        return true;
    });
});

function clearRosterFilters() {
    search.value = '';
    sexFilter.value = '';
    subjectFilter.value = '';
    scoreMin.value = '';
    scoreMax.value = '';
}

/** "School · Grade 1 · Section 1": the platform owner looks at sections of many schools, so the school leads. */
const heading = computed(() => {
    const school = auth.isPlatform ? section.value?.organizationName : '';

    return school ? `${school} · ${section.value.label}` : (section.value?.label ?? '');
});

const subtitle = computed(() => {
    if (!section.value) {
        return '';
    }

    return [
        gradeLevel.value?.branchName,
        gradeLevel.value?.schoolYearName ? `SY ${gradeLevel.value.schoolYearName}` : '',
        pluralize(section.value.studentsCount ?? students.value.length, 'student'),
        pluralize(section.value.teachers.length, 'teacher'),
    ].filter(Boolean).join(' · ');
});

/** Offered subjects with nobody assigned, for the admin's hint. */
const openSubjects = computed(() => subjects.value.filter((subject) => section.value?.offers(subject.id) && !section.value?.assignmentFor(subject.id)));

/** How many of the section's students take a subject (admins see everyone; a teacher only their own students). */
function takers(subject) {
    return students.value.filter((student) => student.takes(subject.id)).length;
}

function teacherIdFor(subject) {
    return section.value?.assignmentFor(subject.id)?.teacherId ?? '';
}

/** The select's value: the teacher's id, '' for nobody yet, or 'off' when the section does not offer the subject. */
function cellValue(subject) {
    return section.value?.offers(subject.id) ? teacherIdFor(subject) : 'off';
}

async function load() {
    loading.value = true;
    loadError.value = '';

    try {
        section.value = props.admin ? await adminService.getSection(props.id) : await referenceService.section(props.id);

        if (props.admin) {
            loadTeachers();
        }
    } catch (err) {
        section.value = null;
        loadError.value = err?.isNotFound ? 'This section could not be found.' : (err?.message ?? 'Failed to load the section.');
    } finally {
        loading.value = false;
    }
}

async function loadTeachers() {
    try {
        teachers.value = await adminService.listTeachers({ branch_id: gradeLevel.value?.branchId ?? '' });
    } catch (err) {
        notify.error(err?.message ?? 'Failed to load the teachers.');
    }
}

/** Admin: pick (or clear) the teacher of one subject in this section. */
async function assign(subject, value) {
    if (savingSubjectId.value !== null) {
        return;
    }

    if (String(value) === String(cellValue(subject))) {
        return;
    }

    const offered = section.value.offers(subject.id);

    savingSubjectId.value = subject.id;

    try {
        if (value === 'off') {
            await adminService.withdrawSubject(section.value.id, subject.id);
            notify.info(`${subject.name} is no longer offered in ${section.value.label}.`);
        } else {
            if (!offered) {
                await adminService.offerSubject(section.value.id, subject.id);
            }

            if (value === '' || value === null) {
                if (offered) {
                    await adminService.unassignTeacher(section.value.id, subject.id);
                    notify.info(`Nobody teaches ${subject.name} in ${section.value.label} now.`);
                } else {
                    notify.info(`${section.value.label} offers ${subject.name} now.`);
                }
            } else {
                const assignment = await adminService.assignTeacher(section.value.id, subject.id, value);
                notify.success(`${assignment.teacherName || 'The teacher'} now teaches ${subject.name} in ${section.value.label}.`);
            }
        }

        await load();
    } catch (err) {
        notify.error(err?.isValidation ? (err.first('teacher_id') ?? err.message) : (err?.message ?? 'Unable to change the teacher.'));
        await load();
    } finally {
        savingSubjectId.value = null;
    }
}

function openRequest(subject) {
    requestSubject.value = subject;
    requestOpen.value = true;
}

function onSectionSaved() {
    load();
}

async function confirmRemove() {
    if (removing.value || !section.value) {
        return;
    }

    removing.value = true;

    try {
        const levelId = section.value.gradeLevelId;
        await adminService.removeSection(section.value.id);
        notify.success(`${section.value.label} deleted.`);
        deleting.value = false;
        router.push({ name: 'admin-grade-level-detail', params: { id: levelId } });
    } catch (err) {
        notify.error(err?.isValidation ? (err.first('section') ?? err.message) : (err?.message ?? 'Unable to delete the section.'));
    } finally {
        removing.value = false;
    }
}

watch(() => props.id, load);

onMounted(() => {
    load();

    if (!props.admin) {
        teaching.fetch();
    }
});
</script>

<template>
    <div class="section-detail">
        <p class="back">
            <RouterLink v-if="admin && section?.gradeLevelId" :to="{ name: 'admin-grade-level-detail', params: { id: section.gradeLevelId } }">
                &larr; {{ gradeLevel?.name || 'Grade level' }}
            </RouterLink>
            <RouterLink v-else-if="admin" :to="{ name: 'admin-grade-levels' }">&larr; Grade Levels</RouterLink>
            <RouterLink v-else :to="{ name: 'classes' }">&larr; My classes</RouterLink>
        </p>

        <p v-if="loading && !section" class="muted">Loading…</p>

        <EmptyState v-else-if="!section" :message="loadError">
            <template #action>
                <RouterLink :to="{ name: admin ? 'admin-grade-levels' : 'classes' }" class="btn btn-secondary btn-sm">
                    {{ admin ? 'Back to grade levels' : 'Back to my classes' }}
                </RouterLink>
            </template>
        </EmptyState>

        <template v-else>
            <div class="page-header">
                <div>
                    <h1>{{ heading }}</h1>
                    <p class="muted subtitle">{{ subtitle }}</p>
                </div>
                <div class="page-actions">
                    <button type="button" class="btn" :disabled="!auth.canWrite" @click="enrollOpen = true">Enroll student</button>
                    <template v-if="admin">
                        <button v-if="students.length" type="button" class="btn btn-secondary" :disabled="!auth.canWrite" @click="promoteOpen = true">Promote students</button>
                        <button type="button" class="btn btn-secondary" :disabled="!auth.canWrite" @click="editOpen = true">Edit</button>
                        <button type="button" class="btn btn-secondary text-danger" :disabled="!auth.canWrite" @click="deleting = true">Delete</button>
                    </template>
                </div>
            </div>

            <section class="card">
                <div class="card-header">
                    <h2 class="card-title">Subjects and teachers</h2>
                    <span v-if="admin && openSubjects.length" class="muted small-note">
                        {{ pluralize(openSubjects.length, 'subject') }} without a teacher
                    </span>
                </div>

                <EmptyState v-if="subjects.length === 0" compact message="This grade level has no subjects yet.">
                    <template v-if="admin && section.gradeLevelId" #action>
                        <RouterLink :to="{ name: 'admin-grade-level-detail', params: { id: section.gradeLevelId } }" class="btn btn-link btn-sm">
                            Add subjects to {{ gradeLevel?.name }}
                        </RouterLink>
                    </template>
                </EmptyState>

                <table v-else class="table subjects">
                    <colgroup>
                        <col class="col-subject" />
                        <col />
                        <col v-if="admin" class="col-takers" />
                        <col v-if="!admin" class="col-action" />
                    </colgroup>
                    <thead>
                        <tr>
                            <th>Subject</th>
                            <th>Teacher</th>
                            <th v-if="admin" class="text-right">Students taking it</th>
                            <th v-if="!admin" class="table-actions"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="subject in subjects" :key="subject.id">
                            <td class="ellipsis subject-name">
                                <RouterLink
                                    v-if="admin || section.teacherFor(subject.id)?.id === myId"
                                    :to="{ name: admin ? 'admin-subject-detail' : 'subject-detail', params: { id: subject.id } }"
                                >
                                    {{ subject.name }}
                                </RouterLink>
                                <span v-else>{{ subject.name }}</span>
                            </td>
                            <td v-if="admin">
                                <select
                                    class="input teacher-select"
                                    :value="cellValue(subject)"
                                    :aria-label="`Teacher of ${subject.name}`"
                                    :disabled="!auth.canWrite || savingSubjectId !== null"
                                    @change="assign(subject, $event.target.value)"
                                >
                                    <option value="off">Not offered in this section</option>
                                    <option value="">Offered, nobody teaches it yet</option>
                                    <option v-for="teacher in teachers" :key="teacher.id" :value="teacher.id">{{ teacher.name }}</option>
                                    <option
                                        v-if="section.teacherFor(subject.id) && !teachers.some((teacher) => teacher.id === section.teacherFor(subject.id).id)"
                                        :value="section.teacherFor(subject.id).id"
                                    >
                                        {{ section.teacherFor(subject.id).name }}
                                    </option>
                                </select>
                                <span v-if="savingSubjectId === subject.id" class="muted saving">Saving…</span>
                            </td>
                            <td v-else class="ellipsis">
                                <span v-if="section.teacherFor(subject.id)?.id === myId" class="mine">You</span>
                                <span v-else-if="section.teacherFor(subject.id)">{{ section.teacherFor(subject.id).name }}</span>
                                <span v-else class="muted">Nobody yet</span>
                            </td>
                            <td v-if="admin" class="mono text-right">{{ takers(subject) }} / {{ students.length }}</td>
                            <td v-if="!admin" class="table-actions">
                                <StatusBadge v-if="teaching.pendingFor(section.id, subject.id)" tone="warn">Requested</StatusBadge>
                                <button
                                    v-else-if="!section.teacherFor(subject.id)"
                                    type="button"
                                    class="btn btn-secondary btn-sm"
                                    :disabled="!auth.canWrite"
                                    @click="openRequest(subject)"
                                >
                                    Request
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <section class="card">
                <div class="card-header">
                    <h2 class="card-title">Students</h2>
                </div>

                <!-- Filters on the left; the student count and the colour key for the bars on the right. -->
                <div v-if="students.length" class="toolbar">
                    <div class="toolbar-controls">
                        <input
                            v-model="search"
                            class="input search"
                            type="search"
                            placeholder="Search by name or student ID"
                            aria-label="Search students by name or student ID"
                        />
                        <select v-if="subjectOptions.length > 1" v-model="subjectFilter" class="input select" aria-label="Filter by subject taken">
                            <option value="">All subjects</option>
                            <option v-for="subject in subjectOptions" :key="subject.id" :value="subject.id">{{ subject.name }}</option>
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
                        <button v-if="hasRosterFilters" type="button" class="btn btn-link btn-sm" @click="clearRosterFilters">Clear</button>
                    </div>
                    <div class="toolbar-meta">
                        <span class="muted count nowrap">{{ pluralize(visibleStudents.length, 'student') }}</span>
                        <PaceRecordsLegend v-if="visibleStudents.length > 0" :order="RECORD_ORDER" />
                    </div>
                </div>

                <EmptyState v-if="students.length === 0" compact message="No students enrolled in this section yet.">
                    <template #action>
                        <button type="button" class="btn btn-link btn-sm" :disabled="!auth.canWrite" @click="enrollOpen = true">Enroll the first student</button>
                    </template>
                </EmptyState>

                <EmptyState v-else-if="visibleStudents.length === 0" compact message="No students match your filters.">
                    <template #action>
                        <button type="button" class="btn btn-link btn-sm" @click="clearRosterFilters">Clear filters</button>
                    </template>
                </EmptyState>

                <div v-else class="table-wrap">
                    <table class="table roster">
                        <colgroup>
                            <col class="col-student-id" />
                            <col />
                            <col class="col-student-subjects" />
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
                                <th>Subjects</th>
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
                                <td class="ellipsis">
                                    <RouterLink :to="{ name: studentRoute, params: { id: student.id } }" class="name">{{ student.fullName }}</RouterLink>
                                </td>
                                <td class="ellipsis muted" :title="student.subjectNames">{{ student.subjectNames || '—' }}</td>
                                <td>{{ student.sexLabel || '—' }}</td>
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
                                <td class="mono text-right" :title="student.averageScore === null ? '' : 'Across all PACE records this school year; untested PACEs count as 0'">
                                    <span v-if="student.averageScore !== null">{{ formatScore(student.averageScore) }}</span>
                                    <span v-else class="muted">—</span>
                                </td>
                                <td class="table-actions">
                                    <RouterLink
                                        :to="{ name: studentRoute, params: { id: student.id } }"
                                        class="btn btn-link btn-sm btn-icon"
                                        :title="`View ${student.displayName}`"
                                        :aria-label="`View ${student.displayName}`"
                                    >
                                        <AppIcon name="eye" />
                                    </RouterLink>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <StudentFormModal
                v-model="enrollOpen"
                :section-id="section.id"
                :admin="admin"
                :sections="admin ? [section] : null"
                @saved="load"
            />

            <template v-if="admin">
                <SubjectFormModal v-model="editOpen" kind="section" :grade-level="gradeLevel" :record="section" @saved="onSectionSaved" />
                <PromoteSectionModal v-model="promoteOpen" :section="section" :students="students" @done="load" />

                <ConfirmDialog
                    :model-value="deleting"
                    title="Delete section"
                    :busy="removing"
                    @update:model-value="deleting = false"
                    @confirm="confirmRemove"
                >
                    Delete <strong>{{ section.label }}</strong>?
                    <template v-if="section.hasStudents">
                        It still has {{ pluralize(section.studentsCount ?? students.length, 'student') }} enrolled, so it cannot be
                        deleted until they are moved to another section or removed.
                    </template>
                    <template v-else>Its teaching assignments go with it. This cannot be undone.</template>
                </ConfirmDialog>
            </template>

            <TeachingRequestModal v-else v-model="requestOpen" :section="section" :subject="requestSubject" />
        </template>
    </div>
</template>

<style scoped>
.section-detail {
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

.card {
    padding: 0;
    overflow: hidden;
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

.small-note {
    font-size: 0.85rem;
}

.subjects {
    table-layout: fixed;
}

.col-subject {
    width: 34%;
}

.col-action {
    width: 120px;
}

.col-takers {
    width: 170px;
}

.col-student-subjects {
    width: 170px;
}

.subject-name {
    font-weight: 500;
}

.teacher-select {
    width: auto;
    min-width: 200px;
    max-width: 100%;
    padding-top: 4px;
    padding-bottom: 4px;
}

.saving {
    margin-left: 8px;
    font-size: 0.85rem;
}

.mine {
    font-weight: 500;
    color: var(--primary);
}

.table-wrap {
    overflow-x: auto;
}

.roster {
    table-layout: fixed;
    min-width: 1040px;
}

.col-student-id {
    width: 100px;
}

.col-average {
    width: 110px;
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

.col-sex {
    width: 70px;
}

.col-age {
    width: 55px;
}

.col-birth {
    width: 110px;
}

.col-records {
    width: 130px;
}

.col-actions {
    width: 50px;
}

.name {
    font-weight: 500;
}

.records {
    line-height: 1;
}

.ellipsis {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
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
