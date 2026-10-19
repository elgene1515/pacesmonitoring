<script setup>
import { RouterLink } from 'vue-router';
import { formatDate } from '../../core/format';
import EmptyState from '../ui/EmptyState.vue';
import AppIcon from '../ui/AppIcon.vue';
import StatusBadge from '../ui/StatusBadge.vue';

/** The most recently registered students and members of the school, side by side (stacked on small screens). */
defineProps({
    students: { type: Array, default: () => [] },
    users: { type: Array, default: () => [] },
    loading: { type: Boolean, default: false },
});

function roleTone(user) {
    if (user.isOwner) {
        return 'bad';
    }

    return user.isAdmin ? 'info' : 'muted';
}
</script>

<template>
    <div class="recent-lists grid-2">
        <section class="card recent">
            <div class="recent-header">
                <h2 class="card-title">Recent students</h2>
                <RouterLink :to="{ name: 'admin-students' }" class="muted view-all">View all</RouterLink>
            </div>

            <p v-if="loading" class="muted">Loading…</p>

            <EmptyState v-else-if="students.length === 0" compact message="No students registered yet." />

            <table v-else class="table">
                <colgroup>
                    <col />
                    <col class="col-grade" />
                    <col class="col-teacher" />
                    <col class="col-actions" />
                </colgroup>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Grade level</th>
                        <th>Teacher</th>
                        <th class="table-actions"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="student in students" :key="student.id">
                        <td class="ellipsis">
                            <RouterLink :to="{ name: 'admin-student-detail', params: { id: student.id } }">
                                {{ student.displayName }}
                            </RouterLink>
                        </td>
                        <td class="ellipsis">{{ student.gradeLevelName || '—' }}</td>
                        <td class="ellipsis">{{ student.creatorName || '—' }}</td>
                        <td class="table-actions">
                            <RouterLink
                                :to="{ name: 'admin-student-detail', params: { id: student.id } }"
                                class="btn btn-link btn-sm btn-icon"
                                :title="`View ${student.displayName}`"
                                :aria-label="`View ${student.displayName}`"
                            >
                                <AppIcon name="eye" />
                            </RouterLink>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section class="card recent">
            <div class="recent-header">
                <h2 class="card-title">Recent members</h2>
                <RouterLink :to="{ name: 'admin-users' }" class="muted view-all">View all</RouterLink>
            </div>

            <p v-if="loading" class="muted">Loading…</p>

            <EmptyState v-else-if="users.length === 0" compact message="No users registered yet." />

            <table v-else class="table">
                <colgroup>
                    <col />
                    <col class="col-role" />
                    <col class="col-date" />
                    <col class="col-actions" />
                </colgroup>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Role</th>
                        <th>Registered</th>
                        <th class="table-actions"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="user in users" :key="user.id">
                        <td class="ellipsis">
                            <RouterLink :to="{ name: 'admin-user-detail', params: { id: user.id } }" :title="user.email">
                                {{ user.name }}
                            </RouterLink>
                            <span v-if="user.branchLabel" class="muted small">{{ user.branchLabel }}</span>
                        </td>
                        <td><StatusBadge :tone="roleTone(user)">{{ user.roleLabel }}</StatusBadge></td>
                        <td class="nowrap muted">{{ formatDate(user.createdAt) || '—' }}</td>
                        <td class="table-actions">
                            <RouterLink
                                :to="{ name: 'admin-user-detail', params: { id: user.id } }"
                                class="btn btn-link btn-sm btn-icon"
                                :title="`View ${user.name}`"
                                :aria-label="`View ${user.name}`"
                            >
                                <AppIcon name="eye" />
                            </RouterLink>
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>
    </div>
</template>

<style scoped>
.recent-lists > .card {
    min-width: 0;
}

.recent-header {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 12px;
}

.view-all {
    font-size: 0.92rem;
}

.view-all:hover {
    color: var(--primary);
}

.recent .table {
    table-layout: fixed;
}

.col-grade,
.col-teacher {
    width: 28%;
}

.col-role {
    width: 90px;
}

.col-date {
    width: 120px;
}

.col-actions {
    width: 52px;
}

.ellipsis {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.small {
    display: block;
    font-size: 0.8rem;
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
