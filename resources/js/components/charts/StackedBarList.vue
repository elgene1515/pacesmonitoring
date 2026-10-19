<script setup>
import { computed, ref } from 'vue';
import { RouterLink } from 'vue-router';
import EmptyState from '../ui/EmptyState.vue';

/**
 * A ranked list of horizontal bars, each split into tested / issued / assigned
 * segments (left to right), e.g. PACEs per subject. Hovering a bar shows all of
 * its numbers together. An item with a `to` route target renders its label as a link.
 */
const props = defineProps({
    items: { type: Array, required: true }, // [{id, label, sublabel?, total, tested, issued, assigned, to?}]
    emptyMessage: { type: String, default: 'Nothing to show yet.' },
    limit: { type: Number, default: 8 },
    /** When true, every item is shown in a scrolling list instead of being cut off at `limit`. */
    scrollable: { type: Boolean, default: false },
    /** Max height (px) of the scrolling list, only used when `scrollable` is true. */
    maxHeight: { type: Number, default: 260 },
});

const maxValue = computed(() => Math.max(1, ...props.items.map((item) => item.total)));
const visibleItems = computed(() => (props.scrollable ? props.items : props.items.slice(0, props.limit)));
const hiddenCount = computed(() => (props.scrollable ? 0 : Math.max(0, props.items.length - props.limit)));

/**
 * The hovered / focused row and where to pin its tooltip. The tooltip is
 * teleported to <body> and positioned against the viewport, so the list's
 * own scroll container can't clip it.
 */
const hover = ref(null); // { item, x, y }

function showTooltip(item, event) {
    const rect = event.currentTarget.getBoundingClientRect();
    hover.value = { item, x: rect.left + Math.min(rect.width / 2, 120), y: rect.top };
}

function hideTooltip() {
    hover.value = null;
}

function widths(item) {
    const scale = 100 / maxValue.value;

    return {
        tested: item.tested * scale,
        issued: item.issued * scale,
        assigned: item.assigned * scale,
    };
}

function describe(item) {
    return `${item.tested} tested, ${item.issued} issued, ${item.assigned} assigned, ${item.total} total`;
}
</script>

<template>
    <div class="stacked-bar-list">
        <EmptyState v-if="items.length === 0" compact :message="emptyMessage" />

        <template v-else>
            <div class="bar-rows" :class="{ scrollable }" :style="scrollable ? { maxHeight: `${maxHeight}px` } : null" @scroll="hideTooltip">
                <div v-for="item in visibleItems" :key="item.id ?? item.label" class="bar-row">
                    <div class="bar-row-label">
                        <component :is="item.to ? RouterLink : 'span'" :to="item.to ?? undefined" class="bar-row-name">
                            {{ item.label }}
                        </component>
                        <span v-if="item.sublabel" class="muted bar-row-sublabel">{{ item.sublabel }}</span>
                    </div>
                    <div
                        class="bar-row-track"
                        tabindex="0"
                        role="img"
                        :aria-label="`${item.label}: ${describe(item)}`"
                        @mouseenter="showTooltip(item, $event)"
                        @mouseleave="hideTooltip"
                        @focus="showTooltip(item, $event)"
                        @blur="hideTooltip"
                    >
                        <div class="segment tested" :style="{ width: `${widths(item).tested}%` }" />
                        <div class="segment issued" :style="{ width: `${widths(item).issued}%` }" />
                        <div class="segment assigned" :style="{ width: `${widths(item).assigned}%` }" />
                    </div>
                    <span class="bar-row-value mono">{{ item.total }}</span>
                </div>
            </div>

            <p v-if="hiddenCount > 0" class="muted bar-list-more">+{{ hiddenCount }} more not shown</p>

            <div class="legend">
                <span class="legend-item"><span class="swatch tested" />Tested</span>
                <span class="legend-item"><span class="swatch issued" />Issued</span>
                <span class="legend-item"><span class="swatch assigned" />Assigned</span>
            </div>

            <Teleport to="body">
                <span v-if="hover" class="tooltip" role="tooltip" :style="{ left: `${hover.x}px`, top: `${hover.y}px` }">
                    <span class="tooltip-item"><span class="swatch tested" /><span class="mono">{{ hover.item.tested }}</span> tested</span>
                    <span class="tooltip-item"><span class="swatch issued" /><span class="mono">{{ hover.item.issued }}</span> issued</span>
                    <span class="tooltip-item"><span class="swatch assigned" /><span class="mono">{{ hover.item.assigned }}</span> assigned</span>
                    <span class="tooltip-item tooltip-total"><span class="mono">{{ hover.item.total }}</span> total</span>
                </span>
            </Teleport>
        </template>
    </div>
</template>

<style scoped>
.stacked-bar-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.bar-rows {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.bar-rows.scrollable {
    overflow-y: auto;
    padding-right: 6px;
}

.bar-row {
    display: grid;
    grid-template-columns: minmax(90px, 160px) 1fr 36px;
    align-items: center;
    gap: 10px;
}

.bar-row-label {
    display: flex;
    flex-direction: column;
    min-width: 0;
    font-size: 0.92rem;
}

.bar-row-name {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.bar-row-sublabel {
    font-size: 0.78rem;
}

/* Rounded track that clips its segments, so a single-status bar keeps round ends. */
.bar-row-track {
    height: 10px;
    border-radius: 999px;
    background: var(--bg);
    display: flex;
    overflow: hidden;
    outline: none;
    cursor: default;
}

.bar-row-track:focus-visible {
    box-shadow: 0 0 0 2px var(--primary);
}

.segment {
    height: 100%;
}

.segment.tested,
.swatch.tested {
    background: var(--status-tested);
}

.segment.issued,
.swatch.issued {
    background: var(--status-issued);
}

.segment.assigned,
.swatch.assigned {
    background: var(--status-assigned);
}

/* Viewport-positioned tooltip (teleported to <body>) listing every number of the row. */
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

.bar-row-value {
    text-align: right;
    font-size: 0.9rem;
}

.bar-list-more {
    margin: 4px 0 0;
    font-size: 0.85rem;
}

.legend {
    display: flex;
    justify-content: center;
    gap: 16px;
    margin-top: 6px;
    font-size: 0.85rem;
    color: var(--muted);
}

.legend-item {
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.swatch {
    width: 8px;
    height: 8px;
    border-radius: 2px;
    display: inline-block;
    flex-shrink: 0;
}

.legend .swatch {
    width: 10px;
    height: 10px;
}
</style>
