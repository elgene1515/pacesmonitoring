<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { adminService, referenceService } from '../../services';
import { useAuthStore } from '../../stores/auth';
import { notify } from '../../stores/notifications';
import { formatScore, pluralize } from '../../core/format';
import { buildRecordRings } from '../../core/recordRings';
import GradeLevelFormModal from '../gradeLevels/GradeLevelFormModal.vue';
import SubjectFormModal from '../gradeLevels/SubjectFormModal.vue';
import ConfirmDialog from '../ui/ConfirmDialog.vue';
import EmptyState from '../ui/EmptyState.vue';
import AppIcon from '../ui/AppIcon.vue';
import LayeredRings from '../charts/LayeredRings.vue';
import PaceRecordsBar from '../charts/PaceRecordsBar.vue';
import PaceRecordsLegend from '../charts/PaceRecordsLegend.vue';
import PaceRecordsExplorer from '../assignments/PaceRecordsExplorer.vue';

/**
 * A grade level: its subjects, its sections with who teaches what, and every
 * PACE record under it.
 *
 * Teachers see the sections they teach (read-only). The admin view (`admin`)
 * is where the curriculum is kept: add, rename and remove subjects and
 * sections, and pick the teacher of each subject in each section.
 */
const props = defineProps({
    id: { type: Number, required: true },
    admin: { type: Boolean, default: false },
});

const subjectRoute = computed(() => (props.admin ? 'admin-subject-detail' : 'subject-detail'));
const sectionRoute = computed(() => (props.admin ? 'admin-section-detail' : 'section-detail'));

/** Legend / bar order shared with the other pages: tested / issued / assigned. */
const RECORD_ORDER = ['tested', 'issued', 'assigned'];

const auth = useAuthStore();
const router = useRouter();

const level = ref(null);
const loading = ref(true);
const loadError = ref('');

/* Admin editing */
const editOpen = ref(false);
const recordModal = ref(false);
const recordKind = ref('subject');
const editingRecord = ref(null);
const deleting = ref(null); // { kind: 'level'|'subject'|'section', record }
const removing = ref(false);
const teachers = ref([]);
const savingCell = ref('');

const records = computed(() => level.value?.assignments ?? []);
const subjects = computed(() => level.value?.subjects ?? []);
const sections = computed(() => level.value?.sections ?? []);
const myId = computed(() => Number(auth.user?.id ?? 0));

/** Layered rings (assigned / issued / tested / pass / retakes / fail) for every record under the grade level. */
const recordRings = computed(() => buildRecordRings(records.value));

/** Average across every record; PACEs without a score count as 0. */
const averageAll = computed(() => {
    if (records.value.length === 0) {
        return null;
    }

    const sum = records.value.reduce((carry, record) => carry + (Number(record.score) || 0), 0);

    return sum / records.value.length;
});

/** Slots (section × subject the section offers) nobody teaches yet. */
const openSlots = computed(() => sections.value.reduce(
    (sum, section) => sum + subjects.value.filter((subject) => section.offers(subject.id) && !section.assignmentFor(subject.id)).length,
    0,
));

/** "School · Grade 1": the platform owner looks at grade levels of many schools, so the school leads. */
const heading = computed(() => {
    const school = auth.isPlatform ? level.value?.organizationName : '';

    return school ? `${school} · ${level.value.name}` : (level.value?.name ?? '');
});

const subtitle = computed(() => {
    if (!level.value) {
        return '';
    }

    const parts = [];

    if (level.value.branchName) {
        parts.push(level.value.branchName);
    }

    if (level.value.schoolYearName) {
        parts.push(`SY ${level.value.schoolYearName}`);
    }

    parts.push(pluralize(subjects.value.length, 'subject'));
    parts.push(pluralize(sections.value.length, props.admin ? 'section' : 'section you teach', props.admin ? 'sections' : 'sections you teach'));
    parts.push(pluralize(level.value.studentsCount ?? 0, 'student'));

    return parts.join(' · ');
});

function cellKey(section, subject) {
    return `${section.id}-${subject.id}`;
}

function teacherIdFor(section, subject) {
    return section.assignmentFor(subject.id)?.teacherId ?? '';
}

/** What a grid cell shows: the teacher's id, '' for nobody yet, or 'off' when the section does not offer the subject. */
function cellValue(section, subject) {
    return section.offers(subject.id) ? teacherIdFor(section, subject) : 'off';
}

