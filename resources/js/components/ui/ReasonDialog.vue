<script setup>
import { ref, watch } from 'vue';
import AppModal from './AppModal.vue';
import AppField from './AppField.vue';

/**
 * Confirm an action with an optional free-text reason (declining a request).
 *
 *  props   modelValue   Boolean
 *          title        String
 *          label        String   label of the reason field
 *          confirmText  String
 *          busy         Boolean
 *  slots   default      the question shown above the field
 *  emits   update:modelValue(Boolean)
 *          confirm(reason: string)
 */
const props = defineProps({
    modelValue: { type: Boolean, default: false },
    title: { type: String, default: 'Decline' },
    label: { type: String, default: 'Reason' },
    confirmText: { type: String, default: 'Decline' },
    busy: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'confirm']);

const uid = `reason-${Math.random().toString(36).slice(2, 8)}`;
const reason = ref('');

watch(
    () => props.modelValue,
    (open) => {
        if (open) {
            reason.value = '';
        }
    },
);

function close() {
    if (!props.busy) {
        emit('update:modelValue', false);
    }
}

function confirm() {
    if (!props.busy) {
        emit('confirm', reason.value.trim());
    }
}
</script>

<template>
    <AppModal :model-value="modelValue" :title="title" width="440px" :closable="!busy" :close-on-backdrop="false" @update:model-value="close">
        <form class="reason-form" novalidate @submit.prevent="confirm">
            <p class="message"><slot /></p>
            <AppField :label="label" :for="`${uid}-reason`" hint="Optional. The teacher sees it.">
                <input :id="`${uid}-reason`" v-model="reason" class="input" type="text" maxlength="255" :disabled="busy" />
            </AppField>
            <div class="actions">
                <button type="button" class="btn btn-secondary" :disabled="busy" @click="close">Cancel</button>
                <button type="submit" class="btn btn-danger" :disabled="busy">{{ busy ? 'Please wait…' : confirmText }}</button>
            </div>
        </form>
    </AppModal>
</template>

<style scoped>
.reason-form {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.message {
    margin: 0;
}

.actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
}
</style>
