<script setup>
import { computed, ref, watch } from 'vue';
import { assignmentService, paceService } from '../../services';
import { notify } from '../../stores/notifications';
import { quarterLabel } from '../../core/schoolYear';
import AppModal from '../ui/AppModal.vue';
import AppField from '../ui/AppField.vue';
import EmptyState from '../ui/EmptyState.vue';
import StatusBadge from '../ui/StatusBadge.vue';

/**
 * Assign one or more PACEs to a student for a subject / school year / quarter.
 *
 *  props   modelValue       Boolean       open state (v-model)
 *          student          Student       the student receiving the PACEs
 *          subject          Subject|null  subject the PACEs belong to
 *          schoolYear       String        "YYYY-YYYY"
 *          quarter          Number        1..4
 *          paces            Pace[]|null   list to choose from; when null the modal loads it itself
 *          assignedPaceIds  Number[]      PACE ids already assigned in this subject/quarter (shown disabled)
 *  emits   update:modelValue(Boolean)
 *          assigned(PaceAssignment[])     the records created by the API
 */
const props = defineProps({
    modelValue: { type: Boolean, default: false },
    student: { type: Object, default: null },
    subject: { type: Object, default: null },
    schoolYear: { type: String, default: '' },
    quarter: { type: Number, default: 1 },
    paces: { type: Array, default: null },
    assignedPaceIds: { type: Array, default: () => [] },
});

const emit = defineEmits(['update:modelValue', 'assigned']);

const uid = `assign-${Math.random().toString(36).slice(2, 8)}`;

const search = ref('');
const selected = ref([]);
const issuedDate = ref('');
const submitting = ref(false);
const fieldErrors = ref({});
const formError = ref('');

const localPaces = ref([]);
const loadingPaces = ref(false);

const source = computed(() => (Array.isArray(props.paces) ? props.paces : localPaces.value));

const filtered = computed(() => {
    const query = search.value.trim().toLowerCase();

    if (!query) {
        return source.value;
    }

    return source.value.filter(
        (pace) => String(pace.number).toLowerCase().includes(query) || String(pace.name).toLowerCase().includes(query),
    );
});

const assignedSet = computed(() => new Set(props.assignedPaceIds.map((id) => Number(id))));

/** Selected PACEs with no booklet left: they can be assigned, but not issued. */
const outOfStockSelected = computed(() => source.value.filter((pace) => selected.value.includes(pace.id) && pace.isOutOfStock));

/** Issuing takes a booklet from each PACE's stock, so an issued date needs every selected PACE in stock. */
const issuingBlocked = computed(() => issuedDate.value !== '' && outOfStockSelected.value.length > 0);

const selectableVisible = computed(() => filtered.value.filter((pace) => !assignedSet.value.has(pace.id)));

const allVisibleSelected = computed(
    () => selectableVisible.value.length > 0 && selectableVisible.value.every((pace) => selected.value.includes(pace.id)),
);

const title = computed(() => (props.subject ? `Assign PACEs · ${props.subject.name}` : 'Assign PACEs'));

const submitLabel = computed(() => {
    if (submitting.value) {
        return 'Assigning…';
    }

    const count = selected.value.length;

    if (count === 0) {
        return 'Assign PACEs';
    }

    return `Assign ${count} ${count === 1 ? 'PACE' : 'PACEs'}`;
});

const context = computed(() => {
    const parts = [];

    if (props.student) {
        parts.push(props.student.displayName);
    }

    if (props.schoolYear) {
        parts.push(`SY ${props.schoolYear}`);
    }

    if (props.quarter) {
        parts.push(quarterLabel(props.quarter));
    }

    return parts.join(' · ');
});

function isAssigned(pace) {
    return assignedSet.value.has(pace.id);
}

function isSelected(pace) {
    return selected.value.includes(pace.id);
}

function toggle(pace) {
    if (isAssigned(pace) || submitting.value) {
        return;
    }

    if (isSelected(pace)) {
        selected.value = selected.value.filter((id) => id !== pace.id);
    } else {
        selected.value = [...selected.value, pace.id];
    }

    formError.value = '';
}

function toggleVisible() {
    if (allVisibleSelected.value) {
        const visibleIds = new Set(selectableVisible.value.map((pace) => pace.id));
        selected.value = selected.value.filter((id) => !visibleIds.has(id));
    } else {
        const merged = new Set([...selected.value, ...selectableVisible.value.map((pace) => pace.id)]);
        selected.value = [...merged];
    }
}

function clearSelection() {
    selected.value = [];
}

async function loadPaces() {
    loadingPaces.value = true;

    try {
        localPaces.value = await paceService.list();
    } catch (err) {
        notify.error(err?.message ?? 'Failed to load PACEs.');
    } finally {
        loadingPaces.value = false;
    }
}

function reset() {
    search.value = '';
    selected.value = [];
    issuedDate.value = '';
    fieldErrors.value = {};
    formError.value = '';
}

