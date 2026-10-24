<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { referenceService } from '../../services';
import { useAuthStore } from '../../stores/auth';
import { useSchoolYearsStore } from '../../stores/schoolYears';
import { useTeachingStore } from '../../stores/teaching';
import { useReferenceStore } from '../../stores/reference';
import { notify } from '../../stores/notifications';
import { formatDate, pluralize } from '../../core/format';
import TeachingRequestModal from '../teaching/TeachingRequestModal.vue';
import ConfirmDialog from '../ui/ConfirmDialog.vue';
import EmptyState from '../ui/EmptyState.vue';
import StatusBadge from '../ui/StatusBadge.vue';
import AppIcon from '../ui/AppIcon.vue';

/**
 * Class requests: the teacher's requests for open slots (a subject of a
 * section nobody teaches yet), and the branch's curriculum to find them in.
 */
const auth = useAuthStore();
const schoolYears = useSchoolYearsStore();
const teaching = useTeachingStore();
const reference = useReferenceStore();

const curriculum = ref([]);
const loadingCurriculum = ref(true);
const yearFilter = ref('');
let yearInitialised = false;

/** Show only the slots nobody teaches yet (default) or every slot. */
const openOnly = ref(true);
const search = ref('');

const requestOpen = ref(false);
const requestSection = ref(null);
const requestSubject = ref(null);

const withdrawing = ref(null);
const busy = ref(false);

const myId = computed(() => Number(auth.user?.id ?? 0));

const searchTerm = computed(() => search.value.trim().toLowerCase());

function matches(...texts) {
    return searchTerm.value === '' || texts.some((text) => String(text ?? '').toLowerCase().includes(searchTerm.value));
}

/** Every slot (section × subject) of a grade level, with who holds it and whether the teacher asked for it. */
function slotsFor(level) {
    const slots = [];

    for (const section of level.sections) {
        if (!section.gradeLevel) {
            section.gradeLevel = level;
        }

        for (const subject of level.subjects) {
            if (!section.offers(subject.id)) {
                continue;
            }

            const assignment = section.assignmentFor(subject.id);
            const pending = teaching.pendingFor(section.id, subject.id) ?? null;
            const mine = assignment?.teacherId === myId.value;

            if (openOnly.value && assignment) {
                continue;
            }

            if (!matches(level.name, section.name, subject.name, assignment?.teacherName)) {
                continue;
            }

            slots.push({
                key: `${section.id}-${subject.id}`,
                section,
                subject,
                teacher: assignment?.teacher ?? null,
                mine,
                pending,
            });
        }
    }

    return slots;
}

const levels = computed(() => curriculum.value
    .map((level) => ({ level, slots: slotsFor(level) }))
    .filter((entry) => entry.slots.length > 0));

const openCount = computed(() => curriculum.value.reduce((sum, level) => sum + level.sections.reduce(
    (inner, section) => inner + level.subjects.filter((subject) => section.offers(subject.id) && !section.assignmentFor(subject.id)).length,
    0,
), 0));

const curriculumEmptyMessage = computed(() => {
    if (curriculum.value.length === 0) {
        return 'Your branch has no grade levels in this school year yet.';
    }

    if (searchTerm.value !== '') {
        return `No classes match “${search.value.trim()}”.`;
    }

    return openOnly.value ? 'Every class in this school year already has a teacher.' : 'No sections yet.';
});

async function loadCurriculum() {
    loadingCurriculum.value = true;

    try {
        curriculum.value = await referenceService.curriculum(yearFilter.value || null);
    } catch (err) {
        notify.error(err?.message ?? 'Failed to load the grade levels.');
    } finally {
        loadingCurriculum.value = false;
    }
}

watch(
    () => [schoolYears.loaded, schoolYears.current],
    ([loaded, current]) => {
        if (yearInitialised || !loaded) {
            return;
        }

        yearInitialised = true;
        yearFilter.value = current?.id ?? '';
        loadCurriculum();
    },
    { immediate: true },
);

function openRequest(slot) {
    requestSection.value = slot.section;
    requestSubject.value = slot.subject;
    requestOpen.value = true;
}

function onRequested() {
    // Nothing to reload: the store already holds the new request, so the slot shows "Requested".
}

async function confirmWithdraw() {
    const target = withdrawing.value;

    if (!target || busy.value) {
        return;
    }

    busy.value = true;

    try {
        await teaching.cancel(target.id);
        notify.info(`Your request for ${target.label} was withdrawn.`);
        withdrawing.value = null;
    } catch (err) {
        notify.error(err?.isValidation ? (err.first('request') ?? err.message) : (err?.message ?? 'Unable to withdraw the request.'));
    } finally {
        busy.value = false;
    }
}

