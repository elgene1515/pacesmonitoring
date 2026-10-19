<script setup>
import { computed, ref } from 'vue';
import { assignmentService } from '../../services';
import { notify } from '../../stores/notifications';
import { formatDate, formatScore } from '../../core/format';
import ConfirmDialog from '../ui/ConfirmDialog.vue';
import EmptyState from '../ui/EmptyState.vue';
import AppIcon from '../ui/AppIcon.vue';

/**
 * Compact table of PACE assignments with inline editing and a one-click
 * retake toggle.
 *
 *  props   assignments   PaceAssignment[]  rows to show (already filtered by the parent)
 *          paces         Pace[]            options for the PACE select while editing
 *          emptyMessage  String            shown when there are no rows
 *  emits   updated(PaceAssignment)         after a successful inline save or retake toggle (record returned by the API)
 *          removed(PaceAssignment)         after a successful delete
 */
const props = defineProps({
    assignments: { type: Array, required: true },
    paces: { type: Array, default: () => [] },
    emptyMessage: { type: String, default: 'No PACEs assigned' },
    /** Hide the edit / delete actions (records of a school year the student has moved on from). */
    readonly: { type: Boolean, default: false },
    /** Route a PACE number links to (the admin pages use their own read-only PACE page). */
    paceRoute: { type: String, default: 'pace-detail' },
});

const emit = defineEmits(['updated', 'removed']);

const editingId = ref(null);
const draft = ref(null);
const saving = ref(false);
const errors = ref({});

/** Id of the row whose retake flag is being saved. */
const togglingId = ref(null);

const confirming = ref(null);
const deleting = ref(false);

/** PACE options for the select; the row's current PACE is always included. */
const paceOptions = computed(() => {
    const list = [...props.paces];
    const current = draft.value?.pace;

    if (current && !list.some((pace) => pace.id === current.id)) {
        list.push(current);
    }

    return list.sort((a, b) => {
        const left = String(a.number);
        const right = String(b.number);
        return left.length - right.length || left.localeCompare(right);
    });
});

function isEditing(assignment) {
    return editingId.value === assignment.id;
}

function startEdit(assignment) {
    editingId.value = assignment.id;
    draft.value = assignment.clone();
    errors.value = {};
}

function cancelEdit() {
    if (saving.value) {
        return;
    }

    editingId.value = null;
    draft.value = null;
    errors.value = {};
}

function firstError(field) {
    const value = errors.value[field];
    if (Array.isArray(value)) {
        return value.length ? String(value[0]) : '';
    }

    return value ? String(value) : '';
}

function validate() {
    const record = draft.value;
    const found = {};

    if (!record.paceId) {
        found.pace_id = ['Select a PACE.'];
    }

    if (record.score !== null && record.score !== undefined && record.score !== '') {
        const score = Number(record.score);

        if (Number.isNaN(score) || score < 0 || score > 100) {
            found.score = ['Score must be between 0 and 100.'];
        }
    }

    if (record.issuedDate && record.dateTested && record.dateTested < record.issuedDate) {
        found.date_tested = ['Date tested cannot be earlier than the issued date.'];
    }

    errors.value = found;

    return Object.keys(found).length === 0;
}

async function save() {
    if (saving.value || !draft.value || !validate()) {
        return;
    }

    saving.value = true;

    try {
        const updated = await assignmentService.update(draft.value.id, draft.value);
        notify.success('PACE record updated.');
        emit('updated', updated);
        editingId.value = null;
        draft.value = null;
        errors.value = {};
    } catch (err) {
        if (err?.isValidation && err.hasFieldErrors) {
            errors.value = err.errors;
        } else {
            notify.error(err?.message ?? 'Unable to update the PACE record.');
        }
    } finally {
        saving.value = false;
    }
}

/** Flip the retake flag of a row and save it straight away. */
async function toggleRetake(assignment) {
    if (props.readonly || togglingId.value !== null || saving.value) {
        return;
    }

    togglingId.value = assignment.id;
    const retake = !assignment.retake;

    try {
        const updated = await assignmentService.update(assignment.id, { retake });
        notify.success(retake ? `PACE #${assignment.paceNumber} marked as retake.` : `Retake cleared for PACE #${assignment.paceNumber}.`);
        emit('updated', updated);
    } catch (err) {
        notify.error(err?.message ?? 'Unable to update the retake flag.');
    } finally {
        togglingId.value = null;
    }
}

function askRemove(assignment) {
    confirming.value = assignment;
}

