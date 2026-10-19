<script setup>
import { computed } from 'vue';

/**
 * Small pill for subscription / request / role states.
 *
 *  props   tone   'ok' | 'warn' | 'bad' | 'info' | 'muted'
 */
const props = defineProps({
    tone: { type: String, default: 'muted' },
});

const toneClass = computed(() => ['ok', 'warn', 'bad', 'info', 'muted'].includes(props.tone) ? props.tone : 'muted');
</script>

<template>
    <span class="status-badge" :class="toneClass"><slot /></span>
</template>

<style scoped>
.status-badge {
    display: inline-block;
    padding: 1px 8px;
    border-radius: 999px;
    border: 1px solid var(--border);
    font-size: 0.8rem;
    line-height: 1.5;
    color: var(--muted);
    white-space: nowrap;
    vertical-align: middle;
}

.status-badge.ok {
    color: var(--success);
    border-color: rgba(var(--success-rgb), 0.35);
    background: rgba(var(--success-rgb), 0.08);
}

.status-badge.warn {
    color: var(--warn-text);
    border-color: rgba(var(--warn-rgb), 0.4);
    background: rgba(var(--warn-rgb), 0.1);
}

.status-badge.bad {
    color: var(--danger);
    border-color: rgba(var(--danger-rgb), 0.35);
    background: rgba(var(--danger-rgb), 0.08);
}

.status-badge.info {
    color: var(--primary);
    border-color: rgba(var(--primary-rgb), 0.35);
    background: rgba(var(--primary-rgb), 0.08);
}
</style>
