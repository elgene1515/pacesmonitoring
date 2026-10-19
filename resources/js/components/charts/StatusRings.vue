<script setup>
import { computed } from 'vue';

const LABELS = { assigned: 'Assigned', issued: 'Issued', tested: 'Tested' };
const COLORS = { assigned: 'var(--status-assigned)', issued: 'var(--status-issued)', tested: 'var(--status-tested)' };

/**
 * Layered ring chart for a PACE records breakdown: one concentric ring per
 * status (assigned outermost, then issued, tested innermost), each filled in
 * proportion to that status's share of all records. The total sits in the
 * middle and a legend with counts and percentages beside it.
 */
const props = defineProps({
    total: { type: Number, default: 0 },
    assigned: { type: Number, default: 0 },
    issued: { type: Number, default: 0 },
    tested: { type: Number, default: 0 },
    /** Outer-to-inner ring order (any permutation of assigned / issued / tested). */
    order: {
        type: Array,
        default: () => ['assigned', 'issued', 'tested'],
        validator: (value) => value.every((key) => ['assigned', 'issued', 'tested'].includes(key)),
    },
    size: { type: Number, default: 136 },
    strokeWidth: { type: Number, default: 11 },
    gap: { type: Number, default: 4 },
    /** Hide the legend (e.g. when the surrounding card lists the numbers already). */
    hideLegend: { type: Boolean, default: false },
});

const counts = computed(() => ({
    assigned: Number(props.assigned) || 0,
    issued: Number(props.issued) || 0,
    tested: Number(props.tested) || 0,
}));

const total = computed(() => Number(props.total) || 0);

const rings = computed(() =>
    props.order.map((key, index) => {
        const radius = props.size / 2 - props.strokeWidth / 2 - index * (props.strokeWidth + props.gap);
        const circumference = 2 * Math.PI * radius;
        const count = counts.value[key];
        const fraction = total.value > 0 ? Math.min(1, count / total.value) : 0;

        return {
            key,
            label: LABELS[key],
            color: COLORS[key],
            count,
            fraction,
            percent: Math.round(fraction * 100),
            radius,
            circumference,
            dashOffset: circumference * (1 - fraction),
        };
    }),
);

const description = computed(() =>
    total.value > 0
        ? `${total.value} PACE records: ${rings.value.map((ring) => `${ring.count} ${ring.label.toLowerCase()} (${ring.percent}%)`).join(', ')}`
        : 'No PACE records',
);
</script>

<template>
    <div class="status-rings">
        <svg :width="size" :height="size" :viewBox="`0 0 ${size} ${size}`" role="img" :aria-label="description">
            <g v-for="ring in rings" :key="ring.key" class="ring">
                <title>{{ ring.label }}: {{ ring.count }} of {{ total }} ({{ ring.percent }}%)</title>
                <circle
                    :cx="size / 2"
                    :cy="size / 2"
                    :r="ring.radius"
                    fill="none"
                    stroke="var(--bg)"
                    :stroke-width="strokeWidth"
                />
                <circle
                    v-if="ring.count > 0"
                    :cx="size / 2"
                    :cy="size / 2"
                    :r="ring.radius"
                    fill="none"
                    :stroke="ring.color"
                    :stroke-width="strokeWidth"
                    stroke-linecap="round"
                    :stroke-dasharray="ring.circumference"
                    :stroke-dashoffset="ring.dashOffset"
                    class="arc"
                />
            </g>
            <text x="50%" y="50%" text-anchor="middle" dominant-baseline="central" class="value">{{ total }}</text>
        </svg>

        <ul v-if="!hideLegend" class="legend">
            <li v-for="ring in rings" :key="ring.key" class="legend-item">
                <span class="swatch" :style="{ background: ring.color }" />
                <span class="legend-label">{{ ring.label }}</span>
                <span class="mono legend-count">{{ ring.count }}</span>
                <span class="muted legend-percent">{{ total > 0 ? `${ring.percent}%` : '—' }}</span>
            </li>
        </ul>
    </div>
</template>

<style scoped>
.status-rings {
    display: flex;
    align-items: center;
    gap: 18px;
}

.status-rings svg {
    flex-shrink: 0;
}

.arc {
    transform-origin: center;
    transform: rotate(-90deg);
    transition: stroke-dashoffset 0.3s ease;
}

.value {
    font-size: 1.25rem;
    font-weight: 600;
    fill: var(--text);
    font-family: var(--font);
}

.legend {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 6px;
    font-size: 0.9rem;
}

.legend-item {
    display: grid;
    grid-template-columns: 10px auto minmax(2.2em, auto) auto;
    align-items: center;
    gap: 8px;
}

.swatch {
    width: 10px;
    height: 10px;
    border-radius: 2px;
}

.legend-count {
    text-align: right;
}

.legend-percent {
    font-size: 0.82rem;
}
</style>
