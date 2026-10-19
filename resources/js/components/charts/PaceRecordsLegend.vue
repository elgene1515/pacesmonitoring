<script setup>
const LABELS = { assigned: 'Assigned', issued: 'Issued', tested: 'Tested' };

/**
 * Colour key for PaceRecordsBar. Pass the same `order` as the bars so the
 * legend reads left to right the way the segments do.
 */
defineProps({
    /** Any permutation of assigned / issued / tested. */
    order: {
        type: Array,
        default: () => ['assigned', 'issued', 'tested'],
        validator: (value) => value.every((key) => ['assigned', 'issued', 'tested'].includes(key)),
    },
});
</script>

<template>
    <div class="pace-records-legend muted">
        <span v-for="key in order" :key="key" class="legend-item">
            <span :class="['swatch', key]" />{{ LABELS[key] }}
        </span>
    </div>
</template>

<style scoped>
.pace-records-legend {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: 16px;
    font-size: 0.85rem;
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
}

.swatch.assigned {
    background: var(--status-assigned);
}

.swatch.issued {
    background: var(--status-issued);
}

.swatch.tested {
    background: var(--status-tested);
}
</style>
