<script setup>
import { ref } from 'vue';

/**
 * Password input with a show/hide toggle.
 *
 *  props   id             String   input id (for the <label for>)
 *          modelValue     String   v-model
 *          autocomplete   String
 *          disabled       Boolean
 *  emits   update:modelValue(String)
 */
defineProps({
    id: { type: String, required: true },
    modelValue: { type: String, default: '' },
    autocomplete: { type: String, default: 'current-password' },
    disabled: { type: Boolean, default: false },
});

defineEmits(['update:modelValue']);

const showPassword = ref(false);
</script>

<template>
    <div class="password-field">
        <input
            :id="id"
            :value="modelValue"
            class="input"
            :type="showPassword ? 'text' : 'password'"
            :autocomplete="autocomplete"
            :disabled="disabled"
            @input="$emit('update:modelValue', $event.target.value)"
        />
        <button
            type="button"
            class="toggle-password"
            :aria-label="showPassword ? 'Hide password' : 'Show password'"
            :aria-pressed="showPassword"
            tabindex="-1"
            :disabled="disabled"
            @click="showPassword = !showPassword"
        >
            <svg v-if="showPassword" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a21.3 21.3 0 0 1 5.06-6.06M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 8 11 8a21.3 21.3 0 0 1-3.22 4.44M14.12 14.12a3 3 0 1 1-4.24-4.24" />
                <line x1="1" y1="1" x2="23" y2="23" />
            </svg>
            <svg v-else viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z" />
                <circle cx="12" cy="12" r="3" />
            </svg>
        </button>
    </div>
</template>

<style scoped>
.password-field {
    position: relative;
    display: flex;
}

.password-field .input {
    width: 100%;
    padding-right: 38px;
}

.toggle-password {
    position: absolute;
    top: 50%;
    right: 8px;
    transform: translateY(-50%);
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    padding: 0;
    border: none;
    background: transparent;
    color: var(--muted);
    cursor: pointer;
}

.toggle-password:hover {
    color: var(--text);
}
</style>
