<script setup>
import { onMounted, ref, watch } from 'vue';
import { adminService } from '../../../services';
import { useAuthStore } from '../../../stores/auth';
import { useScopeStore } from '../../../stores/scope';
import { notify } from '../../../stores/notifications';
import { pluralize } from '../../../core/format';
import { CHANGE_REQUEST_STATUS_LABELS } from '../../../models';
import ChangeRequestList from '../../students/ChangeRequestList.vue';
import ReasonDialog from '../../ui/ReasonDialog.vue';

/** Teachers' proposed corrections to students' details: apply them to the record or decline them. */
const STATUS_OPTIONS = [
    { value: 'pending', label: 'Pending' },
    { value: '', label: 'All proposals' },
    ...Object.entries(CHANGE_REQUEST_STATUS_LABELS)
        .filter(([value]) => value !== 'pending')
        .map(([value, label]) => ({ value, label })),
];

const auth = useAuthStore();
const scope = useScopeStore();

const requests = ref([]);
const loading = ref(true);
const loaded = ref(false);
const status = ref('pending');
const busyId = ref(null);
const declining = ref(null);

async function load() {
    loading.value = true;

    try {
        requests.value = await adminService.listStudentChangeRequests(status.value || null);
        loaded.value = true;
    } catch (err) {
        notify.error(err?.message ?? 'Failed to load the proposed changes.');
    } finally {
        loading.value = false;
    }
}

async function accept(request) {
    if (busyId.value !== null) {
        return;
    }

    busyId.value = request.id;

    try {
        await adminService.acceptStudentChangeRequest(request.id);
        notify.success(`${request.studentName}'s record was updated.`);
        await load();
        auth.refresh().catch(() => {});
    } catch (err) {
        notify.error(err?.isValidation ? (Object.values(err.errors ?? {})[0]?.[0] ?? err.message) : (err?.message ?? 'Unable to apply the changes.'));
    } finally {
        busyId.value = null;
    }
}

async function confirmDecline(reason) {
    const request = declining.value;

    if (!request || busyId.value !== null) {
        return;
    }

    busyId.value = request.id;

    try {
        await adminService.declineStudentChangeRequest(request.id, reason);
        notify.info(`${request.requesterName}'s proposal for ${request.studentName} was declined.`);
        declining.value = null;
        await load();
        auth.refresh().catch(() => {});
    } catch (err) {
        notify.error(err?.message ?? 'Unable to decline the proposal.');
    } finally {
        busyId.value = null;
    }
}

watch(() => scope.organizationId, load);

onMounted(load);
</script>

<template>
    <div class="admin-student-changes">
        <p class="back">
            <RouterLink :to="{ name: 'admin-students' }">&larr; Students</RouterLink>
        </p>

        <div class="page-header">
            <div>
                <h1>Proposed changes</h1>
                <p class="muted subtitle">
                    Teachers cannot edit a student's details themselves; they propose corrections here. Applying one
                    updates the student's record.
                </p>
            </div>
        </div>

        <div class="card">
            <div class="toolbar">
                <select v-model="status" class="input select" aria-label="Filter by status" @change="load">
                    <option v-for="option in STATUS_OPTIONS" :key="option.value" :value="option.value">{{ option.label }}</option>
                </select>
                <span class="muted count nowrap">{{ loading && !loaded ? 'Loading…' : pluralize(requests.length, 'proposal') }}</span>
            </div>

            <p v-if="loading && !loaded" class="muted state">Loading…</p>

            <ChangeRequestList
                v-else
                admin
                :requests="requests"
                :busy-id="busyId"
                :can-write="auth.canWrite"
                :empty-message="status === 'pending' ? 'No proposals waiting for an answer.' : 'No proposals yet.'"
                @accept="accept"
                @decline="declining = $event"
            />
        </div>

        <ReasonDialog
            :model-value="declining !== null"
            title="Decline proposal"
            :busy="busyId !== null"
            @update:model-value="declining = null"
            @confirm="confirmDecline"
        >
            Decline <strong v-if="declining">{{ declining.requesterName }}</strong>'s proposed changes for
            <strong v-if="declining">{{ declining.studentName }}</strong>? The record stays as it is.
        </ReasonDialog>
    </div>
</template>

<style scoped>
.admin-student-changes {
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
