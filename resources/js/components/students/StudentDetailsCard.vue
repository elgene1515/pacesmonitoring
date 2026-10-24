<script setup>
import { computed } from 'vue';
import CollapsibleCard from '../ui/CollapsibleCard.vue';

/**
 * The student's contact, emergency-contact and health details on the student
 * pages (admin and teacher), as three columns. Phone numbers and emails are
 * links, so staff can call or write from the page. The card folds away; an
 * allergy / condition on record stays flagged in its heading while folded.
 *
 *  props   student   Student
 */
const props = defineProps({
    student: { type: Object, required: true },
});

const tel = (value) => `tel:${String(value).replace(/[^0-9+]/g, '')}`;

const hasHealth = computed(() => Boolean(props.student.bloodType || props.student.medicalNotes));
</script>

<template>
    <CollapsibleCard class="details-card" title="Contact, emergency and health" storage-key="student-details">
        <template #summary>
            <span v-if="student.medicalNotes" class="flag">Medical note on record</span>
            <span v-if="student.guardianPhone" class="muted nowrap">Emergency: {{ student.guardianName || 'contact' }} · <span class="mono">{{ student.guardianPhone }}</span></span>
        </template>
        <div class="columns">
            <dl class="info">
                <h3 class="column-title">Contact</h3>
                <div class="info-item">
                    <dt>Email</dt>
                    <dd>
                        <a v-if="student.email" :href="`mailto:${student.email}`">{{ student.email }}</a>
                        <span v-else class="muted">—</span>
                    </dd>
                </div>
                <div class="info-item">
                    <dt>Mobile number</dt>
                    <dd>
                        <a v-if="student.mobile" :href="tel(student.mobile)" class="mono">{{ student.mobile }}</a>
                        <span v-else class="muted">—</span>
                    </dd>
                </div>
                <div class="info-item">
                    <dt>Address</dt>
                    <dd class="text">{{ student.address || '—' }}</dd>
                </div>
            </dl>

            <dl class="info">
                <h3 class="column-title">Emergency contact</h3>
                <div class="info-item">
                    <dt>Name</dt>
                    <dd>
                        {{ student.guardianName || '—' }}
                        <span v-if="student.guardianRelationship" class="muted">({{ student.guardianRelationship }})</span>
                    </dd>
                </div>
                <div class="info-item">
                    <dt>Phone number</dt>
                    <dd>
                        <a v-if="student.guardianPhone" :href="tel(student.guardianPhone)" class="mono">{{ student.guardianPhone }}</a>
                        <span v-else class="muted">—</span>
                    </dd>
                </div>
                <div class="info-item">
                    <dt>Email</dt>
                    <dd>
                        <a v-if="student.guardianEmail" :href="`mailto:${student.guardianEmail}`">{{ student.guardianEmail }}</a>
                        <span v-else class="muted">—</span>
                    </dd>
                </div>
            </dl>

            <dl class="info">
                <h3 class="column-title">Medical and health</h3>
                <div class="info-item">
                    <dt>Blood type</dt>
                    <dd class="mono">{{ student.bloodType || '—' }}</dd>
                </div>
                <div class="info-item">
                    <dt>Known allergies or medical conditions</dt>
                    <dd v-if="student.medicalNotes" class="text notes">{{ student.medicalNotes }}</dd>
                    <dd v-else class="muted">{{ hasHealth ? '—' : 'None recorded.' }}</dd>
                </div>
            </dl>
        </div>
    </CollapsibleCard>
</template>

<style scoped>
.flag {
    padding: 1px 8px;
    border-radius: 999px;
    color: var(--danger);
    background: rgba(var(--danger-rgb), 0.1);
    font-weight: 500;
}

.columns {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 18px 32px;
}

.info {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin: 0;
    min-width: 0;
}

.column-title {
    margin: 0;
    font-size: 0.9rem;
    font-weight: 600;
}

.info-item dt {
    font-size: 0.85rem;
    color: var(--muted);
    margin-bottom: 2px;
}

.info-item dd {
    margin: 0;
    overflow-wrap: anywhere;
}

.text {
    white-space: pre-wrap;
}

/* Allergies and conditions stand out: staff must not miss them. */
.notes {
    padding: 8px 10px;
    border: 1px solid rgba(var(--danger-rgb), 0.35);
    border-radius: var(--radius);
    background: rgba(var(--danger-rgb), 0.06);
}
</style>
