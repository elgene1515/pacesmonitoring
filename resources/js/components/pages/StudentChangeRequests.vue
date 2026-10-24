<script setup>
import { computed, onMounted, ref } from 'vue';
import { useAuthStore } from '../../stores/auth';
import { useStudentChangeRequestsStore } from '../../stores/studentChangeRequests';
import { notify } from '../../stores/notifications';
import { pluralize } from '../../core/format';
import { CHANGE_REQUEST_STATUS_LABELS } from '../../models';
import ChangeRequestList from '../students/ChangeRequestList.vue';
import ConfirmDialog from '../ui/ConfirmDialog.vue';

/** The teacher's proposed edits to student details, kept as history. Pending ones can be withdrawn. */
const STATUS_OPTIONS = [
    { value: '', label: 'All proposals' },
    ...Object.entries(CHANGE_REQUEST_STATUS_LABELS).map(([value, label]) => ({ value, label })),
];

const auth = useAuthStore();
const changes = useStudentChangeRequestsStore();

const statusFilter = ref('');
const withdrawing = ref(null);
const busy = ref(false);

const visible = computed(() => (statusFilter.value
    ? changes.items.filter((request) => request.status === statusFilter.value)
    : changes.items));

const emptyMessage = computed(() => (statusFilter.value
    ? 'No proposals with that status.'
    : 'You have not proposed any change yet. Open a student and choose “Propose changes” to correct their details.'));

async function confirmWithdraw() {
    const target = withdrawing.value;

    if (!target || busy.value) {
        return;
    }

    busy.value = true;

    try {
        await changes.cancel(target.id);
        notify.info(`Your proposal for ${target.studentName} was withdrawn.`);
        withdrawing.value = null;
    } catch (err) {
        notify.error(err?.isValidation ? (err.first('request') ?? err.message) : (err?.message ?? 'Unable to withdraw the proposal.'));
    } finally {
        busy.value = false;
    }
}

onMounted(() => {
    changes.fetch();
});
</script>

<template>
    <div class="student-changes">
        <p class="back">
            <RouterLink :to="{ name: 'students' }">&larr; Students</RouterLink>
        </p>

        <div class="page-header">
            <div>
                <h1>Proposed changes</h1>
                <p class="muted subtitle">
                    Corrections you asked for on students' details. Your school admin applies or declines them; a
                    student's record only changes once a proposal is applied.
                </p>
            </div>
        </div>

        <div class="card">
            <div class="toolbar">
                <select v-model="statusFilter" class="input select" aria-label="Filter by status">
                    <option v-for="option in STATUS_OPTIONS" :key="option.value" :value="option.value">{{ option.label }}</option>
                </select>
                <span class="muted count nowrap">
                    {{ changes.loading && !changes.loaded ? 'Loading…' : pluralize(visible.length, 'proposal') }}
                    <template v-if="changes.pendingCount > 0"> · {{ changes.pendingCount }} pending</template>
                </span>
            </div>

            <p v-if="changes.loading && !changes.loaded" class="muted state">Loading…</p>

            <ChangeRequestList
                v-else
                :requests="visible"
                :busy-id="busy ? withdrawing?.id ?? null : null"
                :can-write="auth.canWrite"
                :empty-message="emptyMessage"
                @withdraw="withdrawing = $event"
            />
        </div>

        <ConfirmDialog
            :model-value="withdrawing !== null"
            title="Withdraw proposal"
            confirm-text="Withdraw"
            :busy="busy"
            @update:model-value="withdrawing = null"
            @confirm="confirmWithdraw"
        >
            Withdraw your proposed changes for <strong v-if="withdrawing">{{ withdrawing.studentName }}</strong>?
        </ConfirmDialog>
    </div>
</template>

<style scoped>
.student-changes {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.back {
    margin: 0;
    font-size: 0.92rem;
}

.page-header {
    margin-bottom: 0;
}

.subtitle {
    margin: 4px 0 0;
    max-width: 640px;
}

.card {
    padding: 0;
    overflow: hidden;
}

.toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px 16px;
    flex-wrap: wrap;
    padding: 12px 16px;
    border-bottom: 1px solid var(--border);
}

.toolbar .input {
    width: auto;
}

.select {
    min-width: 160px;
}

.count {
    font-size: 0.92rem;
}

.state {
    margin: 0;
    padding: 24px 16px;
    text-align: center;
}
</style>
