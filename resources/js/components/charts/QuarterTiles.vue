<script setup>
import { computed } from 'vue';
import { quarterLabel } from '../../core/schoolYear';
import PaceRecordsBar from './PaceRecordsBar.vue';
import PaceRecordsLegend from './PaceRecordsLegend.vue';

/**
 * One tile per quarter: the record total, a status bar (tested / issued /
 * assigned, with a hover tooltip) and the pass / fail / retake counts.
 */
const props = defineProps({
    rows: { type: Array, required: true }, // [{quarter, total, tested, issued, assigned, passed, failed, retakes}]
});

const ORDER = ['tested', 'issued', 'assigned'];

const hasData = computed(() => props.rows.some((row) => row.total > 0));

const tiles = computed(() => props.rows.map((row) => ({
    ...row,
    label: quarterLabel(row.quarter),
    scored: (Number(row.passed) || 0) + (Number(row.failed) || 0),
})));
</script>

<template>
    <div class="quarter-tiles">
        <p v-if="!hasData" class="muted empty-note">No PACE records yet.</p>

        <template v-else>
            <div class="tiles">
                <div v-for="tile in tiles" :key="tile.quarter" class="tile" :class="{ empty: tile.total === 0 }">
                    <p class="tile-label muted">{{ tile.label }}</p>
                    <p class="tile-total mono">{{ tile.total }}</p>
                    <p class="tile-sub muted">{{ tile.total === 1 ? 'PACE record' : 'PACE records' }}</p>

                    <div class="tile-bar">
                        <PaceRecordsBar
                            :total="tile.total"
                            :tested="tile.tested"
                            :issued="tile.issued"
                            :assigned="tile.assigned"
                            :order="ORDER"
                            tooltip
                        />
                    </div>

                    <dl class="tile-stats">
                        <div class="stat">
                            <dt>Assigned</dt>
                            <dd class="mono assigned">{{ tile.assigned }}</dd>
                        </div>
                        <div class="stat">
                            <dt>Pass</dt>
                            <dd class="mono pass">{{ tile.passed }}</dd>
                        </div>
                        <div class="stat">
                            <dt>Fail</dt>
                            <dd class="mono fail">{{ tile.failed }}</dd>
                        </div>
                        <div class="stat">
                            <dt>Retakes</dt>
                            <dd class="mono retake">{{ tile.retakes }}</dd>
                        </div>
                    </dl>
                </div>
            </div>

            <PaceRecordsLegend :order="ORDER" class="legend" />
        </template>
    </div>
</template>

<style scoped>
.empty-note {
    padding: 24px 0;
    text-align: center;
}

.tiles {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
}

.tile {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 12px 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--bg);
    text-align: center;
}

.tile.empty {
    opacity: 0.6;
}

.tile p {
    margin: 0;
}

.tile-label {
    font-size: 0.85rem;
}

.tile-total {
    margin-top: 2px;
    font-size: 1.6rem;
    font-weight: 600;
    line-height: 1.1;
}

.tile-sub {
    font-size: 0.78rem;
}

/* The bar's track is a fixed 80px; let it fill the tile instead. */
.tile-bar {
    width: 100%;
    margin: 10px 0 8px;
    display: flex;
    justify-content: center;
}

.tile-bar :deep(.bar-track) {
    width: 100%;
    max-width: 140px;
}

.tile-stats {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 6px;
    margin: 0;
    width: 100%;
}

.stat {
    display: flex;
    flex-direction: column;
    align-items: center;
    min-width: 0;
}

.stat dt {
    font-size: 0.72rem;
    color: var(--muted);
}

.stat dd {
    margin: 0;
    font-size: 0.95rem;
    font-weight: 600;
}

.stat .assigned {
    color: var(--muted);
}

.stat .pass {
    color: #7c3aed;
}

.stat .fail {
    color: var(--danger);
}

.stat .retake {
    color: var(--warn-text);
}

.legend {
    justify-content: center;
    margin-top: 12px;
}

@media (max-width: 640px) {
    .tiles {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
</style>
