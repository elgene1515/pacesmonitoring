<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { adminService, referenceService } from '../../services';
import { useAuthStore } from '../../stores/auth';
import { formatScore, pluralize } from '../../core/format';
import { buildRecordRings } from '../../core/recordRings';
import SubjectFormModal from '../gradeLevels/SubjectFormModal.vue';
import EmptyState from '../ui/EmptyState.vue';
import LayeredRings from '../charts/LayeredRings.vue';
import PaceRecordsExplorer from '../assignments/PaceRecordsExplorer.vue';

const props = defineProps({
    id: { type: Number, required: true },
    /** Admin view: loads through the admin API, can rename the subject, admin page links. */
    admin: { type: Boolean, default: false },
});

const auth = useAuthStore();

const gradeLevelRoute = computed(() => (props.admin ? 'admin-grade-level-detail' : 'grade-level-detail'));

/** Legend / table order shared with the Grade levels page: tested / issued / assigned. */
const RECORD_ORDER = ['tested', 'issued', 'assigned'];

const subject = ref(null);
const loading = ref(true);
const loadError = ref('');
const editOpen = ref(false);

const records = computed(() => subject.value?.assignments ?? []);

/** Who teaches this subject in each section, by section name. */
const taughtIn = computed(() => (subject.value?.teachingAssignments ?? [])
    .map((assignment) => ({ id: assignment.id, sectionId: assignment.sectionId, section: assignment.sectionName, teacher: assignment.teacher }))
    .sort((a, b) => String(a.section).localeCompare(String(b.section))));

/** Layered rings (assigned / issued / tested / pass / retakes / fail) for every record under the subject. */
const recordRings = computed(() => buildRecordRings(records.value));

/**
 * Average score across every record (assigned, issued and tested alike):
 * records without a score count as 0, so the figure reflects overall progress
 * rather than only the PACEs already tested.
 */
const averageAll = computed(() => {
    if (records.value.length === 0) {
        return null;
    }

    const sum = records.value.reduce((carry, record) => carry + (Number(record.score) || 0), 0);

    return sum / records.value.length;
});

/** "School · Grade 1 · Math": the platform owner looks at subjects of many schools, so the school and grade level lead. */
const heading = computed(() => {
    if (!subject.value) {
        return '';
    }

    const school = auth.isPlatform ? subject.value.organizationName : '';

    return [school, subject.value.gradeLevelName, subject.value.name].filter(Boolean).join(' · ');
});

const subtitle = computed(() => {
    if (!subject.value) {
        return '';
    }

    const parts = [];

    if (subject.value.gradeLevelName) {
        parts.push(subject.value.gradeLevelName);
    }

    if (subject.value.schoolYearName) {
        parts.push(`SY ${subject.value.schoolYearName}`);
    }

    parts.push(pluralize(records.value.length, 'PACE record'));

    return parts.join(' · ');
});

async function load() {
    loading.value = true;
    loadError.value = '';

    try {
        subject.value = props.admin ? await adminService.getSubject(props.id) : await referenceService.subject(props.id);
    } catch (err) {
        subject.value = null;
        loadError.value = err?.isNotFound
            ? 'This subject could not be found.'
            : (err?.message ?? 'Failed to load the subject.');
    } finally {
        loading.value = false;
    }
}

watch(() => props.id, load);

onMounted(load);
</script>

<template>
    <div class="subject-detail">
        <p class="back">
            <RouterLink v-if="subject?.gradeLevelId" :to="{ name: gradeLevelRoute, params: { id: subject.gradeLevelId } }">
                &larr; {{ subject.gradeLevelName || 'Grade level' }}
            </RouterLink>
            <RouterLink v-else :to="{ name: admin ? 'admin-grade-levels' : 'classes' }">&larr; {{ admin ? 'Grade Levels' : 'My classes' }}</RouterLink>
        </p>

        <p v-if="loading" class="muted">Loading…</p>

        <EmptyState v-else-if="!subject" :message="loadError">
            <template #action>
                <RouterLink :to="{ name: admin ? 'admin-grade-levels' : 'classes' }" class="btn btn-secondary btn-sm">{{ admin ? 'Back to grade levels' : 'Back to my classes' }}</RouterLink>
            </template>
        </EmptyState>

        <template v-else>
            <!-- The subject's name stays pinned under the top bar while the page scrolls. -->
            <div class="page-header">
                <div>
                    <h1>{{ heading }}</h1>
                    <p class="muted subtitle">{{ subtitle }}</p>
                </div>
                <div v-if="admin" class="page-actions">
                    <button type="button" class="btn btn-secondary" @click="editOpen = true">Edit</button>
                </div>
            </div>

            <!-- Subject information on the left; the records chart and average on the right, stretched to the same height. -->
            <div class="info-row">
                <section class="card info-card">
                    <h2 class="card-title">Subject information</h2>
                    <dl class="info">
                        <div class="info-item">
                            <dt>Grade level</dt>
                            <dd>
                                <RouterLink v-if="subject.gradeLevelId" :to="{ name: gradeLevelRoute, params: { id: subject.gradeLevelId } }">
                                    {{ subject.gradeLevelName || 'Grade level' }}
                                </RouterLink>
                                <span v-else>—</span>
                            </dd>
                        </div>
                        <div class="info-item">
                            <dt>School year</dt>
                            <dd class="mono">{{ subject.schoolYearName || '—' }}</dd>
                        </div>
                        <div class="info-item">
                            <dt>Branch</dt>
                            <dd>{{ subject.branchName || '—' }}</dd>
                        </div>
                        <div class="info-item info-wide">
                            <dt>Taught in</dt>
                            <dd>
                                <span v-if="taughtIn.length === 0" class="muted">No section has a teacher for it yet.</span>
                                <ul v-else class="taught-in">
                                    <li v-for="item in taughtIn" :key="item.id">
                                        <RouterLink v-if="admin && item.sectionId" :to="{ name: 'admin-section-detail', params: { id: item.sectionId } }">{{ item.section }}</RouterLink>
                                        <span v-else>{{ item.section }}</span>
                                        <span class="muted"> · </span>
                                        <RouterLink v-if="admin && item.teacher" :to="{ name: 'admin-user-detail', params: { id: item.teacher.id } }">{{ item.teacher.name }}</RouterLink>
                                        <span v-else>{{ item.teacher?.name || '—' }}</span>
                                    </li>
                                </ul>
                            </dd>
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
                        <EmptyState v-if="records.length === 0" compact message="No PACE records under this subject yet." />
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

            <PaceRecordsExplorer
                :records="records"
                :order="RECORD_ORDER"
                :admin="admin"
                year-filter
                quarter-filter
                score-filter
                empty-message="No PACE records have been filed under this subject yet."
            />

            <SubjectFormModal v-if="admin" v-model="editOpen" kind="subject" :grade-level="subject.gradeLevel" :record="subject" @saved="load" />
        </template>
    </div>
</template>

<style scoped>
.subject-detail {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.back {
    margin: 0;
    font-size: 0.92rem;
}

/* The subject's name stays pinned under the top bar while the page scrolls (like the student page). */
.page-header {
    margin-bottom: 0;
    position: sticky;
    top: var(--nav-height, 0px);
    z-index: 20;
    padding: 12px 0;
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

.info-wide {
    grid-column: 1 / -1;
}

.taught-in {
    margin: 0;
    padding: 0;
    list-style: none;
}

.taught-in li + li {
    margin-top: 2px;
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
</style>
