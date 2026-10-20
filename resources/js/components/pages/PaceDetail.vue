<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { paceService } from '../../services';
import { formatDate, formatScore, pluralize } from '../../core/format';
import { buildRecordRings } from '../../core/recordRings';
import PaceFormModal from '../paces/PaceFormModal.vue';
import PaceRequestFormModal from '../paces/PaceRequestFormModal.vue';
import StockControl from '../paces/StockControl.vue';
import EmptyState from '../ui/EmptyState.vue';
import LayeredRings from '../charts/LayeredRings.vue';
import PaceRecordsExplorer from '../assignments/PaceRecordsExplorer.vue';

const props = defineProps({
    id: { type: Number, required: true },
});

/** Legend / table order shared with the PACEs page: tested / issued / assigned. */
const RECORD_ORDER = ['tested', 'issued', 'assigned'];

const pace = ref(null);
const loading = ref(true);
const loadError = ref('');
const editOpen = ref(false);
const requestOpen = ref(false);

const records = computed(() => pace.value?.assignments ?? []);

/** The rows the assignments table is currently showing (follows its search and filters). */
const shownRecords = ref([]);

/** Layered rings (assigned / issued / tested / pass / retakes / fail) for the shown rows. */
const shownRings = computed(() => buildRecordRings(shownRecords.value));

const isNarrowed = computed(() => shownRecords.value.length !== records.value.length);

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

/**
 * Average score across the shown records (assigned, issued and tested alike):
 * records without a score count as 0, so the figure reflects overall progress
 * rather than only the PACEs already tested. Follows the table's filters, like
 * the ring chart.
 */
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
        pace.value = await paceService.get(props.id);
    } catch (err) {
        pace.value = null;
        loadError.value = err?.isNotFound ? 'This PACE could not be found.' : (err?.message ?? 'Failed to load the PACE.');
    } finally {
        loading.value = false;
    }
}

/** Keep the loaded PACE in step with a − / + change without reloading its records. */
function onStockAdjusted(updated) {
    if (pace.value && updated) {
        pace.value.stock = updated.stock;
    }
}

watch(() => props.id, load);

onMounted(load);
</script>

<template>
    <div class="pace-detail">
        <p class="back">
            <RouterLink :to="{ name: 'paces' }">&larr; PACEs</RouterLink>
        </p>

        <p v-if="loading" class="muted">Loading…</p>

        <EmptyState v-else-if="!pace" :message="loadError">
            <template #action>
                <RouterLink :to="{ name: 'paces' }" class="btn btn-secondary btn-sm">Back to PACEs</RouterLink>
            </template>
        </EmptyState>

        <template v-else>
            <div class="page-header">
                <div>
                    <h1>{{ pace.label }}</h1>
                    <p class="muted subtitle">{{ pluralize(records.length, 'assignment') }}</p>
                </div>
                <div class="page-actions">
                    <button type="button" class="btn btn-secondary" @click="requestOpen = true">Request stock</button>
                    <button type="button" class="btn btn-secondary" @click="editOpen = true">Edit</button>
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
                        <div class="info-item info-stock">
                            <dt>Stock on hand</dt>
                            <dd>
                                <StockControl :pace="pace" @adjusted="onStockAdjusted" />
                                <RouterLink v-if="pace.hasPendingRequest" :to="{ name: 'pace-requests' }" class="small">
                                    {{ pluralize(pace.pendingRequestsCount, 'stock request') }} waiting for your admin
                                </RouterLink>
                                <span v-else class="muted small">Issuing this PACE to a student takes one booklet.</span>
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

            <PaceRecordsExplorer
                title="Assignments"
                :records="records"
                :subjects="subjects"
                :show-pace="false"
                show-grade
                year-filter
                quarter-filter
                :order="RECORD_ORDER"
                empty-message="This PACE has not been assigned to any student yet."
                @filtered="shownRecords = $event"
            />

            <PaceFormModal v-model="editOpen" :pace="pace" @saved="load" />

            <PaceRequestFormModal v-model="requestOpen" :pace="pace" @saved="load" />
        </template>
    </div>
</template>

<style scoped>
.pace-detail {
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

/* The stock row spans the grid so the − / + control and its hint have room. */
.info-stock {
    grid-column: 1 / -1;
}

.info-stock .small {
    display: block;
    margin-top: 4px;
    font-size: 0.82rem;
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