async function remove() {
    if (deleting.value || !confirming.value) {
        return;
    }

    const target = confirming.value;
    deleting.value = true;

    try {
        await assignmentService.remove(target.id);
        notify.success(`PACE #${target.paceNumber} removed.`);
        emit('removed', target);

        if (editingId.value === target.id) {
            cancelEdit();
        }

        confirming.value = null;
    } catch (err) {
        notify.error(err?.message ?? 'Unable to remove the PACE.');
    } finally {
        deleting.value = false;
    }
}

function scoreClass(assignment) {
    if (assignment.score === null || assignment.score === undefined) {
        return '';
    }

    return assignment.isPassed ? 'text-success' : 'text-danger';
}

function statusLabel(assignment) {
    switch (assignment.status) {
        case 'tested':
            return 'Tested';
        case 'issued':
            return 'Issued';
        default:
            return 'Assigned';
    }
}
</script>

<template>
    <div class="assignment-table">
        <EmptyState v-if="assignments.length === 0" compact :message="emptyMessage" />

        <div v-else class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th class="col-number">PACE #</th>
                        <th>Name</th>
                        <th class="col-date">Issued</th>
                        <th class="col-date">Tested</th>
                        <th class="col-score">Score</th>
                        <th class="col-retake">Retake</th>
                        <th class="col-status">Status</th>
                        <th class="table-actions"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="assignment in assignments" :key="assignment.id">
                        <tr v-if="isEditing(assignment)" class="editing">
                            <td colspan="2">
                                <select v-model="draft.paceId" class="input cell-input" :disabled="saving" aria-label="PACE">
                                    <option :value="null" disabled>Select PACE</option>
                                    <option v-for="pace in paceOptions" :key="pace.id" :value="pace.id">
                                        {{ pace.label }}
                                    </option>
                                </select>
                                <small v-if="firstError('pace_id')" class="cell-error">{{ firstError('pace_id') }}</small>
                            </td>
                            <td>
                                <input v-model="draft.issuedDate" class="input cell-input" type="date" :disabled="saving" aria-label="Issued date" />
                                <small v-if="firstError('issued_date')" class="cell-error">{{ firstError('issued_date') }}</small>
                            </td>
                            <td>
                                <input v-model="draft.dateTested" class="input cell-input" type="date" :disabled="saving" aria-label="Date tested" />
                                <small v-if="firstError('date_tested')" class="cell-error">{{ firstError('date_tested') }}</small>
                            </td>
                            <td>
                                <input
                                    v-model="draft.score"
                                    class="input cell-input score-input"
                                    type="number"
                                    min="0"
                                    max="100"
                                    step="0.01"
                                    inputmode="decimal"
                                    :disabled="saving"
                                    aria-label="Score"
                                />
                                <small v-if="firstError('score')" class="cell-error">{{ firstError('score') }}</small>
                            </td>
                            <td colspan="2">
                                <label class="checkbox retake-check">
                                    <input v-model="draft.retake" type="checkbox" :disabled="saving" />
                                    Retake
                                </label>
                                <small v-if="firstError('retake')" class="cell-error">{{ firstError('retake') }}</small>
                            </td>
                            <td class="table-actions">
                                <button type="button" class="btn btn-sm" :disabled="saving" @click="save">
                                    {{ saving ? 'Saving…' : 'Save' }}
                                </button>
                                <button type="button" class="btn btn-secondary btn-sm" :disabled="saving" @click="cancelEdit">
                                    Cancel
                                </button>
                            </td>
                        </tr>

                        <tr v-else>
                            <td class="mono col-number">
                                <RouterLink v-if="assignment.paceId" :to="{ name: paceRoute, params: { id: assignment.paceId } }">
                                    #{{ assignment.paceNumber }}
                                </RouterLink>
                                <span v-else class="muted">—</span>
                            </td>
                            <td>{{ assignment.paceName || '—' }}</td>
                            <td class="nowrap">
                                <span v-if="assignment.issuedDate">{{ formatDate(assignment.issuedDate) }}</span>
                                <span v-else class="muted">—</span>
                            </td>
                            <td class="nowrap">
                                <span v-if="assignment.dateTested">{{ formatDate(assignment.dateTested) }}</span>
                                <span v-else class="muted">—</span>
                            </td>
                            <td class="mono" :class="scoreClass(assignment)">
                                <span v-if="assignment.score !== null">{{ formatScore(assignment.score) }}</span>
                                <span v-else class="muted">—</span>
                            </td>
                            <td class="retake-cell">
                                <button
                                    type="button"
                                    class="retake-toggle"
                                    :class="{ on: assignment.retake }"
                                    role="switch"
                                    :aria-checked="assignment.retake"
                                    :disabled="readonly || togglingId !== null || saving"
                                    :title="readonly
                                        ? (assignment.retake ? 'Retake' : 'Not a retake')
                                        : (assignment.retake ? 'Marked as retake · click to clear' : 'Mark as retake')"
                                    :aria-label="`Retake for PACE #${assignment.paceNumber}`"
                                    @click="toggleRetake(assignment)"
                                >
                                    <AppIcon name="retake" />
                                    <span class="retake-label">{{ togglingId === assignment.id ? 'Saving…' : (assignment.retake ? 'Retake' : 'No') }}</span>
                                </button>
                            </td>
                            <td>
                                <span class="status" :class="`status-${assignment.status}`">{{ statusLabel(assignment) }}</span>
                            </td>
                            <td class="table-actions">
                                <template v-if="!readonly">
                                    <button
                                        type="button"
                                        class="btn btn-link btn-sm btn-icon"
                                        :disabled="saving"
                                        :title="`Edit PACE #${assignment.paceNumber}`"
                                        :aria-label="`Edit PACE #${assignment.paceNumber}`"
                                        @click="startEdit(assignment)"
                                    >
                                        <AppIcon name="pencil" />
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-link btn-sm btn-icon text-danger"
                                        :disabled="saving"
                                        :title="`Remove PACE #${assignment.paceNumber}`"
                                        :aria-label="`Remove PACE #${assignment.paceNumber}`"
                                        @click="askRemove(assignment)"
                                    >
                                        <AppIcon name="trash" />
                                    </button>
                                </template>
                                <span v-else class="muted">View only</span>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <ConfirmDialog
            :model-value="confirming !== null"
            title="Remove PACE"
            confirm-text="Remove"
            :busy="deleting"
            @update:model-value="confirming = null"
            @confirm="remove"
        >
            Remove PACE
            <strong v-if="confirming">#{{ confirming.paceNumber }} {{ confirming.paceName }}</strong>
            from this student's records? Any recorded dates and score will be lost.
        </ConfirmDialog>
    </div>
