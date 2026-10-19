<script setup>
import AppModal from './AppModal.vue';

defineProps({
    modelValue: { type: Boolean, default: false },
    title: { type: String, default: 'Please confirm' },
    message: { type: String, default: 'Are you sure?' },
    confirmText: { type: String, default: 'Delete' },
    cancelText: { type: String, default: 'Cancel' },
    danger: { type: Boolean, default: true },
    busy: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'confirm', 'cancel']);

function cancel() {
    emit('update:modelValue', false);
    emit('cancel');
}

function confirm() {
    emit('confirm');
}
</script>

<template>
    <AppModal
        :model-value="modelValue"
        :title="title"
        width="420px"
        :closable="!busy"
        :close-on-backdrop="!busy"
        @update:model-value="cancel"
    >
        <p class="message">
            <slot>{{ message }}</slot>
        </p>

        <template #footer>
            <button type="button" class="btn btn-secondary" :disabled="busy" @click="cancel">
                {{ cancelText }}
            </button>
            <button
                type="button"
                class="btn"
                :class="{ 'btn-danger': danger }"
                :disabled="busy"
                @click="confirm"
            >
                {{ busy ? 'Please wait…' : confirmText }}
            </button>
        </template>
    </AppModal>
</template>

<style scoped>
.message {
    margin: 0;
    color: var(--text);
}
</style>
