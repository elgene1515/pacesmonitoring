<script setup>
import EmptyState from '../ui/EmptyState.vue';

/**
 * A scrollable list of clickable records, e.g. failed PACEs or top-performing students.
 * Each row links to a route (usually a student's detail page) and shows a primary
 * line, an optional secondary (muted) line, a highlighted value, and an optional caption.
 */
defineProps({
    items: { type: Array, required: true }, // [{id, to, primary, secondary?, value, valueClass?, caption?}]
    emptyMessage: { type: String, default: 'Nothing to show yet.' },
    maxHeight: { type: Number, default: 260 },
});
</script>

<template>
    <div class="record-list">
        <EmptyState v-if="items.length === 0" compact :message="emptyMessage" />

        <div v-else class="record-rows" :style="{ maxHeight: `${maxHeight}px` }">
            <RouterLink v-for="item in items" :key="item.id" :to="item.to" class="record-row">
                <div class="record-main">
                    <span class="record-primary">{{ item.primary }}</span>
                    <span v-if="item.secondary" class="muted record-secondary">{{ item.secondary }}</span>
                </div>
                <div class="record-side">
                    <span class="record-value mono" :class="item.valueClass">{{ item.value }}</span>
                    <span v-if="item.caption" class="muted record-caption">{{ item.caption }}</span>
                </div>
            </RouterLink>
        </div>
    </div>
</template>

<style scoped>
.record-rows {
    display: flex;
    flex-direction: column;
    gap: 2px;
    overflow-y: auto;
}

.record-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 8px 4px;
    border-radius: var(--radius);
    color: inherit;
}

.record-row:hover {
    background: var(--bg);
    text-decoration: none;
}

.record-main,
.record-side {
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.record-main {
    overflow: hidden;
}

.record-primary {
    font-weight: 500;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.record-secondary {
    font-size: 0.82rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.record-side {
    align-items: flex-end;
    flex: 0 0 auto;
    text-align: right;
}

.record-value {
    font-weight: 600;
}

.record-value.danger {
    color: var(--danger);
}

.record-value.success {
    color: var(--success);
}

.record-caption {
    font-size: 0.78rem;
    white-space: nowrap;
}
</style>
