<script setup>
import { computed } from 'vue';
import { LEGAL_DOCUMENTS, LEGAL_IS_TEMPORARY, LEGAL_UPDATED } from '../../core/legal';
import AppModal from '../ui/AppModal.vue';

/**
 * The Terms of Service / Privacy Policy in a dialog (no page of their own), so
 * the person keeps whatever they were doing. Opens on one document and offers
 * a link to the other.
 *
 *  v-model   modelValue  Boolean   open state
 *  props     document    String    'terms' | 'privacy' (v-model:document switches between them)
 *  emits     update:modelValue(Boolean), update:document(String)
 */
const props = defineProps({
    modelValue: { type: Boolean, default: false },
    document: { type: String, default: 'terms' },
});

const emit = defineEmits(['update:modelValue', 'update:document']);

const current = computed(() => LEGAL_DOCUMENTS[props.document] ?? LEGAL_DOCUMENTS.terms);

/** The document offered as the other one. */
const otherKey = computed(() => (props.document === 'privacy' ? 'terms' : 'privacy'));
const other = computed(() => LEGAL_DOCUMENTS[otherKey.value]);
</script>

<template>
    <AppModal
        :model-value="modelValue"
        :title="current.title"
        width="640px"
        @update:model-value="emit('update:modelValue', $event)"
    >
        <div class="legal">
            <p class="muted updated">Last updated {{ LEGAL_UPDATED }}</p>
            <p v-if="LEGAL_IS_TEMPORARY" class="temporary" role="note">
                Temporary text: the final {{ current.title }} will replace this.
            </p>

            <section v-for="section in current.sections" :key="section.heading" class="section">
                <h3>{{ section.heading }}</h3>
                <p v-for="(paragraph, index) in section.paragraphs" :key="index">{{ paragraph }}</p>
            </section>

            <div class="actions">
                <button type="button" class="btn btn-link btn-sm" @click="emit('update:document', otherKey)">
                    Read the {{ other.title }}
                </button>
                <button type="button" class="btn btn-secondary" @click="emit('update:modelValue', false)">Close</button>
            </div>
        </div>
    </AppModal>
</template>

<style scoped>
.legal {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.updated {
    margin: 0;
    font-size: 0.85rem;
}

.temporary {
    margin: 4px 0 8px;
    padding: 8px 10px;
    border: 1px dashed var(--border);
    border-radius: var(--radius);
    color: var(--muted);
    font-size: 0.88rem;
}

.section h3 {
    margin: 12px 0 4px;
    font-size: 0.98rem;
}

.section p {
    margin: 0;
    line-height: 1.6;
}

.actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-top: 18px;
    padding-top: 14px;
    border-top: 1px solid var(--border);
}
</style>
