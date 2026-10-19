<script setup>
import { computed } from 'vue';

const props = defineProps({
    label: { type: String, default: '' },
    error: { type: [String, Array], default: '' },
    required: { type: Boolean, default: false },
    hint: { type: String, default: '' },
    for: { type: String, default: '' },
});

const errorMessage = computed(() => {
    if (Array.isArray(props.error)) {
        return props.error.length ? String(props.error[0]) : '';
    }

    return props.error ? String(props.error) : '';
});

const invalid = computed(() => errorMessage.value !== '');
</script>

<template>
    <div class="field" :class="{ 'field-invalid': invalid }">
        <label v-if="label" class="field-label" :for="props.for || undefined">
            {{ label }}
            <span v-if="required" class="field-required" aria-hidden="true">*</span>
        </label>

        <slot :invalid="invalid" :id="props.for" />

        <small v-if="invalid" class="field-error" role="alert">{{ errorMessage }}</small>
        <small v-else-if="hint" class="field-hint">{{ hint }}</small>
    </div>
</template>

<style scoped>
.field {
    display: flex;
    flex-direction: column;
    gap: 4px;
    min-width: 0;
}

.field-label {
    font-size: 0.92rem;
    font-weight: 500;
    color: var(--text);
}

.field-required {
    color: var(--danger);
    margin-left: 2px;
}

.field-error {
    color: var(--danger);
    font-size: 0.85rem;
}

.field-hint {
    color: var(--muted);
    font-size: 0.85rem;
}

.field-invalid :deep(.input) {
    border-color: var(--danger);
}

.field-invalid :deep(.input:focus) {
    outline-color: var(--danger);
}
</style>
