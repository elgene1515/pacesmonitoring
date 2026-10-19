<script setup>
import { computed } from 'vue';

/**
 * A single-value progress ring, e.g. "42 of 60 tested".
 */
const props = defineProps({
    value: { type: Number, required: true },
    total: { type: Number, required: true },
    label: { type: String, required: true },
    /** Overrides the default "{value} of {total}" caption. */
    caption: { type: String, default: '' },
    color: { type: String, default: 'var(--success)' },
    size: { type: Number, default: 108 },
    strokeWidth: { type: Number, default: 12 },
});

const radius = computed(() => (props.size - props.strokeWidth) / 2);
const circumference = computed(() => 2 * Math.PI * radius.value);
const percent = computed(() => (props.total > 0 ? Math.min(1, props.value / props.total) : 0));
const dashOffset = computed(() => circumference.value * (1 - percent.value));
const percentLabel = computed(() => `${Math.round(percent.value * 100)}%`);
const captionText = computed(() => props.caption || `${props.value} of ${props.total}`);
</script>

<template>
    <div class="donut-stat">
        <svg
            :width="size"
            :height="size"
            :viewBox="`0 0 ${size} ${size}`"
            role="img"
            :aria-label="`${label}: ${total > 0 ? percentLabel : 'no data'}`"
        >
            <circle :cx="size / 2" :cy="size / 2" :r="radius" fill="none" stroke="var(--border)" :stroke-width="strokeWidth" />
            <circle
                v-if="total > 0"
                :cx="size / 2"
                :cy="size / 2"
                :r="radius"
                fill="none"
                :stroke="color"
                :stroke-width="strokeWidth"
                stroke-linecap="round"
                :stroke-dasharray="circumference"
                :stroke-dashoffset="dashOffset"
                class="donut-arc"
            />
            <text x="50%" y="50%" text-anchor="middle" dominant-baseline="central" class="donut-value">
                {{ total > 0 ? percentLabel : '—' }}
            </text>
        </svg>
        <div class="donut-info">
            <p class="donut-label">{{ label }}</p>
            <p class="muted donut-caption">{{ total > 0 ? captionText : 'No data yet' }}</p>
        </div>
    </div>
</template>

<style scoped>
.donut-stat {
    display: flex;
    align-items: center;
    gap: 16px;
}

.donut-arc {
    transform-origin: center;
    transform: rotate(-90deg);
    transition: stroke-dashoffset 0.3s ease;
}

.donut-value {
    font-size: 1.1rem;
    font-weight: 600;
    fill: var(--text);
    font-family: var(--font);
}

.donut-info {
    min-width: 0;
}

.donut-label {
    margin: 0;
    font-weight: 500;
}

.donut-caption {
    margin: 2px 0 0;
    font-size: 0.85rem;
}
</style>
