<script setup>
import { onBeforeUnmount, watch } from 'vue';

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    title: { type: String, default: '' },
    width: { type: String, default: '520px' },
    closeOnBackdrop: { type: Boolean, default: true },
    closable: { type: Boolean, default: true },
});

const emit = defineEmits(['update:modelValue', 'close']);

function close() {
    if (!props.closable) {
        return;
    }

    emit('update:modelValue', false);
    emit('close');
}

function onBackdrop() {
    if (props.closeOnBackdrop) {
        close();
    }
}

function onKeydown(event) {
    if (event.key === 'Escape') {
        event.stopPropagation();
        close();
    }
}

function lockScroll(locked) {
    if (typeof document === 'undefined') {
        return;
    }

    document.body.style.overflow = locked ? 'hidden' : '';
}

watch(
    () => props.modelValue,
    (open) => {
        if (open) {
            document.addEventListener('keydown', onKeydown);
            lockScroll(true);
        } else {
            document.removeEventListener('keydown', onKeydown);
            lockScroll(false);
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKeydown);
    lockScroll(false);
});
</script>

<template>
    <Teleport to="body">
        <Transition name="modal">
            <div v-if="modelValue" class="modal-backdrop" @mousedown.self="onBackdrop">
                <div
                    class="modal"
                    role="dialog"
                    aria-modal="true"
                    :aria-label="title || 'Dialog'"
                    :style="{ maxWidth: width }"
                >
                    <header v-if="title || closable" class="modal-header">
                        <h2 class="modal-title">{{ title }}</h2>
                        <button
                            v-if="closable"
                            type="button"
                            class="modal-close"
                            aria-label="Close"
                            @click="close"
                        >
                            &times;
                        </button>
                    </header>

                    <div class="modal-body">
                        <slot />
                    </div>

                    <footer v-if="$slots.footer" class="modal-footer">
                        <slot name="footer" />
                    </footer>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.modal-backdrop {
    position: fixed;
    inset: 0;
    z-index: 1000;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding: 48px 16px;
    overflow-y: auto;
    background: var(--backdrop);
}

.modal {
    width: 100%;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
}

.modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 14px 20px;
    border-bottom: 1px solid var(--border);
}

.modal-title {
    font-size: 1.1rem;
    font-weight: 600;
}

.modal-close {
    border: none;
    background: transparent;
    color: var(--muted);
    font-size: 1.5rem;
    line-height: 1;
    padding: 0 4px;
    cursor: pointer;
    border-radius: 4px;
}

.modal-close:hover {
    color: var(--text);
}

.modal-close:focus-visible {
    outline: 2px solid var(--primary);
}

.modal-body {
    padding: 20px;
}

.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    padding: 14px 20px;
    border-top: 1px solid var(--border);
}

@media (max-width: 640px) {
    .modal-backdrop {
        padding: 16px 12px;
    }

    .modal-header {
        padding: 12px 16px;
    }

    .modal-body {
        padding: 16px;
    }

    .modal-footer {
        flex-wrap: wrap;
        padding: 12px 16px;
    }
}

.modal-enter-active,
.modal-leave-active {
    transition: opacity 0.15s ease;
}

.modal-enter-from,
.modal-leave-to {
    opacity: 0;
}
</style>
