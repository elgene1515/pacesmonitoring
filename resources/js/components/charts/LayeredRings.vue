<script setup>
import { computed } from 'vue';

/**
 * Generic layered ring chart: one concentric ring per entry (first entry
 * outermost), each filled to `value / total` of its own total. A legend beside
 * the chart lists the label, "value of total" and the percentage.
 *
 *  props   rings   { key, label, value, total, color, hint? }[]   outer to inner
 *          center  string                                        text in the middle (e.g. a grand total)
 *          caption string                                        small text under the centre value
 */
const props = defineProps({
    rings: { type: Array, required: true },
    center: { type: [String, Number], default: '' },
    caption: { type: String, default: '' },
    size: { type: Number, default: 200 },
    strokeWidth: { type: Number, default: 9 },
    gap: { type: Number, default: 4 },
});

const layers = computed(() =>
    props.rings.map((ring, index) => {
        const radius = props.size / 2 - props.strokeWidth / 2 - index * (props.strokeWidth + props.gap);
        const circumference = 2 * Math.PI * radius;
        const value = Number(ring.value) || 0;
        const total = Number(ring.total) || 0;
        const fraction = total > 0 ? Math.min(1, value / total) : 0;

        return {
            ...ring,
            value,
            total,
            fraction,
            percent: Math.round(fraction * 100),
            radius,
            circumference,
            dashOffset: circumference * (1 - fraction),
        };
    }),
);

const description = computed(() =>
    layers.value.map((layer) => `${layer.label}: ${layer.value} of ${layer.total} (${layer.total > 0 ? `${layer.percent}%` : 'no data'})`).join(', '),
);
</script>

<template>
    <div class="layered-rings">
        <svg :width="size" :height="size" :viewBox="`0 0 ${size} ${size}`" role="img" :aria-label="description">
            <g v-for="layer in layers" :key="layer.key">
                <title>{{ layer.label }}: {{ layer.value }} of {{ layer.total }}{{ layer.total > 0 ? ` (${layer.percent}%)` : '' }}</title>
                <circle :cx="size / 2" :cy="size / 2" :r="layer.radius" fill="none" stroke="var(--bg)" :stroke-width="strokeWidth" />
                <circle
                    v-if="layer.value > 0 && layer.total > 0"
                    :cx="size / 2"
                    :cy="size / 2"
                    :r="layer.radius"
                    fill="none"
                    :stroke="layer.color"
                    :stroke-width="strokeWidth"
                    stroke-linecap="round"
                    :stroke-dasharray="layer.circumference"
                    :stroke-dashoffset="layer.dashOffset"
                    class="arc"
                />
            </g>
            <text x="50%" :y="caption ? '47%' : '50%'" text-anchor="middle" dominant-baseline="central" class="value">{{ center }}</text>
            <text v-if="caption" x="50%" y="58%" text-anchor="middle" dominant-baseline="central" class="caption">{{ caption }}</text>
        </svg>

        <ul class="legend">
            <li v-for="layer in layers" :key="layer.key" class="legend-item" :title="layer.hint || ''">
                <span class="swatch" :style="{ background: layer.color }" />
                <span class="legend-label">{{ layer.label }}</span>
                <span class="mono legend-count">{{ layer.value }}<span class="muted"> / {{ layer.total }}</span></span>
                <span class="muted legend-percent">{{ layer.total > 0 ? `${layer.percent}%` : '—' }}</span>
            </li>
        </ul>
    </div>
</template>

<style scoped>
.layered-rings {
    display: flex;
    align-items: center;
    gap: 24px;
    flex-wrap: wrap;
}

.layered-rings svg {
    flex-shrink: 0;
}

.arc {
    transform-origin: center;
    transform: rotate(-90deg);
    transition: stroke-dashoffset 0.3s ease;
}

.value {
    font-size: 1.4rem;
    font-weight: 600;
    fill: var(--text);
    font-family: var(--font);
}

.caption {
    font-size: 0.72rem;
    fill: var(--muted);
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
    grid-template-columns: 10px minmax(70px, auto) minmax(4.5em, auto) auto;
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
