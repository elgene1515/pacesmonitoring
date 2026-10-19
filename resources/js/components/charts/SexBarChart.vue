<script setup>
import { computed, ref } from 'vue';
import { RouterLink } from 'vue-router';
import EmptyState from '../ui/EmptyState.vue';

/**
 * Vertical bars, one per grade level, each split into boys (bottom) and girls
 * (top). Hovering a bar shows the boys / girls / total numbers.
 */
const props = defineProps({
    items: { type: Array, required: true }, // [{id, label, total, male, female, to?}]
    emptyMessage: { type: String, default: 'Nothing to show yet.' },
});

const CHART_HEIGHT = 160;

const maxTotal = computed(() => Math.max(1, ...props.items.map((item) => item.total)));

const bars = computed(() => {
    const scale = CHART_HEIGHT / maxTotal.value;

    return props.items.map((item) => {
        const male = Number(item.male) || 0;
        const female = Number(item.female) || 0;
        const other = Math.max(0, (Number(item.total) || 0) - male - female);

        return {
            ...item,
            male,
            female,
            other,
            heights: { male: male * scale, female: female * scale, other: other * scale },
        };
    });
});

/**
 * The hovered / focused bar and where to pin its tooltip. The tooltip is
 * teleported to <body> and positioned against the viewport, so the chart's
 * horizontal scroll container can't clip it.
 */
const hover = ref(null); // { bar, x, y }

function showTooltip(bar, event) {
    const rect = event.currentTarget.getBoundingClientRect();
    const filled = Math.max(bar.heights.male + bar.heights.female + bar.heights.other, 8);
    hover.value = { bar, x: rect.left + rect.width / 2, y: rect.bottom - filled };
}

function hideTooltip() {
    hover.value = null;
}
</script>

<template>
    <div class="sex-bar-chart">
        <EmptyState v-if="items.length === 0" compact :message="emptyMessage" />

        <template v-else>
            <div class="bars-wrap" @scroll="hideTooltip">
                <div class="bars">
                    <div v-for="bar in bars" :key="bar.id ?? bar.label" class="bar-col">
                        <span class="bar-total mono muted">{{ bar.total || '' }}</span>
                        <div
                            class="bar-track"
                            :style="{ height: `${CHART_HEIGHT}px` }"
                            tabindex="0"
                            role="img"
                            :aria-label="`${bar.label}: ${bar.male} boys, ${bar.female} girls, ${bar.total} students`"
                            @mouseenter="showTooltip(bar, $event)"
                            @mouseleave="hideTooltip"
                            @focus="showTooltip(bar, $event)"
                            @blur="hideTooltip"
                        >
                            <div class="segment female" :style="{ height: `${bar.heights.female}px` }" />
                            <div v-if="bar.other" class="segment other" :style="{ height: `${bar.heights.other}px` }" />
                            <div class="segment male" :style="{ height: `${bar.heights.male}px` }" />
                        </div>
                        <component :is="bar.to ? RouterLink : 'span'" :to="bar.to ?? undefined" class="bar-label muted">
                            {{ bar.label }}
                        </component>
                    </div>
                </div>
            </div>

            <div class="legend">
                <span class="legend-item"><span class="swatch male" />Boys</span>
                <span class="legend-item"><span class="swatch female" />Girls</span>
            </div>

            <Teleport to="body">
                <span v-if="hover" class="tooltip" role="tooltip" :style="{ left: `${hover.x}px`, top: `${hover.y}px` }">
                    <strong>{{ hover.bar.label }}</strong>
                    <span><span class="swatch male" /><span class="mono">{{ hover.bar.male }}</span> boys</span>
                    <span><span class="swatch female" /><span class="mono">{{ hover.bar.female }}</span> girls</span>
                    <span v-if="hover.bar.other"><span class="swatch other" /><span class="mono">{{ hover.bar.other }}</span> unspecified</span>
                    <span class="tooltip-total"><span class="mono">{{ hover.bar.total }}</span> total</span>
                </span>
            </Teleport>
        </template>
    </div>
</template>

<style scoped>
.bars-wrap {
    overflow-x: auto;
    padding-top: 4px;
}

.bars {
    display: flex;
    justify-content: space-around;
    gap: 12px;
    min-width: max-content;
    padding: 0 8px;
}

.bar-col {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    flex: 1 1 0;
    width: 72px;
}

.bar-total {
    font-size: 0.8rem;
    min-height: 1.2em;
}

.bar-track {
    width: 32px;
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
    border-radius: 4px 4px 0 0;
    background: var(--bg);
    overflow: hidden;
    outline: none;
    cursor: default;
}

.bar-track:focus-visible {
    box-shadow: 0 0 0 2px var(--primary);
}

.segment {
    width: 100%;
}

.segment.male,
.swatch.male {
    background: #2f80ed;
}

.segment.female,
.swatch.female {
    background: #d946a8;
}

.segment.other,
.swatch.other {
    background: var(--status-assigned);
}

.bar-label {
    font-size: 0.82rem;
    max-width: 72px;
    text-align: center;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

a.bar-label:hover {
    color: var(--primary);
}

/* Viewport-positioned tooltip (teleported to <body>) with the boys / girls numbers. */
.tooltip {
    position: fixed;
    z-index: 1000;
    transform: translate(-50%, calc(-100% - 8px));
    display: flex;
    flex-direction: column;
    gap: 3px;
    padding: 6px 10px;
    border-radius: 6px;
    background: var(--tooltip-bg);
    color: var(--tooltip-text);
    font-size: 0.8rem;
    line-height: 1.3;
    white-space: nowrap;
    text-align: left;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.18);
    pointer-events: none;
}

.tooltip > span {
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.tooltip-total {
    padding-top: 3px;
    border-top: 1px solid rgba(255, 255, 255, 0.3);
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

.legend {
    display: flex;
    justify-content: center;
    gap: 16px;
    margin-top: 14px;
    font-size: 0.85rem;
    color: var(--muted);
}

.legend-item {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.swatch {
    width: 10px;
    height: 10px;
    border-radius: 2px;
    display: inline-block;
    flex-shrink: 0;
}
</style>