async function load() {
    loading.value = true;
    loadError.value = '';

    try {
        level.value = props.admin ? await adminService.getGradeLevel(props.id) : await referenceService.gradeLevel(props.id);

        if (props.admin && teachers.value.length === 0) {
            loadTeachers();
        }
    } catch (err) {
        level.value = null;
        loadError.value = err?.isNotFound
            ? 'This grade level could not be found.'
            : (err?.message ?? 'Failed to load the grade level.');
    } finally {
        loading.value = false;
    }
}

async function loadTeachers() {
    try {
        teachers.value = await adminService.listTeachers({ branch_id: level.value?.branchId ?? '' });
    } catch (err) {
        notify.error(err?.message ?? 'Failed to load the teachers.');
    }
}

/**
 * Admin: set one grid cell: a teacher for the subject in that section, nobody yet
 * (offered, open), or "Not offered" (the section does not have the subject).
 */
async function assign(section, subject, value) {
    if (savingCell.value !== '') {
        return;
    }

    if (String(value) === String(cellValue(section, subject))) {
        return;
    }

    const offered = section.offers(subject.id);

    savingCell.value = cellKey(section, subject);

    try {
        if (value === 'off') {
            await adminService.withdrawSubject(section.id, subject.id);
            notify.info(`${subject.name} is no longer offered in ${section.name}.`);
        } else {
            if (!offered) {
                await adminService.offerSubject(section.id, subject.id);
            }

            if (value === '' || value === null) {
                if (offered) {
                    await adminService.unassignTeacher(section.id, subject.id);
                    notify.info(`Nobody teaches ${subject.name} in ${section.name} now.`);
                } else {
                    notify.info(`${section.name} offers ${subject.name} now.`);
                }
            } else {
                const assignment = await adminService.assignTeacher(section.id, subject.id, value);
                notify.success(`${assignment.teacherName || 'The teacher'} now teaches ${subject.name} in ${section.name}.`);
            }
        }
    } catch (err) {
        notify.error(err?.isValidation ? (err.first('subject_id') ?? err.first('teacher_id') ?? err.message) : (err?.message ?? 'Unable to change the teacher.'));
    } finally {
        savingCell.value = '';
        await load();
    }
}

/** A teacher who left the branch keeps their slots until reassigned: still offer them in that cell's select. */
function strayTeacher(section, subject) {
    const teacher = section.teacherFor(subject.id);

    return teacher && !teachers.value.some((item) => Number(item.id) === Number(teacher.id)) ? teacher : null;
}

function openRecord(kind, record = null) {
    recordKind.value = kind;
    editingRecord.value = record;
    recordModal.value = true;
}

const deleteTitle = computed(() => {
    switch (deleting.value?.kind) {
        case 'level':
            return 'Delete grade level';
        case 'section':
            return 'Delete section';
        default:
            return 'Delete subject';
    }
});

async function confirmRemove() {
    const target = deleting.value;

    if (removing.value || !target) {
        return;
    }

    removing.value = true;

    try {
        if (target.kind === 'level') {
            await adminService.removeGradeLevel(target.record.id);
            notify.success(`${target.record.name} deleted.`);
            deleting.value = null;
            router.push({ name: 'admin-grade-levels' });
            return;
        }

        if (target.kind === 'section') {
            await adminService.removeSection(target.record.id);
        } else {
            await adminService.removeSubject(target.record.id);
        }

        notify.success(`${target.record.name} deleted.`);
        deleting.value = null;
        await load();
    } catch (err) {
        notify.error(err?.isValidation ? (Object.values(err.errors ?? {})[0]?.[0] ?? err.message) : (err?.message ?? 'Unable to delete it.'));
    } finally {
        removing.value = false;
    }
}

watch(() => props.id, load);

onMounted(load);
</script>

