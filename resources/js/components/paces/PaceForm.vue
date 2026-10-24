<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Pace } from '../../models';
import AppField from '../ui/AppField.vue';
import SearchSelect from '../ui/SearchSelect.vue';

/**
 * Admin: PACE create / edit form for a branch catalog. When creating, leaving
 * "End number" blank registers just the one PACE; filling it in registers
 * every number from "PACE number" through "End number" (inclusive), all
 * sharing the same name and starting pool stock.
 *
 *  props   pace        Pace|null   existing record to edit (null = blank create form)
 *          branches    Branch[]    the school's branches (new PACEs)
 *          branchId    Number|null branch preselected for a new PACE
 *          organizations Organization[] platform owner only: the schools to pick from first (a dropdown you can type into);
 *                                   the branch list then narrows to the chosen school
 *          organizationId Number|String|null  school preselected for a new PACE
 *          submitting  Boolean
 *          errors      Object      server-side 422 field map ({ branch_id, name, number|start|end, pool_stock })
 *          submitText  String      label of the primary button when creating
 *  emits   submit(Pace)                                             a Pace model built from the form (id kept when editing)
 *          submit-range({ branch_id, name, start, end, pool_stock }) only when creating with an end number filled in
 *          cancel()
 */
const props = defineProps({
    pace: { type: Object, default: null },
    branches: { type: Array, default: () => [] },
    branchId: { type: [Number, String], default: null },
    organizations: { type: Array, default: () => [] },
    organizationId: { type: [Number, String], default: null },
    submitting: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
    submitText: { type: String, default: 'Save' },
});

const emit = defineEmits(['submit', 'submit-range', 'cancel']);

const uid = `pace-${Math.random().toString(36).slice(2, 8)}`;
const MAX_RANGE_SIZE = 500;
const form = reactive({ organizationId: '', branchId: '', number: '', end: '', name: '', poolStock: 0 });
const clientErrors = ref({});

/** The school picker shows when the platform owner has schools to choose from. */
const pickSchool = computed(() => props.organizations.length > 0);

const schoolOptions = computed(() => props.organizations.map((organization) => ({ value: organization.id, label: organization.name })));

/** The branches to pick from: the chosen school's when the school picker is in use. */
const branchOptions = computed(() => {
    if (!pickSchool.value) {
        return props.branches;
    }

    return form.organizationId === '' ? [] : props.branches.filter((branch) => String(branch.organizationId) === String(form.organizationId));
});

function preselectedOrganizationId(branchId) {
    if (props.organizationId) {
        return props.organizationId;
    }

    return props.branches.find((branch) => String(branch.id) === String(branchId))?.organizationId ?? '';
}

watch(
    () => props.pace,
    (pace) => {
        clientErrors.value = {};
        form.branchId = pace?.exists
            ? (pace.branchId ?? '')
            : (props.branchId || (props.branches.length === 1 ? props.branches[0].id : '') || '');
        form.organizationId = pickSchool.value && !pace?.exists
            ? (preselectedOrganizationId(form.branchId) || (props.organizations.length === 1 ? props.organizations[0].id : ''))
            : '';

        if (form.organizationId !== '' && !form.branchId && branchOptions.value.length === 1) {
            form.branchId = branchOptions.value[0].id;
        }
        form.number = pace?.number ?? '';
        form.end = '';
        form.name = pace?.name ?? '';
        form.poolStock = pace?.exists ? pace.poolStock : 0;
    },
    { immediate: true },
);

watch(() => props.branchId, (branchId) => {
    if (!props.pace?.exists && branchId) {
        form.branchId = branchId;

        if (pickSchool.value) {
            form.organizationId = preselectedOrganizationId(branchId);
        }
    }
});

/** Choosing another school clears the branch; a school with a single branch selects it. */
function onSchoolChange() {
    form.branchId = '';

    if (branchOptions.value.length === 1) {
        form.branchId = branchOptions.value[0].id;
    }
}


const fieldErrors = computed(() => ({ ...clientErrors.value, ...props.errors }));
const isEdit = computed(() => Boolean(props.pace?.exists));
const isRange = computed(() => !isEdit.value && String(form.end).trim() !== '');

function stockValue() {
    const raw = String(form.poolStock ?? '').trim();

    return raw === '' ? 0 : Number(raw);
}

function validateStock(errors) {
    const stock = stockValue();

    if (!Number.isInteger(stock) || stock < 0) {
        errors.pool_stock = ['Pool stock must be a whole number of 0 or more.'];
    }
}

function validateBranch(errors) {
    if (!isEdit.value && pickSchool.value && !form.organizationId) {
        errors.branch_id = ['Please select the school first, then its branch.'];
    } else if (!isEdit.value && !form.branchId) {
        errors.branch_id = ['Please select the branch whose catalog this PACE goes in.'];
    }
}

function validateSingle() {
    const errors = {};
    const number = String(form.number).trim();

    validateBranch(errors);

    if (!number) {
        errors.number = ['PACE number is required.'];
    } else if (!/^[0-9]+$/.test(number)) {
        errors.number = ['PACE number must contain digits only.'];
    } else if (number.length > 50) {
        errors.number = ['PACE number may not be longer than 50 characters.'];
    }

    if (!String(form.name).trim()) {
        errors.name = ['PACE name is required.'];
    }

    if (!isEdit.value) {
        validateStock(errors);
    }

    return errors;
}