/** "Accepted by Ana Cruz · Oct 19, 2026", "Declined by …", or "Withdrawn · …". */
function decisionLine(request) {
    if (request.isPending) {
        return '';
    }

    const by = request.deciderName ? ` by ${request.deciderName}` : '';
    const when = formatDate(request.decidedAt);

    return `${request.statusLabel}${by}${when ? ` · ${when}` : ''}`;
}

/** An accepted request changes the teacher's classes, so the cached curriculum is refreshed. */
watch(() => teaching.items.filter((item) => item.isAccepted).length, (now, before) => {
    if (before !== undefined && now !== before) {
        reference.load(true);
    }
});

onMounted(() => {
    schoolYears.load();
    teaching.fetch();
});
</script>

<template>
    <div class="teaching-requests">
        <p class="back">
            <RouterLink :to="{ name: 'classes' }">&larr; My classes</RouterLink>
        </p>

        <div class="page-header">
            <div>
                <h1>Class requests</h1>
                <p class="muted subtitle">
                    Ask your school admin to put you in charge of a subject in a section nobody teaches yet. Once
                    accepted, the section's students show up in your lists.
                </p>
            </div>
        </div>

        <section class="card">
            <div class="card-header">
                <h2 class="card-title">Your requests</h2>
                <span class="muted count nowrap">
                    {{ teaching.loading && !teaching.loaded ? 'Loading…' : pluralize(teaching.items.length, 'request') }}
                    <template v-if="teaching.pendingCount > 0"> · {{ teaching.pendingCount }} pending</template>
                </span>
            </div>

            <p v-if="teaching.loading && !teaching.loaded" class="muted state">Loading…</p>

            <EmptyState v-else-if="teaching.items.length === 0" compact message="You have not asked for a class yet. Pick an open one below." />

            <div v-else class="table-wrap">
                <table class="table requests">
                    <colgroup>
                        <col />
                        <col class="col-year" />
                        <col class="col-date" />
                        <col class="col-status" />
                        <col class="col-actions" />
                    </colgroup>
                    <thead>
                        <tr>
                            <th>Class</th>
                            <th>School year</th>
                            <th>Requested</th>
                            <th>Status</th>
                            <th class="table-actions"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="request in teaching.items" :key="request.id">
                            <td class="ellipsis">
                                <span class="name">{{ request.label }}</span>
                                <span v-if="request.message" class="muted small" :title="request.message">“{{ request.message }}”</span>
                            </td>
                            <td class="mono">{{ request.schoolYearName || '—' }}</td>
                            <td class="nowrap">{{ formatDate(request.createdAt) || '—' }}</td>
                            <td>
                                <StatusBadge :tone="request.tone">{{ request.statusLabel }}</StatusBadge>
                                <span v-if="decisionLine(request)" class="muted small">{{ decisionLine(request) }}</span>
                                <span v-if="request.declineReason" class="muted small" :title="request.declineReason">“{{ request.declineReason }}”</span>
                            </td>
                            <td class="table-actions">
                                <RouterLink
                                    v-if="request.isAccepted && request.sectionId"
                                    :to="{ name: 'section-detail', params: { id: request.sectionId } }"
                                    class="btn btn-link btn-sm btn-icon"
                                    :title="`View ${request.label}`"
                                    :aria-label="`View ${request.label}`"
                                >
                                    <AppIcon name="eye" />
                                </RouterLink>
                                <button
                                    v-if="request.isPending"
                                    type="button"
                                    class="btn btn-link btn-sm btn-icon text-danger"
                                    :title="`Withdraw the request for ${request.label}`"
                                    :aria-label="`Withdraw the request for ${request.label}`"
                                    @click="withdrawing = request"
                                >
                                    <AppIcon name="trash" />
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card">
            <div class="card-header">
                <h2 class="card-title">Classes in your branch</h2>
                <span v-if="!loadingCurriculum" class="muted count nowrap">{{ pluralize(openCount, 'open class', 'open classes') }}</span>
            </div>

            <div class="toolbar">
                <input v-model="search" class="input search" type="search" placeholder="Search grade level, section, subject or teacher" aria-label="Search classes" />
                <label class="year">
                    <span class="muted">School year</span>
                    <select v-model="yearFilter" class="input" aria-label="School year" @change="loadCurriculum">
                        <option value="">All school years</option>
                        <option v-for="year in schoolYears.items" :key="year.id" :value="year.id">
                            {{ year.name }}{{ year.isCurrent ? ' (current)' : '' }}
                        </option>
                    </select>
                </label>
                <label class="checkbox">
                    <input v-model="openOnly" type="checkbox" />
                    Open classes only
                </label>
            </div>

            <p v-if="loadingCurriculum" class="muted state">Loading…</p>

            <EmptyState v-else-if="levels.length === 0" compact :message="curriculumEmptyMessage" />

            <div v-else class="levels">
                <div v-for="entry in levels" :key="entry.level.id" class="level">
                    <h3 class="level-name">
                        {{ entry.level.name }}
                        <span class="muted level-year mono">SY {{ entry.level.schoolYearName || '—' }}</span>
                    </h3>
                    <div class="table-wrap">
                        <table class="table slots">
                            <colgroup>
                                <col class="col-section" />
                                <col class="col-subject" />
                                <col />
                                <col class="col-slot-action" />
                            </colgroup>
                            <thead>
                                <tr>
                                    <th>Section</th>
                                    <th>Subject</th>
                                    <th>Teacher</th>
                                    <th class="table-actions"><span class="sr-only">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="slot in entry.slots" :key="slot.key">
                                    <td class="ellipsis">{{ slot.section.name }}</td>
                                    <td class="ellipsis">{{ slot.subject.name }}</td>
                                    <td class="ellipsis">
                                        <span v-if="slot.mine" class="mine">You</span>
                                        <span v-else-if="slot.teacher">{{ slot.teacher.name }}</span>
                                        <span v-else class="muted">Nobody yet</span>
                                    </td>
                                    <td class="table-actions">
                                        <StatusBadge v-if="slot.pending" tone="warn">Requested</StatusBadge>
                                        <button
                                            v-else-if="!slot.teacher"
                                            type="button"
                                            class="btn btn-secondary btn-sm"
                                            :disabled="!auth.canWrite"
                                            @click="openRequest(slot)"
                                        >
                                            Request
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>

        <TeachingRequestModal v-model="requestOpen" :section="requestSection" :subject="requestSubject" @saved="onRequested" />

        <ConfirmDialog
            :model-value="withdrawing !== null"
            title="Withdraw request"
            confirm-text="Withdraw"
            :busy="busy"
            @update:model-value="withdrawing = null"
            @confirm="confirmWithdraw"
        >
            Withdraw your request to teach <strong v-if="withdrawing">{{ withdrawing.label }}</strong>? You can send a new one later.
        </ConfirmDialog>
    </div>
