<script setup>
import { computed, ref, watch } from 'vue';
import { useTeachingStore } from '../../stores/teaching';
import { notify } from '../../stores/notifications';
import AppModal from '../ui/AppModal.vue';
import AppField from '../ui/AppField.vue';

/**
 * A teacher asks the school admin for an open class slot: one subject of one
 * section that nobody teaches yet.
 *
 *  props   modelValue  Boolean   open state (v-model)
 *          section     Section   the section (with its grade level)
 *          subject     Subject   the subject
 *  emits   update:modelValue(Boolean)
 *          saved(TeachingAssignmentRequest)
 */
const props = defineProps({
    modelValue: { type: Boolean, default: false },
    section: { type: Object, default: null },
    subject: { type: Object, default: null },
});

const emit = defineEmits(['update:modelValue', 'saved']);

const teaching = useTeachingStore();
const uid = `teaching-request-${Math.random().toString(36).slice(2, 8)}`;

const message = ref('');
const errors = ref({});
const submitting = ref(false);

const label = computed(() => [props.section?.label, props.subject?.name].filter(Boolean).join(' · '));

watch(
    () => props.modelValue,
    (open) => {
        if (open) {
            message.value = '';
            errors.value = {};
        }
    },
);

function close() {
    if (submitting.value) {
        return;
    }

    emit('update:modelValue', false);
}

async function submit() {
    if (submitting.value || !props.section || !props.subject) {
        return;
    }

    submitting.value = true;
    errors.value = {};

    try {
        const saved = await teaching.request({
            sectionId: props.section.id,
            subjectId: props.subject.id,
            message: message.value,
        });

        notify.success(`Your request to teach ${label.value} was sent to your school admin.`);
        emit('saved', saved);
        emit('update:modelValue', false);
    } catch (err) {
        if (err?.isValidation && err.hasFieldErrors) {
            errors.value = err.errors;
            notify.error(err.first('section_id') ?? err.first('subject_id') ?? err.first('request') ?? err.message);
        } else {
            notify.error(err?.message ?? 'Unable to send the request.');
        }
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <AppModal
        :model-value="modelValue"
        title="Request a class"
        width="460px"
        :closable="!submitting"
        :close-on-backdrop="false"
        @update:model-value="close"
    >
        <form v-if="modelValue" class="request-form" novalidate @submit.prevent="submit">
            <p class="muted intro">
                Ask your school admin to put you in charge of <strong>{{ label }}</strong>
                <template v-if="section?.schoolYearName"> (SY {{ section.schoolYearName }})</template>.
                Once accepted, the section's students show up in your lists and you can assign PACEs for this subject.
            </p>

            <AppField label="Note to your admin" :for="`${uid}-message`" :error="errors.message" hint="Optional.">
                <input :id="`${uid}-message`" v-model="message" class="input" type="text" maxlength="500" :disabled="submitting" />
            </AppField>

            <div class="actions">
                <button type="button" class="btn btn-secondary" :disabled="submitting" @click="close">Cancel</button>
                <button type="submit" class="btn" :disabled="submitting">
                    {{ submitting ? 'Sending…' : 'Send request' }}
                </button>
            </div>
        </form>
    </AppModal>
</template>

<style scoped>
.request-form {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.intro {
    margin: 0;
    font-size: 0.92rem;
}

.actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 6px;
}
</style>