<template>
    <div class="grade-level-detail">
        <p class="back">
            <RouterLink v-if="admin" :to="{ name: 'admin-grade-levels' }">&larr; Grade Levels</RouterLink>
            <RouterLink v-else :to="{ name: 'classes' }">&larr; My classes</RouterLink>
        </p>

        <p v-if="loading && !level" class="muted">Loading…</p>

        <EmptyState v-else-if="!level" :message="loadError">
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
                <div v-if="admin" class="page-actions">
                    <button type="button" class="btn btn-secondary" :disabled="!auth.canWrite" @click="openRecord('subject')">Add subject</button>
                    <button type="button" class="btn btn-secondary" :disabled="!auth.canWrite" @click="openRecord('section')">Add section</button>
                    <button type="button" class="btn btn-secondary" :disabled="!auth.canWrite" @click="editOpen = true">Edit</button>
                    <button
                        type="button"
                        class="btn btn-secondary text-danger"
                        :disabled="!auth.canWrite"
                        @click="deleting = { kind: 'level', record: level }"
                    >
                        Delete
                    </button>
                </div>
            </div>

            <!-- Grade level information on the left; the records chart and average on the right, stretched to the same height. -->
            <div class="info-row">
                <section class="card info-card">
                    <h2 class="card-title">Grade level information</h2>
                    <dl class="info">
                        <div class="info-item">
                            <dt>Branch</dt>
                            <dd>{{ level.branchName || '—' }}</dd>
                        </div>
                        <div class="info-item">
                            <dt>School year</dt>
                            <dd class="mono">{{ level.schoolYearName || '—' }}</dd>
                        </div>
                        <div class="info-item">
                            <dt>Students</dt>
                            <dd class="mono">{{ level.studentsCount ?? 0 }}</dd>
                        </div>
                        <div class="info-item">
                            <dt>Subjects</dt>
                            <dd class="mono">{{ subjects.length }}</dd>
                        </div>
                        <div class="info-item">
                            <dt>{{ admin ? 'Sections' : 'Your sections' }}</dt>
                            <dd class="mono">{{ sections.length }}</dd>
                        </div>
                        <div class="info-item">
                            <dt>PACE records</dt>
                            <dd class="mono">{{ records.length }}</dd>
                        </div>
                    </dl>
                </section>

                <section class="card stats-card">
                    <h2 class="card-title">PACE records</h2>
                    <div class="stats-body">
                        <EmptyState v-if="records.length === 0" compact message="No PACE records under this grade level yet." />
                        <LayeredRings v-else :rings="recordRings" :center="records.length" :size="176" :stroke-width="8" />
                        <div class="average">
                            <p class="muted average-label">Average score</p>
                            <p class="mono average-value">{{ averageAll === null ? '—' : formatScore(averageAll) }}</p>
                            <p class="muted average-hint">
                                Across all {{ pluralize(records.length, 'record') }}; untested PACEs count as 0.
                            </p>
                        </div>
                    </div>
                </section>
            </div>

            <section class="card block">
                <div class="block-header">
                    <h2 class="card-title">Sections and teachers</h2>
                    <span v-if="admin && sections.length && subjects.length" class="muted small-note">
                        {{ openSlots === 0 ? 'Every subject has a teacher in every section.' : `${pluralize(openSlots, 'class', 'classes')} without a teacher` }}
                    </span>
                </div>

                <EmptyState v-if="sections.length === 0" compact :message="admin ? 'No sections yet. Add a section to enroll students in.' : 'You teach no section of this grade level.'">
                    <template v-if="admin" #action>
                        <button type="button" class="btn btn-link btn-sm" :disabled="!auth.canWrite" @click="openRecord('section')">Add the first section</button>
                    </template>
                </EmptyState>

                <EmptyState v-else-if="subjects.length === 0" compact message="No subjects yet, so there is nobody to assign.">
                    <template v-if="admin" #action>
                        <button type="button" class="btn btn-link btn-sm" :disabled="!auth.canWrite" @click="openRecord('subject')">Add the first subject</button>
                    </template>
                </EmptyState>

                <div v-else class="table-wrap">
                    <table class="table matrix">
                        <thead>
                            <tr>
                                <th class="col-section-head">Section</th>
                                <th v-for="subject in subjects" :key="subject.id" class="subject-head">{{ subject.name }}</th>
                                <th class="table-actions"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="section in sections" :key="section.id">
                                <td class="section-cell">
                                    <RouterLink :to="{ name: sectionRoute, params: { id: section.id } }" class="section-name">{{ section.name }}</RouterLink>
                                    <span class="muted small">{{ pluralize(section.studentsCount ?? 0, 'student') }}</span>
                                </td>
                                <td v-for="subject in subjects" :key="subject.id" class="teacher-cell">
                                    <template v-if="admin">
                                        <select
                                            class="input teacher-select"
                                            :class="{ open: !teacherIdFor(section, subject) || !section.offers(subject.id) }"
                                            :value="cellValue(section, subject)"
                                            :aria-label="`Teacher of ${subject.name} in ${section.name}`"
                                            :disabled="!auth.canWrite || savingCell !== ''"
                                            @change="assign(section, subject, $event.target.value)"
                                        >
                                            <option value="off">Not offered</option>
                                            <option value="">Nobody yet</option>
                                            <option v-for="teacher in teachers" :key="teacher.id" :value="teacher.id">{{ teacher.name }}</option>
                                            <option v-if="strayTeacher(section, subject)" :value="strayTeacher(section, subject).id">
                                                {{ strayTeacher(section, subject).name }}
                                            </option>
                                        </select>
                                    </template>
                                    <template v-else>
                                        <span v-if="!section.offers(subject.id)" class="muted">Not offered</span>
                                        <span v-else-if="section.teacherFor(subject.id)?.id === myId" class="mine">You</span>
                                        <span v-else-if="section.teacherFor(subject.id)">{{ section.teacherFor(subject.id).name }}</span>
                                        <span v-else class="muted">Nobody yet</span>
                                    </template>
                                </td>
                                <td class="table-actions">
                                    <RouterLink
                                        :to="{ name: sectionRoute, params: { id: section.id } }"
                                        class="btn btn-link btn-sm btn-icon"
                                        :title="`View ${section.name}`"
                                        :aria-label="`View ${section.name}`"
                                    >
                                        <AppIcon name="eye" />
                                    </RouterLink>
                                    <template v-if="admin">
                                        <button
                                            type="button"
                                            class="btn btn-link btn-sm btn-icon"
                                            :title="`Rename ${section.name}`"
                                            :aria-label="`Rename ${section.name}`"
                                            :disabled="!auth.canWrite"
                                            @click="openRecord('section', section)"
                                        >
                                            <AppIcon name="pencil" />
                                        </button>
                                        <button
                                            type="button"
                                            class="btn btn-link btn-sm btn-icon text-danger"
                                            :title="section.hasStudents ? 'Move or remove its students first' : `Delete ${section.name}`"
                                            :aria-label="`Delete ${section.name}`"
                                            :disabled="!auth.canWrite"
                                            @click="deleting = { kind: 'section', record: section }"
                                        >
                                            <AppIcon name="trash" />
                                        </button>
                                    </template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="card block">
                <div class="block-header">
                    <h2 class="card-title">Subjects</h2>
                    <PaceRecordsLegend v-if="subjects.length" :order="RECORD_ORDER" />
                </div>

                <EmptyState v-if="subjects.length === 0" compact message="No subjects yet.">
                    <template v-if="admin" #action>
                        <button type="button" class="btn btn-link btn-sm" :disabled="!auth.canWrite" @click="openRecord('subject')">Add the first subject</button>
                    </template>
                </EmptyState>

                <table v-else class="table subjects-table">
                    <colgroup>
                        <col class="col-subject" />
                        <col class="col-records" />
                        <col :class="admin ? 'col-actions-wide' : 'col-actions'" />
                    </colgroup>
                    <thead>
                        <tr>
                            <th>Subject</th>
                            <th>PACE records</th>
                            <th class="table-actions"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="subject in subjects" :key="subject.id">
                            <td class="subject-name">
                                <RouterLink :to="{ name: subjectRoute, params: { id: subject.id } }">{{ subject.name }}</RouterLink>
                            </td>
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
                                    :to="{ name: subjectRoute, params: { id: subject.id } }"
                                    class="btn btn-link btn-sm btn-icon"
                                    :title="`View ${subject.name} records`"
                                    :aria-label="`View ${subject.name} records`"
                                >
                                    <AppIcon name="eye" />
                                </RouterLink>
                                <template v-if="admin">
                                    <button
                                        type="button"
                                        class="btn btn-link btn-sm btn-icon"
                                        :title="`Edit ${subject.name}`"
                                        :aria-label="`Edit ${subject.name}`"
                                        :disabled="!auth.canWrite"
                                        @click="openRecord('subject', subject)"
                                    >
                                        <AppIcon name="pencil" />
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-link btn-sm btn-icon text-danger"
                                        :title="`Delete ${subject.name}`"
                                        :aria-label="`Delete ${subject.name}`"
                                        :disabled="!auth.canWrite"
                                        @click="deleting = { kind: 'subject', record: subject }"
                                    >
                                        <AppIcon name="trash" />
                                    </button>
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <PaceRecordsExplorer
                :records="records"
                :subjects="subjects"
                :order="RECORD_ORDER"
                :admin="admin"
                year-filter
                quarter-filter
                score-filter
                empty-message="No PACE records have been filed under this grade level yet."
            />

            <template v-if="admin">
                <GradeLevelFormModal v-model="editOpen" :grade-level="level" @saved="load" />
                <SubjectFormModal v-model="recordModal" :kind="recordKind" :grade-level="level" :record="editingRecord" @saved="load" />

                <ConfirmDialog
                    :model-value="deleting !== null"
                    :title="deleteTitle"
                    :busy="removing"
                    @update:model-value="deleting = null"
                    @confirm="confirmRemove"
                >
                    <template v-if="deleting?.kind === 'level'">
                        Delete <strong>{{ level.labelWithYear }}</strong>?
                        <template v-if="level.hasStudents">
                            It still has {{ pluralize(level.studentsCount, 'student') }} enrolled, so it cannot be deleted
                            until they are moved to another section or removed.
                        </template>
                        <template v-else>
                            Its subjects, sections and teaching assignments go with it. This cannot be undone.
                        </template>
                    </template>
                    <template v-else-if="deleting?.kind === 'section'">
                        Delete <strong>{{ deleting.record.name }}</strong>?
                        <template v-if="deleting.record.hasStudents">
                            It still has {{ pluralize(deleting.record.studentsCount ?? 0, 'student') }} enrolled, so it cannot be
                            deleted until they are moved to another section or removed.
                        </template>
                        <template v-else>Its teaching assignments go with it. This cannot be undone.</template>
                    </template>
                    <template v-else-if="deleting">
                        Delete <strong>{{ deleting.record.name }}</strong>?
                        <template v-if="deleting.record.assignmentsCount">
                            {{ pluralize(deleting.record.assignmentsCount, 'PACE record') }} filed under it will be deleted as well.
                        </template>
                        Its teaching assignments go with it. This cannot be undone.
                    </template>
                </ConfirmDialog>
            </template>
        </template>
    </div>