watch(
    () => props.modelValue,
    (open) => {
        if (!open) {
            return;
        }

        reset();

        if (!Array.isArray(props.paces)) {
            loadPaces();
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
    if (submitting.value) {
        return;
    }

    fieldErrors.value = {};
    formError.value = '';

    if (!props.student?.id || !props.subject?.id) {
        formError.value = 'A student and a subject are required.';
        return;
    }

    if (selected.value.length === 0) {
        formError.value = 'Select at least one PACE to assign.';
        return;
    }

    if (issuingBlocked.value) {
        fieldErrors.value = { issued_date: 'Leave the issued date blank to assign the out-of-stock PACEs, or add stock first.' };
        return;
    }

    submitting.value = true;

    try {
        const created = await assignmentService.assign(props.student.id, {
            subject_id: props.subject.id,
            pace_ids: selected.value,
            school_year: props.schoolYear,
            quarter: props.quarter,
            issued_date: issuedDate.value || undefined,
        });

        const count = created.length || selected.value.length;
        notify.success(`${count} ${count === 1 ? 'PACE' : 'PACEs'} assigned to ${props.subject.name}.`);
        emit('assigned', created);
        emit('update:modelValue', false);
    } catch (err) {
        if (err?.isValidation) {
            const errors = err.errors ?? {};
            fieldErrors.value = { issued_date: errors.issued_date };

            const rest = Object.entries(errors)
                .filter(([key]) => key !== 'issued_date')
                .flatMap(([, value]) => (Array.isArray(value) ? value : [value]));

            formError.value = rest.length ? rest.join(' ') : (errors.issued_date ? '' : err.message);
        } else {
            notify.error(err?.message ?? 'Unable to assign the PACEs.');
        }
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <AppModal
        :model-value="modelValue"
        :title="title"
        width="560px"
        :closable="!submitting"
        :close-on-backdrop="false"
        @update:model-value="close"
    >
        <div class="assign">
            <p v-if="context" class="context muted">{{ context }}</p>

            <p v-if="formError" class="form-error" role="alert">{{ formError }}</p>

            <div class="toolbar">
                <input
                    v-model="search"
                    class="input search"
                    type="search"
                    placeholder="Search by number or name"
                    :disabled="submitting"
                    aria-label="Search PACEs"
                />
                <span class="count muted nowrap">{{ selected.length }} selected</span>
            </div>

            <div class="list-header">
                <label v-if="selectableVisible.length" class="checkbox muted">
                    <input
                        type="checkbox"
                        :checked="allVisibleSelected"
                        :disabled="submitting"
                        @change="toggleVisible"
                    />
                    Select all shown
                </label>
                <span v-else />
                <button
                    v-if="selected.length"
                    type="button"
                    class="btn btn-link btn-sm"
                    :disabled="submitting"
                    @click="clearSelection"
                >
                    Clear
                </button>
            </div>

            <div class="list" role="group" aria-label="PACEs">
                <p v-if="loadingPaces" class="muted state">Loading…</p>

                <EmptyState v-else-if="source.length === 0" compact>
                    No PACEs registered yet.
                    <template #action>
                        <RouterLink :to="{ name: 'paces' }" class="btn btn-secondary btn-sm">Go to PACEs</RouterLink>
                    </template>
                </EmptyState>

                <EmptyState v-else-if="filtered.length === 0" compact message="No PACEs match your search." />

                <template v-else>
                    <label
                        v-for="pace in filtered"
                        :key="pace.id"
                        class="item"
                        :class="{ selected: isSelected(pace), disabled: isAssigned(pace) }"
                    >
                        <input
                            type="checkbox"
                            :checked="isSelected(pace)"
                            :disabled="isAssigned(pace) || submitting"
                            @change="toggle(pace)"
                        />
                        <span class="item-number mono">#{{ pace.number }}</span>
                        <span class="item-name">{{ pace.name }}</span>
                        <span v-if="isAssigned(pace)" class="item-tag muted">Already assigned</span>
                        <StatusBadge v-else :tone="pace.stockTone" class="item-stock">{{ pace.stock }} in stock</StatusBadge>
                    </label>
                </template>
            </div>

            <AppField
                label="Issued date"
                :for="`${uid}-issued`"
                :error="fieldErrors.issued_date"
                hint="Leave blank if not yet issued. Issuing takes one booklet from each PACE's stock."
            >
                <input
                    :id="`${uid}-issued`"
                    v-model="issuedDate"
                    class="input issued"
                    type="date"
                    :disabled="submitting"
                />
            </AppField>

            <p v-if="issuingBlocked" class="form-error" role="alert">
                Out of stock, so they cannot be issued today:
                {{ outOfStockSelected.map((pace) => `#${pace.number}`).join(', ') }}.
                Leave the issued date blank to assign them anyway, or add stock first.
            </p>
        </div>

        <template #footer>
            <button type="button" class="btn btn-secondary" :disabled="submitting" @click="close">
                Cancel
            </button>
            <button type="button" class="btn" :disabled="submitting || selected.length === 0 || issuingBlocked" @click="submit">
                {{ submitLabel }}
            </button>
        </template>
    </AppModal>
</template>

<style scoped>
.assign {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.context {
    margin: 0;
    font-size: 0.92rem;
}

.form-error {
    margin: 0;
    padding: 8px 10px;
    border: 1px solid var(--danger);
    border-radius: var(--radius);
    color: var(--danger);
    background: rgba(var(--danger-rgb), 0.06);
    font-size: 0.92rem;
}

.toolbar {
    display: flex;
    align-items: center;
    gap: 12px;
}

.search {
    flex: 1;
}

.count {
    font-size: 0.92rem;
}

.list-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    min-height: 28px;
    font-size: 0.92rem;
}

.list {
    max-height: 280px;
    overflow-y: auto;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--surface);
}

.state {
    margin: 0;
    padding: 16px;
    text-align: center;
}

.item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 12px;
    border-bottom: 1px solid var(--border);
    cursor: pointer;
}

.item:last-child {
    border-bottom: none;
}

.item:hover {
    background: var(--bg);
}

.item.selected {
    background: rgba(var(--primary-rgb), 0.06);
}

.item.disabled {
    cursor: not-allowed;
    color: var(--muted);
}

.item input {
    margin: 0;
    accent-color: var(--primary);
}

.item-number {
    min-width: 64px;
    font-weight: 500;
}

.item-name {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.item-tag {
    font-size: 0.85rem;
    white-space: nowrap;
}

.item-stock {
    flex: 0 0 auto;
}

.issued {
    max-width: 220px;
}
</style>
