<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { SEX_OPTIONS, STUDENT_DETAIL_FIELDS, blankStudentDetails, studentDetailsOf } from '../../models';
import { useStudentChangeRequestsStore } from '../../stores/studentChangeRequests';
import { notify } from '../../stores/notifications';
import { todayIso } from '../../core/format';
import AppModal from '../ui/AppModal.vue';
import AppField from '../ui/AppField.vue';
import StudentDetailsFields from './StudentDetailsFields.vue';

/**
 * A teacher proposes changes to a student's details. Only the fields that
 * differ from the current record are sent; the school admin applies or
 * declines the proposal.
 *
 *  props   modelValue  Boolean   open state (v-model)
 *          student     Student   the student (current values prefill the form)
 *  emits   update:modelValue(Boolean)
 *          saved(StudentChangeRequest)
 */
const props = defineProps({
    modelValue: { type: Boolean, default: false },
    student: { type: Object, default: null },
});

const emit = defineEmits(['update:modelValue', 'saved']);

const changeRequests = useStudentChangeRequestsStore();
const uid = `student-change-${Math.random().toString(36).slice(2, 8)}`;
const today = todayIso();

const form = reactive({ firstName: '', middleName: '', lastName: '', extension: '', birthDate: '', sex: '', message: '' });
/** The optional ID number, contact, emergency and health details. */
const details = reactive(blankStudentDetails());
const clientErrors = ref({});
const serverErrors = ref({});
const submitting = ref(false);

const fieldErrors = computed(() => ({ ...clientErrors.value, ...serverErrors.value }));

function hydrate() {
    clientErrors.value = {};
    serverErrors.value = {};
    form.firstName = props.student?.firstName ?? '';
    form.middleName = props.student?.middleName ?? '';
    form.lastName = props.student?.lastName ?? '';
    form.extension = props.student?.extension ?? '';
    form.birthDate = props.student?.birthDate ?? '';
    form.sex = props.student?.sex ?? '';
    form.message = '';
    Object.assign(details, studentDetailsOf(props.student));
}

watch(
    () => props.modelValue,
    (open) => {
        if (open) {
            hydrate();
        }
    },
    { immediate: true },
);

const clean = (value) => {
    const text = value === null || value === undefined ? '' : String(value).trim();
    return text === '' ? null : text;
};

/** The fields whose new value differs from the student's current one (snake_case). */
const changes = computed(() => {
    const student = props.student;

    if (!student) {
        return {};
    }

    const found = {};
    const compare = (key, current, next) => {
        if (clean(current) !== clean(next)) {
            found[key] = next;
        }
    };

    compare('first_name', student.firstName, String(form.firstName).trim());
    compare('middle_name', student.middleName, clean(form.middleName));
    compare('last_name', student.lastName, String(form.lastName).trim());
    compare('extension', student.extension, clean(form.extension));
    compare('birth_date', student.birthDate, form.birthDate || null);
    compare('sex', student.sex, form.sex || null);

    for (const field of STUDENT_DETAIL_FIELDS) {
        compare(field.key, student[field.prop], clean(details[field.prop]));
    }

    return found;
});

const hasChanges = computed(() => Object.keys(changes.value).length > 0);

function close() {
    if (submitting.value) {
        return;
    }

    emit('update:modelValue', false);
}

function validate() {
    const errors = {};

    if (!String(form.firstName).trim()) {
        errors.first_name = ['First name is required.'];
    }

    if (!String(form.lastName).trim()) {
        errors.last_name = ['Last name is required.'];
    }

    if (!form.birthDate) {
        errors.birth_date = ['Date of birth is required.'];
    } else if (form.birthDate > today) {
        errors.birth_date = ['Date of birth cannot be in the future.'];
    }

    if (!form.sex) {
        errors.sex = ['Please select a sex.'];
    }

    if (Object.keys(errors).length === 0 && !hasChanges.value) {
        errors.changes = ['Change at least one detail first.'];
    }

    clientErrors.value = errors;

    return Object.keys(errors).length === 0;
}

