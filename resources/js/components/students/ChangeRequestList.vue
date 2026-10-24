<script setup>
import { formatDate } from '../../core/format';
import EmptyState from '../ui/EmptyState.vue';
import StatusBadge from '../ui/StatusBadge.vue';
import AppIcon from '../ui/AppIcon.vue';

/**
 * Proposed student edits as a table: who, what changes, when, and the decision.
 * Shared by the teacher's own list and the admin's review queue.
 *
 *  props   requests      StudentChangeRequest[]
 *          admin         Boolean   show the requesting teacher and Apply / Decline actions
 *          busyId        Number|null  the request being decided (disables its buttons)
 *          emptyMessage  String
 *  emits   withdraw(StudentChangeRequest)   teacher: withdraw a pending proposal
 *          accept(StudentChangeRequest)     admin
 *          decline(StudentChangeRequest)    admin
 */
defineProps({
    requests: { type: Array, required: true },
    admin: { type: Boolean, default: false },
    busyId: { type: Number, default: null },
    canWrite: { type: Boolean, default: true },
    emptyMessage: { type: String, default: 'No proposed changes.' },
});

const emit = defineEmits(['withdraw', 'accept', 'decline']);

function decisionLine(request) {
    if (request.isPending) {
        return '';
    }

    const by = request.deciderName ? ` by ${request.deciderName}` : '';
    const when = formatDate(request.decidedAt);

    return `${request.statusLabel}${by}${when ? ` · ${when}` : ''}`;
}
</script>

<template>
    <EmptyState v-if="requests.length === 0" :message="emptyMessage" />

    <div v-else class="table-wrap">
        <table class="table changes">
            <colgroup>
                <col class="col-student" />
                <col v-if="admin" class="col-teacher" />
                <col />
                <col class="col-date" />
                <col class="col-status" />
                <col class="col-actions" />
            </colgroup>
            <thead>
                <tr>
                    <th>Student</th>
                    <th v-if="admin">Proposed by</th>
                    <th>Changes</th>
                    <th>Proposed</th>
                    <th>Status</th>
                    <th class="table-actions"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="request in requests" :key="request.id">
                    <td class="ellipsis">
                        <RouterLink
                            v-if="request.studentId"
                            :to="{ name: admin ? 'admin-student-detail' : 'student-detail', params: { id: request.studentId } }"
                            class="name"
                        >
                            {{ request.studentName || 'Student' }}
                        </RouterLink>
                        <span v-if="request.student?.classLabel" class="muted small">{{ request.student.classLabel }}</span>
                    </td>
                    <td v-if="admin" class="ellipsis">
                        <RouterLink v-if="request.requester" :to="{ name: 'admin-user-detail', params: { id: request.requester.id } }">
                            {{ request.requesterName }}
                        </RouterLink>
                        <span v-else>—</span>
                        <span v-if="request.requesterBranchName" class="muted small">{{ request.requesterBranchName }}</span>
                    </td>
                    <td>
                        <ul class="rows">
                            <li v-for="row in request.rows" :key="row.key">
                                <span class="muted">{{ row.label }}:</span>
                                <template v-if="request.isPending">
                                    <span class="from">{{ row.from }}</span>
                                    <span class="muted arrow" aria-label="becomes">→</span>
                                </template>
                                <strong>{{ row.to }}</strong>
                            </li>
                        </ul>
                    </td>
                    <td class="nowrap">{{ formatDate(request.createdAt) || '—' }}</td>
                    <td>
                        <StatusBadge :tone="request.tone">{{ request.statusLabel }}</StatusBadge>
                        <span v-if="decisionLine(request)" class="muted small">{{ decisionLine(request) }}</span>
                        <span v-if="request.declineReason" class="muted small" :title="request.declineReason">“{{ request.declineReason }}”</span>
                    </td>
                    <td class="table-actions">
                        <template v-if="request.isPending && admin">
                            <button
                                type="button"
                                class="btn btn-sm"
                                :disabled="!canWrite || busyId === request.id"
                                @click="emit('accept', request)"
                            >
                                Apply
                            </button>
                            <button
                                type="button"
                                class="btn btn-secondary btn-sm"
                                :disabled="!canWrite || busyId === request.id"
                                @click="emit('decline', request)"
                            >
                                Decline
                            </button>
                        </template>
                        <button
                            v-else-if="request.isPending"
                            type="button"
                            class="btn btn-link btn-sm btn-icon text-danger"
                            :title="`Withdraw the proposal for ${request.studentName}`"
                            :aria-label="`Withdraw the proposal for ${request.studentName}`"
                            :disabled="!canWrite || busyId === request.id"
                            @click="emit('withdraw', request)"
                        >
                            <AppIcon name="trash" />
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>

<style scoped>
.table-wrap {
    overflow-x: auto;
}

.changes {
    table-layout: fixed;
    min-width: 820px;
}

.col-student {
    width: 200px;
}

.col-teacher {
    width: 170px;
}

.col-date {
    width: 120px;
}

.col-status {
    width: 200px;
}

.col-actions {
    width: 160px;
}

.name {
    font-weight: 500;
    display: block;
}

.ellipsis {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

td .small {
    display: block;
    font-size: 0.82rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.rows {
    margin: 0;
    padding: 0;
    list-style: none;
    font-size: 0.9rem;
}

.rows li + li {
    margin-top: 2px;
}

.from {
    text-decoration: line-through;
    color: var(--muted);
}

.arrow {
    margin: 0 4px;
}

.table-actions .btn + .btn {
    margin-left: 4px;
}

.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    border: 0;
}
</style>