</template>

<style scoped>
.grade-level-detail {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.back {
    margin: 0;
    font-size: 0.92rem;
}

/* The name stays pinned under the top bar while the page scrolls (like the student page). */
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

/* Information card and records card side by side, stretched to the same height. */
.info-row {
    display: flex;
    align-items: stretch;
    gap: 16px;
    flex-wrap: wrap;
}

.info-card {
    flex: 1 1 360px;
    min-width: 0;
}

.stats-card {
    flex: 1 1 520px;
    display: flex;
    flex-direction: column;
}

.stats-body {
    flex: 1 1 auto;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px 32px;
    flex-wrap: wrap;
}

dl.info {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
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

.average {
    flex: 0 1 200px;
    padding-left: 24px;
    border-left: 1px solid var(--border);
}

.average p {
    margin: 0;
}

.average-label {
    font-size: 0.85rem;
}

.average-value {
    font-size: 1.6rem;
    font-weight: 600;
    line-height: 1.2;
}

.average-hint {
    margin-top: 4px;
    font-size: 0.8rem;
}

@media (max-width: 560px) {
    .average {
        padding-left: 0;
        border-left: none;
    }
}

.block {
    padding: 0;
    overflow: hidden;
}

.block-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    padding: 12px 16px;
    border-bottom: 1px solid var(--border);
}

.block .card-title {
    margin: 0;
}

.small-note {
    font-size: 0.85rem;
}

.table-wrap {
    overflow-x: auto;
}

.matrix th,
.matrix td {
    white-space: nowrap;
}

.col-section-head {
    min-width: 160px;
}

.subject-head {
    min-width: 170px;
}

.section-name {
    font-weight: 500;
}

.small {
    display: block;
    font-size: 0.8rem;
}

.teacher-select {
    width: 100%;
    min-width: 160px;
    padding-top: 4px;
    padding-bottom: 4px;
}

.teacher-select.open {
    color: var(--muted);
}

.mine {
    font-weight: 500;
    color: var(--primary);
}

.subjects-table {
    table-layout: fixed;
}

.col-records {
    width: 160px;
}

.col-actions {
    width: 60px;
}

.col-actions-wide {
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
