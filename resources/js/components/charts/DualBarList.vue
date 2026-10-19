<script setup>
import { computed } from 'vue';
import { RouterLink } from 'vue-router';
import EmptyState from '../ui/EmptyState.vue';

/**
 * A ranked list where every row shows two thin bars, one per measure (e.g. a
 * PACE's fail rate and retake rate), each with its own colour and value. Bars
 * are scaled against `max` (100 for percentages). An item with a `to` route
 * target renders its label as a link.
 */
const props = defineProps({
    items: { type: Array, required: true }, // [{id, label, sublabel?, first: number, second: number, to?}]
    /** Legend labels and colours for the two bars. */
    firstLabel: { type: String, default: 'First' },
    firstColor: { type: String, default: 'var(--danger)' },
    secondLabel: { type: String, default: 'Second' },
    secondColor: { type: String, default: '#d67014' },
    /** Value that fills a bar completely (100 for percentages); 0 scales to the largest value. */
    max: { type: Number, default: 100 },
    /** Suffix shown after each value (e.g. "%"). */
    unit: { type: String, default: '' },
    emptyMessage: { type: String, default: 'Nothing to show yet.' },
    /** When true, every item is shown in a scrolling list capped at `maxHeight`. */
    scrollable: { type: Boolean, default: false },
    maxHeight: { type: Number, default: 320 },
});

const scaleMax = computed(() => (
    props.max > 0 ? props.max : Math.max(1, ...props.items.flatMap((item) => [Number(item.first) || 0, Number(item.second) || 0]))
));

function width(value) {
    return `${Math.min(100, Math.max(0, (Number(value) || 0) / scaleMax.value * 100))}%`;
}

function format(value) {
    return `${Number(value) || 0}${props.unit}`;
}
</script>

<template>
    <div class="dual-bar-list">
        <EmptyState v-if="items.length === 0" compact :message="emptyMessage" />

        <template v-else>
            <div class="rows" :class="{ scrollable }" :style="scrollable ? { maxHeight: `${maxHeight}px` } : null">
                <div v-for="item in items" :key="item.id ?? item.label" class="row">
                    <div class="row-label">
                        <component :is="item.to ? RouterLink : 'span'" :to="item.to ?? undefined" class="row-name">
                            {{ item.label }}
                        </component>
                        <span v-if="item.sublabel" class="muted row-sublabel">{{ item.sublabel }}</span>
                    </div>
                    <div class="row-bars">
                        <div class="bar" :title="`${firstLabel}: ${format(item.first)}`">
                            <span class="track"><span class="fill" :style="{ width: width(item.first), background: firstColor }" /></span>
                            <span class="value mono" :style="{ color: firstColor }">{{ format(item.first) }}</span>
                        </div>
                        <div class="bar" :title="`${secondLabel}: ${format(item.second)}`">
                            <span class="track"><span class="fill" :style="{ width: width(item.second), background: secondColor }" /></span>
                            <span class="value mono" :style="{ color: secondColor }">{{ format(item.second) }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="legend">
                <span class="legend-item"><span class="swatch" :style="{ background: firstColor }" />{{ firstLabel }}</span>
                <span class="legend-item"><span class="swatch" :style="{ background: secondColor }" />{{ secondLabel }}</span>
            </div>
        </template>
    </div>
</template>

<style scoped>
.dual-bar-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.rows {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.rows.scrollable {
    overflow-y: auto;
    padding-right: 6px;
}

.row {
    display: grid;
    grid-template-columns: minmax(110px, 200px) 1fr;
    align-items: center;
    gap: 10px;
}

.row-label {
    display: flex;
    flex-direction: column;
    min-width: 0;
    font-size: 0.92rem;
}

.row-name {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.row-sublabel {
    font-size: 0.78rem;
    white-space: normal;
}

.row-bars {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.bar {
    display: grid;
    grid-template-columns: 1fr 52px;
    align-items: center;
    gap: 8px;
}

.track {
    display: block;
    height: 8px;
    border-radius: 999px;
    background: var(--bg);
    overflow: hidden;
}

.fill {
    display: block;
    height: 100%;
    border-radius: 999px;
    transition: width 0.3s ease;
}

.value {
    font-size: 0.82rem;
    text-align: right;
}

.legend {
    display: flex;
    justify-content: center;
    gap: 16px;
    margin-top: 2px;
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
}
</style>
