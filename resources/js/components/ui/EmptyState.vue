<script setup>
defineProps({
    message: { type: String, default: 'Nothing to show yet.' },
    compact: { type: Boolean, default: false },
});
</script>

<template>
    <div class="empty" :class="{ compact }">
        <p class="empty-message">
            <slot>{{ message }}</slot>
        </p>
        <div v-if="$slots.action" class="empty-action">
            <slot name="action" />
        </div>
    </div>
</template>

<style scoped>
.empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 40px 16px;
    text-align: center;
    color: var(--muted);
}

.empty.compact {
    padding: 16px;
}

.empty-message {
    margin: 0;
}
</style>
