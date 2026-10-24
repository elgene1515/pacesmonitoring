<script setup>
import { BLOOD_TYPES, GUARDIAN_RELATIONSHIPS } from '../../models';
import AppField from '../ui/AppField.vue';

/**
 * The optional contact, emergency-contact and health fields of a student, as
 * three labelled groups. Used by the enrollment / edit form and by the
 * teacher's change proposal. The ID number is shown by the parent form.
 *
 *  v-model   details   {email, mobile, address, guardianName, guardianRelationship,
 *                       guardianPhone, guardianEmail, bloodType, medicalNotes}
 *  props     errors    Object   field errors (snake_case keys)
 *            disabled  Boolean
 */
const details = defineModel({ type: Object, required: true });

defineProps({
    errors: { type: Object, default: () => ({}) },
    disabled: { type: Boolean, default: false },
});

const uid = `student-details-${Math.random().toString(36).slice(2, 8)}`;
</script>

<template>
    <div class="details">
        <fieldset class="group">
            <legend class="group-title">Contact</legend>
            <div class="grid">
                <AppField label="Student email" :for="`${uid}-email`" :error="errors.email">
                    <input :id="`${uid}-email`" v-model="details.email" class="input" type="email" autocomplete="off" maxlength="255" :disabled="disabled" />
                </AppField>
                <AppField label="Student mobile number" :for="`${uid}-mobile`" :error="errors.mobile">
                    <input :id="`${uid}-mobile`" v-model="details.mobile" class="input" type="tel" inputmode="tel" autocomplete="off" maxlength="30" placeholder="e.g. 0917 123 4567" :disabled="disabled" />
                </AppField>
                <AppField label="Address" :for="`${uid}-address`" :error="errors.address" class="wide">
                    <input :id="`${uid}-address`" v-model="details.address" class="input" type="text" autocomplete="off" maxlength="500" :disabled="disabled" />
                </AppField>
            </div>
        </fieldset>

        <fieldset class="group">
            <legend class="group-title">Emergency contact</legend>
            <p class="muted group-hint">Parent or guardian the school calls in an emergency.</p>
            <div class="grid">
                <AppField label="Name" :for="`${uid}-guardian`" :error="errors.guardian_name">
                    <input :id="`${uid}-guardian`" v-model="details.guardianName" class="input" type="text" autocomplete="off" maxlength="255" :disabled="disabled" />
                </AppField>
                <AppField label="Relationship to the student" :for="`${uid}-relationship`" :error="errors.guardian_relationship">
                    <input
                        :id="`${uid}-relationship`"
                        v-model="details.guardianRelationship"
                        class="input"
                        type="text"
                        autocomplete="off"
                        maxlength="50"
                        placeholder="e.g. Mother"
                        :list="`${uid}-relationships`"
                        :disabled="disabled"
                    />
                    <datalist :id="`${uid}-relationships`">
                        <option v-for="relationship in GUARDIAN_RELATIONSHIPS" :key="relationship" :value="relationship" />
                    </datalist>
                </AppField>
                <AppField label="Phone number" :for="`${uid}-guardian-phone`" :error="errors.guardian_phone">
                    <input :id="`${uid}-guardian-phone`" v-model="details.guardianPhone" class="input" type="tel" inputmode="tel" autocomplete="off" maxlength="30" :disabled="disabled" />
                </AppField>
                <AppField label="Email" :for="`${uid}-guardian-email`" :error="errors.guardian_email">
                    <input :id="`${uid}-guardian-email`" v-model="details.guardianEmail" class="input" type="email" autocomplete="off" maxlength="255" :disabled="disabled" />
                </AppField>
            </div>
        </fieldset>

        <fieldset class="group">
            <legend class="group-title">Medical and health</legend>
            <div class="grid">
                <AppField label="Blood type" :for="`${uid}-blood`" :error="errors.blood_type">
                    <select :id="`${uid}-blood`" v-model="details.bloodType" class="input" :disabled="disabled">
                        <option value="">Not recorded</option>
                        <option v-for="type in BLOOD_TYPES" :key="type" :value="type">{{ type }}</option>
                    </select>
                </AppField>
                <AppField
                    label="Known allergies or medical conditions"
                    :for="`${uid}-medical`"
                    :error="errors.medical_notes"
                    hint="Remarks for teachers and staff, e.g. peanut allergy, asthma (uses an inhaler)."
                    class="wide"
                >
                    <textarea :id="`${uid}-medical`" v-model="details.medicalNotes" class="input textarea" rows="3" maxlength="2000" :disabled="disabled" />
                </AppField>
            </div>
        </fieldset>
    </div>
</template>

<style scoped>
.details {
    display: flex;
    flex-direction: column;
    gap: 18px;
}

.group {
    margin: 0;
    padding: 14px 0 0;
    border: none;
    border-top: 1px solid var(--border);
    min-width: 0;
}

.group-title {
    padding: 0;
    margin-bottom: 10px;
    font-size: 0.95rem;
    font-weight: 600;
}

.group-hint {
    margin: -4px 0 10px;
    font-size: 0.85rem;
}

.grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px 16px;
}

.wide {
    grid-column: 1 / -1;
}

.textarea {
    resize: vertical;
    min-height: 72px;
    font: inherit;
}

@media (max-width: 560px) {
    .grid {
        grid-template-columns: 1fr;
    }
}
</style>
