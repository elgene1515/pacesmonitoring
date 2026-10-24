<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { adminService } from '../../../services';
import { useAuthStore } from '../../../stores/auth';
import { notify } from '../../../stores/notifications';
import { formatDate, formatScore, pluralize } from '../../../core/format';
import { buildRecordRings } from '../../../core/recordRings';
import PaceFormModal from '../../paces/PaceFormModal.vue';
import StockActionModal from '../../paces/StockActionModal.vue';
import EmptyState from '../../ui/EmptyState.vue';
import StatusBadge from '../../ui/StatusBadge.vue';
import AppIcon from '../../ui/AppIcon.vue';
import LayeredRings from '../../charts/LayeredRings.vue';
import PaceRecordsExplorer from '../../assignments/PaceRecordsExplorer.vue';

const props = defineProps({
    id: { type: Number, required: true },
});

/** Legend / table order shared with the other pages: tested / issued / assigned. */
const RECORD_ORDER = ['tested', 'issued', 'assigned'];

const auth = useAuthStore();

const pace = ref(null);
const loading = ref(true);
const loadError = ref('');

const movements = ref([]);
const loadingMovements = ref(false);
const teachers = ref([]);

const editOpen = ref(false);
const stockOpen = ref(false);
const stockMode = ref('receive');
const stockTeacherId = ref(null);

const records = computed(() => pace.value?.assignments ?? []);

/** "School · #1001 · English": the platform owner looks at PACEs of many schools, so the school leads. */
const heading = computed(() => {
    const school = auth.isPlatform ? pace.value?.organizationName : '';

    return school ? `${school} · ${pace.value.label}` : (pace.value?.label ?? '');
});

/** The rows the assignments table is currently showing (follows its search and filters). */
const shownRecords = ref([]);

/** Layered rings (assigned / issued / tested / pass / retakes / fail) for the shown rows. */
const shownRings = computed(() => buildRecordRings(shownRecords.value));

const isNarrowed = computed(() => shownRecords.value.length !== records.value.length);

/** Teachers holding booklets of this PACE, most first. */
const holdings = computed(() => (pace.value?.holdings ?? [])
    .filter((holding) => holding.quantity > 0)
    .sort((a, b) => b.quantity - a.quantity || String(a.teacher?.name ?? '').localeCompare(String(b.teacher?.name ?? ''))));

/** Subjects this PACE has been assigned under, for the table's subject filter. */
const subjects = computed(() => {
    const map = new Map();

    for (const record of records.value) {
        if (record.subjectId && !map.has(record.subjectId)) {
            map.set(record.subjectId, { id: record.subjectId, name: record.subjectName || `Subject #${record.subjectId}` });
        }
    }

    return [...map.values()].sort((a, b) => a.name.localeCompare(b.name));
});

/** Average score across the shown records; untested PACEs count as 0. Follows the table's filters. */
const averageAll = computed(() => {
    if (shownRecords.value.length === 0) {
        return null;
    }

    const sum = shownRecords.value.reduce((carry, record) => carry + (Number(record.score) || 0), 0);

    return sum / shownRecords.value.length;
});

async function load() {
    loading.value = true;
    loadError.value = '';

    try {
        pace.value = await adminService.getPace(props.id);
        loadMovements();

        if (teachers.value.length === 0) {
            loadTeachers();
        }
    } catch (err) {
        pace.value = null;
        loadError.value = err?.isNotFound ? 'This PACE could not be found.' : (err?.message ?? 'Failed to load the PACE.');
    } finally {
        loading.value = false;
    }
}

async function loadMovements() {
    loadingMovements.value = true;

    try {
        movements.value = await adminService.listMovements(props.id);
    } catch (err) {
        notify.error(err?.message ?? 'Failed to load the stock ledger.');
    } finally {
        loadingMovements.value = false;
    }
}

async function loadTeachers() {
    try {
        teachers.value = await adminService.listTeachers({ branch_id: pace.value?.branchId ?? '' });
    } catch {
        teachers.value = [];
    }
}

function openStock(mode, teacherId = null) {
    stockMode.value = mode;
    stockTeacherId.value = teacherId;
    stockOpen.value = true;
}

/** A stock move changes the pool, the holdings and the ledger: reload them all. */
function onStockSaved() {
    load();
}

watch(() => props.id, load);

onMounted(load);
</script>

