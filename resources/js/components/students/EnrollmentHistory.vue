<script setup>
import { computed } from 'vue';
import { formatDate, pluralize } from '../../core/format';
import CollapsibleCard from '../ui/CollapsibleCard.vue';

/**
 * Where a student has been enrolled over time: one row per stay in a
 * section (school year, grade level, section, from–to), latest first. The
 * card starts folded; its heading names the current class and the count.
 *
 *  props   enrollments  StudentEnrollment[]
 *          showBranch   Boolean  add the branch column (schools with several branches)
 */
const props = defineProps({
    enrollments: { type: Array, default: () => [] },
    showBranch: { type: Boolean, default: false },
});

const current = computed(() => props.enrollments.find((stay) => stay.isCurrent) ?? null);
</script>

<template>
    <CollapsibleCard title="Enrollment history" :default-open="false" storage-key="enrollment-history">
        <template #summary>
            <span class="muted">
                {{ pluralize(enrollments.length, 'section') }}<template v-if="current"> · now {{ current.classLabel }}<template v-if="current.schoolYearName"> (SY {{ current.schoolYearName }})</template></template>
            </span>
        </template>
        <p class="muted card-hint">Every section the student has been in. PACE records stay filed under the school year and subject they were made in.</p>

        <p v-if="enrollments.length === 0" class="muted state">No enrollment recorded yet.</p>

        <div v-else class="table-wrap">
            <table class="table history">
                <thead>
                    <tr>
                        <th>School year</th>
                        <th>Grade level</th>
                        <th>Section</th>
                        <th v-if="showBranch">Branch</th>
                        <th>From</th>
                        <th>To</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="stay in enrollments" :key="stay.id" :class="{ current: stay.isCurrent }">
                        <td class="mono">{{ stay.schoolYearName || '—' }}</td>
                        <td>{{ stay.gradeLevelName || '—' }}</td>
                        <td>{{ stay.sectionName || '—' }}</td>
                        <td v-if="showBranch">{{ stay.branchName || '—' }}</td>
                        <td class="nowrap">{{ formatDate(stay.startedOn) || '—' }}</td>
                        <td class="nowrap">
                            <span v-if="stay.isCurrent" class="text-success">Current</span>
                            <template v-else>{{ formatDate(stay.endedOn) || '—' }}</template>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </CollapsibleCard>
</template>

<style scoped>
.card-hint {
    margin: 0 0 12px;
    font-size: 0.85rem;
}

.state {
    margin: 0;
}

.history .current td {
    font-weight: 500;
}
</style>