async function submit() {
    if (submitting.value || !validate()) {
        return;
    }

    submitting.value = true;
    serverErrors.value = {};

    try {
        const saved = await changeRequests.propose(props.student.id, changes.value, form.message);

        notify.success(`Your proposed changes for ${props.student.displayName} were sent to your school admin.`);
        emit('saved', saved);
        emit('update:modelValue', false);
    } catch (err) {
        if (err?.isValidation && err.hasFieldErrors) {
            serverErrors.value = err.errors;

            if (err.errors.request) {
                notify.error(err.first('request'));
            }
        } else {
            notify.error(err?.message ?? 'Unable to send the proposal.');
        }
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <AppModal
        :model-value="modelValue"
        title="Propose changes"
        width="620px"
        :closable="!submitting"
        :close-on-backdrop="false"
        @update:model-value="close"
    >
        <form v-if="modelValue && student" class="change-form" novalidate @submit.prevent="submit">
            <p class="muted intro">
                Edit the details of <strong>{{ student.displayName }}</strong> below. Only what you change is sent to
                your school admin, who applies or declines it. The record stays as it is until then.
            </p>

            <p v-if="fieldErrors.changes" class="text-danger form-error" role="alert">
                {{ Array.isArray(fieldErrors.changes) ? fieldErrors.changes[0] : fieldErrors.changes }}
            </p>

            <div class="grid">
                <AppField label="First name" :for="`${uid}-first`" :error="fieldErrors.first_name" required>
                    <input :id="`${uid}-first`" v-model="form.firstName" class="input" type="text" autocomplete="off" :disabled="submitting" />
                </AppField>

                <AppField label="Middle name" :for="`${uid}-middle`" :error="fieldErrors.middle_name">
                    <input :id="`${uid}-middle`" v-model="form.middleName" class="input" type="text" autocomplete="off" :disabled="submitting" />
                </AppField>

                <AppField label="Last name" :for="`${uid}-last`" :error="fieldErrors.last_name" required>
                    <input :id="`${uid}-last`" v-model="form.lastName" class="input" type="text" autocomplete="off" :disabled="submitting" />
                </AppField>

                <AppField label="Extension" :for="`${uid}-ext`" :error="fieldErrors.extension" hint="e.g. Jr., III">
                    <input :id="`${uid}-ext`" v-model="form.extension" class="input" type="text" autocomplete="off" :disabled="submitting" />
                </AppField>

                <AppField label="Date of birth" :for="`${uid}-birth`" :error="fieldErrors.birth_date" required>
                    <input :id="`${uid}-birth`" v-model="form.birthDate" class="input" type="date" :max="today" :disabled="submitting" />
                </AppField>

                <AppField label="Sex" :error="fieldErrors.sex" required>
                    <div class="radios" role="radiogroup">
                        <label v-for="option in SEX_OPTIONS" :key="option.value" class="checkbox">
                            <input v-model="form.sex" type="radio" :name="`${uid}-sex`" :value="option.value" :disabled="submitting" />
                            {{ option.label }}
                        </label>
                    </div>
                </AppField>
            </div>

            <AppField label="Student ID number" :for="`${uid}-number`" :error="fieldErrors.student_number">
                <input :id="`${uid}-number`" v-model="details.studentNumber" class="input mono number-input" type="text" autocomplete="off" maxlength="50" :disabled="submitting" />
            </AppField>

            <StudentDetailsFields v-model="details" :errors="fieldErrors" :disabled="submitting" />

            <AppField label="Note to your admin" :for="`${uid}-message`" :error="fieldErrors.message" hint="Optional: why the record should change.">
                <input :id="`${uid}-message`" v-model="form.message" class="input" type="text" maxlength="500" :disabled="submitting" />
            </AppField>

            <div class="actions">
                <button type="button" class="btn btn-secondary" :disabled="submitting" @click="close">Cancel</button>
                <button type="submit" class="btn" :disabled="submitting">
                    {{ submitting ? 'Sending…' : 'Send proposal' }}
                </button>
            </div>
        </form>
    </AppModal>
</template>

<style scoped>
.change-form {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.intro {
    margin: 0;
    font-size: 0.92rem;
}

.form-error {
    margin: 0;
    font-size: 0.9rem;
}

.grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px 16px;
}

.number-input {
    max-width: 260px;
}

.radios {
    display: flex;
    align-items: center;
    gap: 18px;
    min-height: 36px;
}

.actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
}

@media (max-width: 560px) {
    .grid {
        grid-template-columns: 1fr;
    }
}
</style>