<template>
    <div class="admin-pace-detail">
        <p class="back">
            <RouterLink :to="{ name: 'admin-paces' }">&larr; PACEs</RouterLink>
        </p>

        <p v-if="loading && !pace" class="muted">Loading…</p>

        <EmptyState v-else-if="!pace" :message="loadError">
            <template #action>
                <RouterLink :to="{ name: 'admin-paces' }" class="btn btn-secondary btn-sm">Back to PACEs</RouterLink>
            </template>
        </EmptyState>

        <template v-else>
            <div class="page-header">
                <div>
                    <h1>{{ heading }}</h1>
                    <p class="muted subtitle">
                        {{ pace.branchName ? `${pace.branchName} catalog · ` : '' }}{{ pluralize(records.length, 'assignment') }}
                    </p>
                </div>
                <div class="page-actions">
                    <button type="button" class="btn" :disabled="!auth.canWrite" @click="openStock('receive')">Receive booklets</button>
                    <button type="button" class="btn btn-secondary" :disabled="!auth.canWrite || !pace.hasPoolStock" @click="openStock('distribute')">Give to a teacher</button>
                    <button type="button" class="btn btn-secondary" :disabled="!auth.canWrite" @click="openStock('adjust')">Correct pool count</button>
                    <button type="button" class="btn btn-secondary" :disabled="!auth.canWrite" @click="editOpen = true">Edit</button>
                </div>
            </div>

            <!-- PACE information on the left; the records chart and average on the right, stretched to the same height. -->
            <div class="info-row">
                <section class="card info-card">
                    <h2 class="card-title">PACE information</h2>
                    <dl class="info">
                        <div class="info-item">
                            <dt>Number</dt>
                            <dd class="mono">{{ pace.number }}</dd>
                        </div>
                        <div class="info-item">
                            <dt>Name</dt>
                            <dd>{{ pace.name }}</dd>
                        </div>
                        <div class="info-item">
                            <dt>Branch</dt>
                            <dd>{{ pace.branchName || '—' }}</dd>
                        </div>
                        <div class="info-item">
                            <dt>Pool</dt>
                            <dd>
                                <span class="mono stock-value" :class="`level-${pace.poolLevel}`">{{ pace.poolStock }}</span>
                                <StatusBadge :tone="pace.poolTone">{{ pace.poolLabel }}</StatusBadge>
                            </dd>
                        </div>
                        <div class="info-item">
                            <dt>Held by teachers</dt>
                            <dd class="mono">{{ pace.holdingsTotal ?? 0 }}</dd>
                        </div>
                        <div class="info-item">
                            <dt>Stock requests</dt>
                            <dd>
                                <RouterLink v-if="pace.hasPendingRequest" :to="{ name: 'admin-pace-requests' }">
                                    {{ pluralize(pace.pendingRequestsCount, 'pending request') }}
                                </RouterLink>
                                <span v-else class="muted">None pending</span>
                            </dd>
                        </div>
                        <div class="info-item">
                            <dt>Assignments</dt>
                            <dd class="mono">{{ records.length }}</dd>
                        </div>
                        <div class="info-item">
                            <dt>Registered</dt>
                            <dd>{{ formatDate(pace.createdAt) || '—' }}</dd>
                        </div>
                    </dl>
                </section>

                <section class="card stats-card">
                    <h2 class="card-title">
                        PACE records
                        <span v-if="isNarrowed" class="muted stats-scope">· {{ shownRecords.length }} of {{ records.length }} (filtered below)</span>
                    </h2>
                    <div class="stats-body">
                        <EmptyState
                            v-if="shownRecords.length === 0"
                            compact
                            :message="records.length === 0 ? 'This PACE has not been assigned yet.' : 'No records match the filters below.'"
                        />
                        <LayeredRings v-else :rings="shownRings" :center="shownRecords.length" :size="176" :stroke-width="8" />
                        <div class="average">
                            <p class="muted average-label">Average score</p>
                            <p class="mono average-value">{{ averageAll === null ? '—' : formatScore(averageAll) }}</p>
                            <p class="muted average-hint">
                                Across {{ isNarrowed ? 'the shown' : 'all' }} {{ pluralize(shownRecords.length, 'record') }};
                                untested PACEs count as 0.
                            </p>
                        </div>
                    </div>
                </section>
            </div>

            <div class="grid-2 pair">
                <section class="card block">
                    <div class="block-header">
                        <h2 class="card-title">Teachers' booklets</h2>
                        <span class="muted small-note">{{ pluralize(holdings.length, 'teacher') }}</span>
                    </div>

                    <EmptyState v-if="holdings.length === 0" compact message="No teacher holds booklets of this PACE.">
                        <template #action>
                            <button type="button" class="btn btn-link btn-sm" :disabled="!auth.canWrite || !pace.hasPoolStock" @click="openStock('distribute')">
                                Give some to a teacher
                            </button>
                        </template>
                    </EmptyState>

                    <table v-else class="table holdings">
                        <colgroup>
                            <col />
                            <col class="col-qty" />
                            <col class="col-set" />
                        </colgroup>
                        <thead>
                            <tr>
                                <th>Teacher</th>
                                <th class="text-right">Booklets</th>
                                <th class="table-actions"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="holding in holdings" :key="holding.teacherId">
                                <td class="ellipsis">
                                    <RouterLink :to="{ name: 'admin-user-detail', params: { id: holding.teacherId } }">
                                        {{ holding.teacher?.name || `Teacher #${holding.teacherId}` }}
                                    </RouterLink>
                                </td>
                                <td class="mono text-right">{{ holding.quantity }}</td>
                                <td class="table-actions">
                                    <button
                                        type="button"
                                        class="btn btn-link btn-sm btn-icon"
                                        :title="`Set how many booklets ${holding.teacher?.name ?? 'this teacher'} holds`"
                                        :aria-label="`Set the booklets of ${holding.teacher?.name ?? 'this teacher'}`"
                                        :disabled="!auth.canWrite"
                                        @click="openStock('holding', holding.teacherId)"
                                    >
                                        <AppIcon name="pencil" />
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <section class="card block">
                    <div class="block-header">
                        <h2 class="card-title">Stock ledger</h2>
                        <span class="muted small-note">Newest first</span>
                    </div>

                    <p v-if="loadingMovements && movements.length === 0" class="muted state">Loading…</p>

                    <EmptyState v-else-if="movements.length === 0" compact message="No stock movements yet." />

                    <div v-else class="ledger-wrap">
                        <table class="table ledger">
                            <colgroup>
                                <col class="col-when" />
                                <col />
                                <col class="col-delta" />
                            </colgroup>
                            <thead>
                                <tr>
                                    <th>When</th>
                                    <th>Movement</th>
                                    <th class="text-right">Pool</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="movement in movements" :key="movement.id">
                                    <td class="nowrap">{{ formatDate(movement.createdAt) || '—' }}</td>
                                    <td class="ellipsis" :title="movement.note">
                                        {{ movement.description }}
                                        <span class="muted small">
                                            {{ movement.type === 'distributed' || movement.type === 'returned' ? `${movement.quantity} booklet${movement.quantity === 1 ? '' : 's'}` : '' }}
                                            {{ movement.performerName ? `· by ${movement.performerName}` : '' }}
                                            {{ movement.paceRequestId ? '· stock request' : '' }}
                                            {{ movement.note ? `· “${movement.note}”` : '' }}
                                        </span>
                                    </td>
                                    <td class="mono text-right" :class="movement.poolDelta < 0 ? 'text-danger' : 'gain'">{{ movement.poolDeltaLabel }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <PaceRecordsExplorer
                title="Assignments"
                :records="records"
                :subjects="subjects"
                :show-pace="false"
                show-grade
                year-filter
                quarter-filter
                admin
                :order="RECORD_ORDER"
                empty-message="This PACE has not been assigned to any student yet."
                @filtered="shownRecords = $event"
            />

            <PaceFormModal v-model="editOpen" :pace="pace" @saved="load" />

            <StockActionModal
                v-model="stockOpen"
                :mode="stockMode"
                :pace="pace"
                :teachers="teachers"
                :teacher-id="stockTeacherId"
                @saved="onStockSaved"
            />
        </template>
    </div>
</template>

<style scoped>
.admin-pace-detail {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.back {
    margin: 0;
    font-size: 0.92rem;
}

/* The PACE's name stays pinned under the top bar while the page scrolls (like the student page). */
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

.stats-scope {
    font-weight: 400;
    font-size: 0.85rem;
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

.stock-value {
    display: inline-block;
    min-width: 2.5ch;
    margin-right: 6px;
    font-weight: 600;
}

.stock-value.level-out {
    color: var(--danger);
}

.stock-value.level-low {
    color: var(--warn-text);
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

.pair > .card {
    min-width: 0;
}

.block {
    padding: 0;
    overflow: hidden;
}

.block-header {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 12px;
    padding: 12px 16px;
    border-bottom: 1px solid var(--border);
}

.block .card-title {
    margin: 0;
}

.small-note {
    font-size: 0.85rem;
}

.state {
    margin: 0;
    padding: 24px 16px;
    text-align: center;
}

.holdings,
.ledger {
    table-layout: fixed;
}

.col-qty {
    width: 100px;
}

.col-set {
    width: 60px;
}

.ledger-wrap {
    max-height: 360px;
    overflow-y: auto;
}

.col-when {
    width: 120px;
}

.col-delta {
    width: 80px;
}

.gain {
    color: var(--success);
}

.ellipsis {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

td .small {
    display: block;
    font-size: 0.8rem;
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
