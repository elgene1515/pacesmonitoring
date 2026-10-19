<script setup>
import { computed } from 'vue';
import { RouterLink } from 'vue-router';
import EmptyState from '../ui/EmptyState.vue';

/**
 * A ranked list of horizontal bars, e.g. PACEs per subject or students per grade level.
 * An item with a `to` route target renders its label as a link.
 */
const props = defineProps({
    items: { type: Array, required: true }, // [{id, label, sublabel?, value, to?}]
    color: { type: String, default: 'var(--primary)' },
    emptyMessage: { type: String, default: 'Nothing to show yet.' },
    limit: { type: Number, default: 8 },
    /** When true, every item is shown in a scrolling list instead of being cut off at `limit`. */
    scrollable: { type: Boolean, default: false },
    /** Max height (px) of the scrolling list, only used when `scrollable` is true. */
    maxHeight: { type: Number, default: 260 },
});

const maxValue = computed(() => Math.max(1, ...props.items.map((item) => item.value)));
const visibleItems = computed(() => (props.scrollable ? props.items : props.items.slice(0, props.limit)));
const hiddenCount = computed(() => (props.scrollable ? 0 : Math.max(0, props.items.length - props.limit)));
</script>

<template>
    <div class="bar-list">
        <EmptyState v-if="items.length === 0" compact :message="emptyMessage" />

        <template v-else>
            <div class="bar-rows" :class="{ scrollable }" :style="scrollable ? { maxHeight: `${maxHeight}px` } : null">
                <div v-for="item in visibleItems" :key="item.id ?? item.label" class="bar-row">
                    <div class="bar-row-label">
                        <component :is="item.to ? RouterLink : 'span'" :to="item.to ?? undefined" class="bar-row-name">
                            {{ item.label }}
                        </component>
                        <span v-if="item.sublabel" class="muted bar-row-sublabel">{{ item.sublabel }}</span>
                    </div>
                    <div class="bar-row-track">
                        <div class="bar-row-fill" :style="{ width: `${(item.value / maxValue) * 100}%`, background: color }" />
                    </div>
                    <span class="bar-row-value mono">{{ item.value }}</span>
                </div>
            </div>

            <p v-if="hiddenCount > 0" class="muted bar-list-more">+{{ hiddenCount }} more not shown</p>
        </template>
    </div>
</template>

<style scoped>
.bar-list {
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

.bar-row-track {
    height: 10px;
    border-radius: 999px;
    background: var(--bg);
    overflow: hidden;
}

.bar-row-fill {
    height: 100%;
    border-radius: 999px;
    transition: width 0.3s ease;
}

.bar-row-value {
    text-align: right;
    font-size: 0.9rem;
}

.bar-list-more {
    margin: 4px 0 0;
    font-size: 0.85rem;
}
</style>