</template>

<style scoped>
.teaching-requests {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.back {
    margin: 0;
    font-size: 0.92rem;
}

.page-header {
    margin-bottom: 0;
}

.subtitle {
    margin: 4px 0 0;
    max-width: 640px;
}

.card {
    padding: 0;
    overflow: hidden;
}

.card-header {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    padding: 12px 16px;
    border-bottom: 1px solid var(--border);
}

.card-header .card-title {
    margin: 0;
}

.count {
    font-size: 0.92rem;
}

.state {
    margin: 0;
    padding: 24px 16px;
    text-align: center;
}

.toolbar {
    display: flex;
    align-items: center;
    gap: 8px 16px;
    flex-wrap: wrap;
    padding: 12px 16px;
    border-bottom: 1px solid var(--border);
}

.toolbar .input {
    width: auto;
}

.search {
    flex: 1 1 220px;
    max-width: 320px;
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

.table-wrap {
    overflow-x: auto;
}

.requests {
    table-layout: fixed;
    min-width: 720px;
}

.col-year {
    width: 110px;
}

.col-date {
    width: 120px;
}

.col-status {
    width: 220px;
}

.col-actions {
    width: 90px;
}

.levels {
    display: flex;
    flex-direction: column;
}

.level + .level {
    border-top: 1px solid var(--border);
}

.level-name {
    margin: 0;
    padding: 12px 16px 4px;
    font-size: 1rem;
    font-weight: 600;
}

.level-year {
    margin-left: 8px;
    font-size: 0.85rem;
    font-weight: 400;
}

.slots {
    table-layout: fixed;
    min-width: 560px;
}

.col-section,
.col-subject {
    width: 28%;
}

.col-slot-action {
    width: 120px;
}

.name {
    font-weight: 500;
    display: block;
}

.mine {
    font-weight: 500;
    color: var(--primary);
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