function validateRange() {
    const errors = {};
    const start = String(form.number).trim();
    const end = String(form.end).trim();

    validateBranch(errors);

    if (!/^[0-9]+$/.test(start)) {
        errors.number = ['PACE number must contain digits only when an end number is given.'];
    }

    if (!/^[0-9]+$/.test(end)) {
        errors.end = ['End number must contain digits only.'];
    } else if (/^[0-9]+$/.test(start) && Number(end) < Number(start)) {
        errors.end = ['End number must not be less than the PACE number.'];
    } else if (/^[0-9]+$/.test(start) && Number(end) - Number(start) + 1 > MAX_RANGE_SIZE) {
        errors.end = [`A single range may register at most ${MAX_RANGE_SIZE} PACEs.`];
    }

    if (!String(form.name).trim()) {
        errors.name = ['PACE name is required.'];
    }

    validateStock(errors);

    return errors;
}

function validate() {
    const errors = isRange.value ? validateRange() : validateSingle();
    clientErrors.value = errors;

    return Object.keys(errors).length === 0;
}

function submit() {
    if (props.submitting || !validate()) {
        return;
    }

    if (isRange.value) {
        emit('submit-range', {
            branch_id: Number(form.branchId),
            name: String(form.name).trim(),
            start: String(form.number).trim(),
            end: String(form.end).trim(),
            pool_stock: stockValue(),
        });

        return;
    }

    emit('submit', new Pace({
        id: props.pace?.id ?? null,
        branchId: form.branchId ? Number(form.branchId) : null,
        number: String(form.number).trim(),
        name: String(form.name).trim(),
        poolStock: stockValue(),
    }));
}
</script>

<template>
    <form class="pace-form" novalidate @submit.prevent="submit">
        <AppField
            v-if="!isEdit && pickSchool"
            label="School"
            :for="`${uid}-school`"
            :error="fieldErrors.organization_id"
            hint="Click to pick a school, or type part of its name to find it."
            required
        >
            <SearchSelect
                :id="`${uid}-school`"
                v-model="form.organizationId"
                :options="schoolOptions"
                placeholder="Select a school"
                empty-text="No school matches"
                :disabled="submitting"
                @change="onSchoolChange"
            />
        </AppField>
        <AppField
            v-if="!isEdit"
            label="Branch"
            :for="`${uid}-branch`"
            :error="fieldErrors.branch_id"
            hint="Each branch keeps its own PACE catalog and stock."
            required
        >
            <select :id="`${uid}-branch`" v-model="form.branchId" class="input" :disabled="submitting || (pickSchool && form.organizationId === '')">
                <option value="" disabled>{{ pickSchool && form.organizationId === '' ? 'Select a school first' : (branchOptions.length ? 'Select branch' : 'No branches') }}</option>
                <option v-for="branch in branchOptions" :key="branch.id" :value="branch.id">{{ branch.name }}</option>
            </select>
        </AppField>
        <p v-else-if="pace?.branchName" class="muted fixed">Catalog of {{ pace.branchName }}.</p>

        <AppField
            label="PACE number"
            :for="`${uid}-number`"
            :error="fieldErrors.number"
            hint="e.g. 1001"
            required
        >
            <input
                :id="`${uid}-number`"
                v-model="form.number"
                class="input"
                type="text"
                inputmode="numeric"
                maxlength="50"
                autocomplete="off"
                :disabled="submitting"
            />
        </AppField>

        <AppField
            v-if="!isEdit"
            label="End number"
            :for="`${uid}-end`"
            :error="fieldErrors.end"
            hint="Leave blank to register just one PACE, or fill in to register every number from the PACE number above through this one, e.g. 1024."
        >
            <input
                :id="`${uid}-end`"
                v-model="form.end"
                class="input"
                type="text"
                inputmode="numeric"
                maxlength="50"
                autocomplete="off"
                :disabled="submitting"
            />
        </AppField>

        <AppField
            :label="isRange ? 'PACE name (used for every number in the range)' : 'PACE name'"
            :for="`${uid}-name`"
            :error="fieldErrors.name"
            required
        >
            <input
                :id="`${uid}-name`"
                v-model="form.name"
                class="input"
                type="text"
                autocomplete="off"
                :disabled="submitting"
            />
        </AppField>

        <AppField
            v-if="!isEdit"
            :label="isRange ? 'Booklets received (for each PACE in the range)' : 'Booklets received'"
            :for="`${uid}-stock`"
            :error="fieldErrors.pool_stock"
            hint="Starts the branch pool; you can receive more and hand booklets to teachers later."
        >
            <input
                :id="`${uid}-stock`"
                v-model="form.poolStock"
                class="input stock-input"
                type="number"
                min="0"
                step="1"
                inputmode="numeric"
                :disabled="submitting"
            />
        </AppField>

        <div class="actions">
            <button type="button" class="btn btn-secondary" :disabled="submitting" @click="emit('cancel')">
                Cancel
            </button>
            <button type="submit" class="btn" :disabled="submitting">
                {{ submitting ? 'Saving…' : (isEdit ? 'Save changes' : (isRange ? 'Register PACEs' : submitText)) }}
            </button>
        </div>
    </form>
</template>

<style scoped>
.pace-form {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.fixed {
    margin: 0;
    font-size: 0.9rem;
}

.stock-input {
    max-width: 160px;
}


.actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 6px;
}
</style>
