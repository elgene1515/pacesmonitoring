<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { adminService, referenceService } from '../../services';
import { formatScore, pluralize } from '../../core/format';
import { buildRecordRings } from '../../core/recordRings';
import GradeLevelFormModal from '../gradeLevels/GradeLevelFormModal.vue';
import SubjectFormModal from '../gradeLevels/SubjectFormModal.vue';
import EmptyState from '../ui/EmptyState.vue';
import AppIcon from '../ui/AppIcon.vue';
import LayeredRings from '../charts/LayeredRings.vue';
import PaceRecordsBar from '../charts/PaceRecordsBar.vue';
import PaceRecordsLegend from '../charts/PaceRecordsLegend.vue';
import PaceRecordsExplorer from '../assignments/PaceRecordsExplorer.vue';

const props = defineProps({
    id: { type: Number, required: true },
    /** Read-only admin view: loads through the admin API, no editing, admin page links. */
    admin: { type: Boolean, default: false },
});

const subjectRoute = computed(() => (props.admin ? 'admin-subject-detail' : 'subject-detail'));

/** Legend / bar order shared with the Grade levels page: tested / issued / assigned. */
const RECORD_ORDER = ['tested', 'issued', 'assigned'];

const level = ref(null);
const loading = ref(true);
const loadError = ref('');
const editOpen = ref(false);
const subjectModal = ref(false);

const records = computed(() => level.value?.assignments ?? []);

const subjects = computed(() => level.value?.subjects ?? []);

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

const subtitle = computed(() => {
    if (!level.value) {
        return '';
    }

    const parts = [];

    if (level.value.schoolYearName) {
        parts.push(`SY ${level.value.schoolYearName}`);
    }

    parts.push(pluralize(subjects.value.length, 'subject'));
    parts.push(pluralize(level.value.studentsCount ?? 0, 'student'));
    parts.push(pluralize(records.value.length, 'PACE record'));

    return parts.join(' · ');
});

async function load() {
    loading.value = true;
    loadError.value = '';

    try {
        level.value = props.admin ? await adminService.getGradeLevel(props.id) : await referenceService.gradeLevel(props.id);
    } catch (err) {
        level.value = null;
        loadError.value = err?.isNotFound
            ? 'This grade level could not be found.'
            : (err?.message ?? 'Failed to load the grade level.');
    } finally {
        loading.value = false;
    }
}

watch(() => props.id, load);

onMounted(load);
</script>

<template>
    <div class="grade-level-detail">
        <p class="back">
            <RouterLink v-if="admin && level?.userId" :to="{ name: 'admin-user-detail', params: { id: level.userId } }">&larr; Teacher</RouterLink>
            <RouterLink v-else-if="admin" :to="{ name: 'admin-users' }">&larr; Users</RouterLink>
            <RouterLink v-else :to="{ name: 'grade-levels' }">&larr; Grade levels</RouterLink>
        </p>

        <p v-if="loading" class="muted">Loading…</p>

        <EmptyState v-else-if="!level" :message="loadError">
            <template #action>
                <RouterLink :to="{ name: admin ? 'admin-users' : 'grade-levels' }" class="btn btn-secondary btn-sm">{{ admin ? 'Back to users' : 'Back to grade levels' }}</RouterLink>
            </template>
        </EmptyState>

        <template v-else>
            <div class="page-header">
                <div>
                    <h1>{{ level.name }}</h1>
                    <p class="muted subtitle">{{ subtitle }}</p>
                </div>
                <div v-if="!admin" class="page-actions">
                    <button type="button" class="btn btn-secondary" @click="subjectModal = true">Add subject</button>
                    <button type="button" class="btn btn-secondary" @click="editOpen = true">Edit</button>
                </div>
            </div>

            <!-- Grade level information on the left; the records chart and average on the right, stretched to the same height. -->
            <div class="info-row">
                <section class="card info-card">
                    <h2 class="card-title">Grade level information</h2>
                    <dl class="info">
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

            <section class="card subjects">
                <div class="subjects-header">
                    <h2 class="card-title">Subjects</h2>
                    <PaceRecordsLegend v-if="subjects.length" :order="RECORD_ORDER" />
                </div>

                <EmptyState v-if="subjects.length === 0" compact message="No subjects yet.">
                    <template v-if="!admin" #action>
                        <button type="button" class="btn btn-link btn-sm" @click="subjectModal = true">Add the first subject</button>
                    </template>
                </EmptyState>

                <table v-else class="table">
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

            <template v-if="!admin">
                <GradeLevelFormModal v-model="editOpen" :grade-level="level" @saved="load" />
                <SubjectFormModal v-model="subjectModal" :grade-level="level" :subject="null" @saved="load" />
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

.info {
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

.subjects {
    padding: 0;
    overflow: hidden;
}

.subjects-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    padding: 12px 16px;
    border-bottom: 1px solid var(--border);
}

.subjects .card-title {
    margin: 0;
}

.subjects .table {
    table-layout: fixed;
}

.col-records {
    width: 160px;
}

.col-actions {
    width: 60px;
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
