<script setup>
import { computed, ref } from 'vue';

const STATUSES = ['assigned', 'issued', 'tested'];
const LABELS = { assigned: 'assigned', issued: 'issued', tested: 'tested' };

/**
 * Compact inline bar for a PACE records count, split into assigned / issued /
 * tested in proportion to the row's own total. Shows just the number (or a
 * dash) when there is no breakdown to graph.
 *
 * With `tooltip`, the number is dropped and the breakdown appears in a small
 * popup when the bar is hovered or focused.
 */
const props = defineProps({
    total: { type: Number, default: null },
    tested: { type: Number, default: null },
    issued: { type: Number, default: null },
    assigned: { type: Number, default: null },
    /** Label the bar "tested/total" instead of just the total. */
    showTested: { type: Boolean, default: false },
    /** Left-to-right order of the segments (any permutation of assigned / issued / tested). */
    order: {
        type: Array,
        default: () => ['assigned', 'issued', 'tested'],
        validator: (value) => value.every((key) => ['assigned', 'issued', 'tested'].includes(key)),
    },
    /** Hide the number next to the bar and show the breakdown in a hover tooltip instead. */
    tooltip: { type: Boolean, default: false },
});

const counts = computed(() => ({ assigned: props.assigned, issued: props.issued, tested: props.tested }));

const hasTotal = computed(() => props.total !== null && props.total !== undefined);

const hasBreakdown = computed(
    () => props.total > 0 && STATUSES.every((key) => Number.isFinite(counts.value[key])),
);

const segments = computed(() =>
    hasBreakdown.value
        ? props.order.map((key) => ({ key, count: counts.value[key] })).filter((segment) => segment.count > 0)
        : [],
);

/** "2 tested, 1 issued, 1 assigned" in the chosen order. */
const description = computed(() => {
    if (props.total === 0) {
        return 'No PACE records';
    }

    if (!hasBreakdown.value) {
        return `${props.total} PACE records`;
    }

    return props.order.map((key) => `${counts.value[key]} ${LABELS[key]}`).join(', ');
});

const tooltipItems = computed(() =>
    hasBreakdown.value ? props.order.map((key) => ({ key, label: LABELS[key], count: counts.value[key] })) : [],
);

/**
 * Where to pin the tooltip while the bar is hovered / focused. It is teleported
 * to <body> and positioned against the viewport, so a scrolling table or a card
 * with hidden overflow can't clip it.
 */
const anchor = ref(null); // { x, y }

function showTooltip(event) {
    if (!props.tooltip) {
        return;
    }

    const track = event.currentTarget.querySelector('.bar-track') ?? event.currentTarget;
    const rect = track.getBoundingClientRect();
    anchor.value = { x: rect.left + rect.width / 2, y: rect.top };
}

function hideTooltip() {
    anchor.value = null;
}
</script>

<template>
    <span v-if="!hasTotal" class="pace-records-bar muted mono">—</span>
    <span v-else-if="!hasBreakdown && !tooltip" class="pace-records-bar mono">{{ total }}</span>
    <span
        v-else
        :class="['pace-records-bar', { 'has-tooltip': tooltip }]"
        :tabindex="tooltip ? 0 : undefined"
        @mouseenter="showTooltip"
        @mouseleave="hideTooltip"
        @focus="showTooltip"
        @blur="hideTooltip"
    >
        <span class="bar-track" role="img" :aria-label="description">
            <span
                v-for="segment in segments"
                :key="segment.key"
                :class="['segment', segment.key]"
                :style="{ flexGrow: segment.count }"
                :title="tooltip ? undefined : `${segment.count} ${segment.key}`"
            />
        </span>

        <template v-if="!tooltip">
            <span v-if="showTested" class="bar-value with-tested mono" :title="`${tested} of ${total} tested`">{{ tested }}/{{ total }}</span>
            <span v-else class="bar-value mono">{{ total }}</span>
        </template>

        <Teleport v-else to="body">
            <span v-if="anchor" class="tooltip" role="tooltip" :style="{ left: `${anchor.x}px`, top: `${anchor.y}px` }">
                <template v-if="tooltipItems.length === 0">{{ description }}</template>
                <template v-else>
                    <span v-for="item in tooltipItems" :key="item.key" class="tooltip-item">
                        <span :class="['swatch', item.key]" />
                        <span class="mono">{{ item.count }}</span>
                        {{ item.label }}
                    </span>
                    <span class="tooltip-item tooltip-total">
                        <span class="mono">{{ total }}</span>
                        total
                    </span>
                </template>
            </span>
        </Teleport>
    </span>
</template>

<style scoped>
.pace-records-bar {
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.pace-records-bar.has-tooltip {
    cursor: default;
    outline: none;
}

.bar-track {
    display: flex;
    gap: 2px;
    width: 80px;
    height: 8px;
    flex-shrink: 0;
    border-radius: 999px;
    background: var(--bg);
    box-shadow: inset 0 0 0 1px var(--border);
    overflow: hidden;
}

.has-tooltip:focus-visible .bar-track {
    box-shadow: inset 0 0 0 1px var(--border), 0 0 0 2px var(--primary);
}

/* flex-grow is set inline to each count, so segments share the track (minus gaps) proportionally. */
.segment {
    flex: 0 1 0;
    min-width: 2px;
}

.segment.assigned,
.swatch.assigned {
    background: var(--status-assigned);
}

.segment.issued,
.swatch.issued {
    background: var(--status-issued);
}

.segment.tested,
.swatch.tested {
    background: var(--status-tested);
}

.bar-value {
    min-width: 2.2em;
    text-align: right;
    font-size: 0.9rem;
}

.bar-value.with-tested {
    min-width: 4em;
}

/* Viewport-positioned tooltip (teleported to <body>) with the per-status numbers. */
.tooltip {
    position: fixed;
    z-index: 1000;
    transform: translate(-50%, calc(-100% - 8px));
    display: inline-flex;
    align-items: center;
    gap: 10px;
    padding: 5px 9px;
    border-radius: 6px;
    background: var(--tooltip-bg);
    color: var(--tooltip-text);
    font-size: 0.8rem;
    line-height: 1.3;
    white-space: nowrap;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.18);
    pointer-events: none;
}

.tooltip::after {
    content: '';
    position: absolute;
    top: 100%;
    left: 50%;
    transform: translateX(-50%);
    border: 5px solid transparent;
    border-top-color: var(--text);
}

.tooltip-item {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.tooltip-total {
    padding-left: 10px;
    border-left: 1px solid rgba(255, 255, 255, 0.3);
}

.swatch {
    width: 8px;
    height: 8px;
    border-radius: 2px;
    flex-shrink: 0;
}
</style>
