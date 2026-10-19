<script setup>
import { useNotificationsStore } from '../../stores/notifications';

const notifications = useNotificationsStore();
</script>

<template>
    <div class="toasts" aria-live="polite" aria-atomic="false">
        <TransitionGroup name="toast">
            <div
                v-for="toast in notifications.toasts"
                :key="toast.id"
                class="toast"
                :class="`toast-${toast.type}`"
                role="status"
            >
                <span class="toast-message">{{ toast.message }}</span>
                <button
                    type="button"
                    class="toast-close"
                    aria-label="Dismiss"
                    @click="notifications.remove(toast.id)"
                >
                    &times;
                </button>
            </div>
        </TransitionGroup>
    </div>
</template>

<style scoped>
.toasts {
    position: fixed;
    right: 16px;
    bottom: 16px;
    z-index: 1100;
    display: flex;
    flex-direction: column;
    gap: 8px;
    width: min(360px, calc(100vw - 32px));
    pointer-events: none;
}

.toast {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    padding: 10px 12px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-left: 3px solid var(--muted);
    border-radius: var(--radius);
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
    pointer-events: auto;
}

.toast-success {
    border-left-color: var(--success);
}

.toast-error {
    border-left-color: var(--danger);
}

.toast-info {
    border-left-color: var(--primary);
}

.toast-message {
    flex: 1;
    word-break: break-word;
}

.toast-close {
    border: none;
    background: transparent;
    color: var(--muted);
    font-size: 1.2rem;
    line-height: 1;
    padding: 0 2px;
    cursor: pointer;
}

.toast-close:hover {
    color: var(--text);
}

.toast-enter-active,
.toast-leave-active {
    transition: opacity 0.15s ease, transform 0.15s ease;
}

.toast-enter-from,
.toast-leave-to {
    opacity: 0;
    transform: translateY(6px);
}
</style>