</template>

<style scoped>
.table-wrap {
    overflow-x: auto;
}

.table {
    min-width: 760px;
}

.table th,
.table td {
    padding: 8px 10px;
    vertical-align: top;
}

.table tbody td {
    vertical-align: middle;
}

.col-number {
    width: 90px;
}

.col-date {
    width: 130px;
}

.col-score {
    width: 90px;
}

.col-retake {
    width: 110px;
}

.col-status {
    width: 90px;
}

tr.editing td {
    background: rgba(var(--primary-rgb), 0.04);
}

.cell-input {
    padding: 5px 8px;
    min-width: 0;
}

.score-input {
    max-width: 90px;
}

.cell-error {
    display: block;
    margin-top: 3px;
    color: var(--danger);
    font-size: 0.8rem;
}

.retake-check {
    font-size: 0.92rem;
}

/* Pill-shaped switch: grey when off, warm orange when the PACE is a retake. */
.retake-toggle {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 2px 10px 2px 8px;
    border: 1px solid var(--border);
    border-radius: 999px;
    background: var(--surface);
    color: var(--muted);
    font-size: 0.82rem;
    line-height: 1.5;
    cursor: pointer;
    white-space: nowrap;
    transition: background-color 0.12s ease, border-color 0.12s ease, color 0.12s ease;
}

.retake-toggle:hover:not(:disabled) {
    border-color: var(--border-strong);
    color: var(--text);
}

.retake-toggle:focus-visible {
    outline: 2px solid var(--primary);
    outline-offset: 2px;
}

.retake-toggle:disabled {
    cursor: default;
    opacity: 0.7;
}

.retake-toggle.on {
    border-color: rgba(var(--warn-rgb), 0.5);
    background: rgba(var(--warn-rgb), 0.1);
    color: var(--warn-text);
}

.retake-toggle .app-icon {
    width: 14px;
    height: 14px;
}

.status {
    display: inline-block;
    padding: 1px 8px;
    border-radius: 999px;
    border: 1px solid var(--border);
    font-size: 0.82rem;
    color: var(--muted);
    white-space: nowrap;
}

.status-issued {
    border-color: rgba(var(--primary-rgb), 0.35);
    color: var(--primary);
}

.status-tested {
    border-color: rgba(var(--success-rgb), 0.35);
    color: var(--success);
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
