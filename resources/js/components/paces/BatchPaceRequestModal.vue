<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { usePacesStore } from '../../stores/paces';
import { usePaceRequestsStore } from '../../stores/paceRequests';
import { notify } from '../../stores/notifications';
import { pluralize } from '../../core/format';
import AppModal from '../ui/AppModal.vue';
import AppField from '../ui/AppField.vue';
import AppIcon from '../ui/AppIcon.vue';

/**
 * Ask the school admin for booklets of several PACEs at once: tick the PACEs
 * of the branch catalog you need and say how many of each, add PACEs that are
 * not in the catalog yet by number and name, and send one request per PACE
 * (they share the needed-by date and note).
 *
 *  props   modelValue  Boolean   open state (v-model)
 *  emits   update:modelValue(Boolean)
 *          saved(PaceRequest[])
 */
const props = defineProps({
    modelValue: { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue', 'saved']);

const paces = usePacesStore();
const requests = usePaceRequestsStore();

const uid = `batch-request-${Math.random().toString(36).slice(2, 8)}`;
const search = ref('');
/** pace id -> { checked, quantity } for the catalog rows. */
const picks = reactive({});
/** Free-form rows for PACEs not in the catalog yet. */
const extras = ref([]);
const form = reactive({ needed_by: '', message: '' });
const clientErrors = ref({});
const serverErrors = ref({});
const submitting = ref(false);

const errors = computed(() => ({ ...clientErrors.value, ...serverErrors.value }));

const searchTerm = computed(() => search.value.trim().toLowerCase());

/** Catalog PACEs by number, narrowed by the search box (ticked ones always stay visible). */
const visiblePaces = computed(() => [...paces.items]
    .filter((pace) => searchTerm.value === ''
        || picks[pace.id]?.checked
        || String(pace.number).toLowerCase().includes(searchTerm.value)
        || String(pace.name).toLowerCase().includes(searchTerm.value))
    .sort((a, b) => {
        const left = String(a.number);
        const right = String(b.number);

        return left.length - right.length || left.localeCompare(right) || String(a.name).localeCompare(String(b.name));
    }));

const checkedPaces = computed(() => paces.items.filter((pace) => picks[pace.id]?.checked));
const filledExtras = computed(() => extras.value.filter((row) => String(row.pace_number).trim() !== '' || String(row.pace_name).trim() !== ''));
const totalCount = computed(() => checkedPaces.value.length + filledExtras.value.length);

function pickOf(pace) {
    if (!picks[pace.id]) {
        picks[pace.id] = { checked: false, quantity: 1 };
    }

    return picks[pace.id];
}

function toggle(pace) {
    const pick = pickOf(pace);
    pick.checked = !pick.checked;
}

function addExtra() {
    extras.value.push({ key: Math.random().toString(36).slice(2, 8), pace_number: '', pace_name: '', quantity: 1 });
}

function removeExtra(index) {
    extras.value.splice(index, 1);
}

function reset() {
    search.value = '';
    Object.keys(picks).forEach((key) => delete picks[key]);
    extras.value = [];
    form.needed_by = '';
    form.message = '';
    clientErrors.value = {};
    serverErrors.value = {};
}

watch(
    () => props.modelValue,
    (open) => {
        if (!open) {
            return;
        }

        reset();

        if (!paces.loaded && !paces.loading) {
            paces.fetch();
        }
    },
);

function close() {
    if (submitting.value) {
        return;
    }

    emit('update:modelValue', false);
}

function validate() {
    const found = {};

    if (totalCount.value === 0) {
        found.items = ['Tick at least one PACE, or add one by number and name.'];
    }

    for (const pace of checkedPaces.value) {
        const quantity = Number(picks[pace.id].quantity);

        if (!Number.isInteger(quantity) || quantity < 1) {
            found[`pace-${pace.id}`] = ['At least 1.'];
        }
    }

    filledExtras.value.forEach((row) => {
        const number = String(row.pace_number).trim();
        const quantity = Number(row.quantity);

        if (!number) {
            found[`extra-${row.key}-number`] = ['Enter the PACE number.'];
        } else if (!/^[0-9]+$/.test(number)) {
            found[`extra-${row.key}-number`] = ['Digits only.'];
        }

        if (!String(row.pace_name).trim()) {
            found[`extra-${row.key}-name`] = ['Enter the PACE name.'];
        }

        if (!Number.isInteger(quantity) || quantity < 1) {
            found[`extra-${row.key}-quantity`] = ['At least 1.'];
        }
    });

    clientErrors.value = found;

    return Object.keys(found).length === 0;
}

async function submit() {
    if (submitting.value || !validate()) {
        return;
    }

    submitting.value = true;
    serverErrors.value = {};

    const items = [
        ...checkedPaces.value.map((pace) => ({ pace_id: pace.id, quantity: Number(picks[pace.id].quantity) })),
        ...filledExtras.value.map((row) => ({
            pace_id: null,
            pace_number: String(row.pace_number).trim(),
            pace_name: String(row.pace_name).trim(),
            quantity: Number(row.quantity),
        })),
    ];

    try {
        const saved = await requests.createMany({
            items,
            needed_by: form.needed_by || null,
            message: String(form.message).trim() || null,
        });

        notify.success(`${pluralize(saved.length, 'stock request')} sent to your school admin.`);
        emit('saved', saved);
        emit('update:modelValue', false);
    } catch (err) {
        if (err?.isValidation) {
            // Field keys come back as items.N.field; the first message is enough to act on.
            serverErrors.value = err.hasFieldErrors ? { items: [err.first('items') ?? Object.values(err.errors)[0]?.[0] ?? err.message] } : { items: [err.message] };
        } else {
            notify.error(err?.message ?? 'Unable to send the requests.');
        }
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <AppModal
        :model-value="modelValue"
        title="Request Stock"
        width="640px"
        :closable="!submitting"
        :close-on-backdrop="false"
        @update:model-value="close"
    >
        <form v-if="modelValue" class="batch-form" novalidate @submit.prevent="submit">
            <p class="muted intro">
                Tick the PACEs you need and how many booklets of each. Your admin gets one request per PACE and
                answers each from the branch pool.
            </p>

            <p v-if="errors.items" class="form-error" role="alert">{{ errors.items[0] }}</p>

            <!-- The branch catalog -->
            <section class="block">
                <div class="block-header">
                    <h3 class="block-title">From the catalog</h3>
                    <input
                        v-model="search"
                        class="input search"
                        type="search"
                        placeholder="Search number or name"
                        aria-label="Search the catalog"
                        :disabled="submitting"
                    />
                </div>

                <p v-if="paces.loading && !paces.loaded" class="muted state">Loading…</p>
                <p v-else-if="paces.items.length === 0" class="muted state">Your branch has no PACEs in its catalog yet; add them below by number and name.</p>
                <p v-else-if="visiblePaces.length === 0" class="muted state">No PACE matches “{{ search.trim() }}”.</p>

                <div v-else class="catalog">
                    <div v-for="pace in visiblePaces" :key="pace.id" class="row" :class="{ picked: pickOf(pace).checked }">
                        <label class="checkbox pick">
                            <input type="checkbox" :checked="pickOf(pace).checked" :disabled="submitting" @change="toggle(pace)" />
                            <span class="pick-text">
                                <span class="pick-label">{{ pace.label }}</span>
                                <span class="muted small">you hold {{ pace.stock }} · pool {{ pace.poolStock }}</span>
                            </span>
                        </label>
                        <div class="qty">
                            <input
                                v-model="pickOf(pace).quantity"
                                class="input qty-input"
                                type="number"
                                min="1"
                                step="1"
                                inputmode="numeric"
                                :aria-label="`Booklets of PACE ${pace.label}`"
                                :disabled="submitting || !pickOf(pace).checked"
                            />
                            <span v-if="errors[`pace-${pace.id}`]" class="text-danger small">{{ errors[`pace-${pace.id}`][0] }}</span>
                        </div>
                    </div>
                </div>
            </section>

            <!-- PACEs the admin still has to add to the catalog -->
            <section class="block">
                <div class="block-header">
                    <h3 class="block-title">Not in the catalog yet</h3>
                    <button type="button" class="btn btn-secondary btn-sm" :disabled="submitting" @click="addExtra">
                        <AppIcon name="plus" /> Add a PACE
                    </button>
                </div>
                <p v-if="extras.length === 0" class="muted state">Your admin adds a new PACE to the branch catalog when giving the booklets.</p>
                <div v-else class="extras">
                    <div v-for="(row, index) in extras" :key="row.key" class="extra-row">
                        <AppField label="PACE number" :for="`${uid}-${row.key}-number`" :error="errors[`extra-${row.key}-number`]" required>
                            <input
                                :id="`${uid}-${row.key}-number`"
                                v-model="row.pace_number"
                                class="input"
                                type="text"
                                inputmode="numeric"
                                maxlength="50"
                                autocomplete="off"
                                :disabled="submitting"
                            />
                        </AppField>
                        <AppField label="PACE name" :for="`${uid}-${row.key}-name`" :error="errors[`extra-${row.key}-name`]" required>
                            <input :id="`${uid}-${row.key}-name`" v-model="row.pace_name" class="input" type="text" autocomplete="off" :disabled="submitting" />
                        </AppField>
                        <AppField label="Qty" :for="`${uid}-${row.key}-qty`" :error="errors[`extra-${row.key}-quantity`]" required>
                            <input :id="`${uid}-${row.key}-qty`" v-model="row.quantity" class="input" type="number" min="1" step="1" inputmode="numeric" :disabled="submitting" />
                        </AppField>
                        <button
                            type="button"
                            class="btn btn-link btn-sm btn-icon text-danger remove"
                            title="Remove this row"
                            aria-label="Remove this row"
                            :disabled="submitting"
                            @click="removeExtra(index)"
                        >
                            <AppIcon name="trash" />
                        </button>
                    </div>
                </div>
            </section>

            <div class="grid-2">
                <AppField label="Needed by" :for="`${uid}-needed`" :error="errors.needed_by" hint="Optional; applies to every PACE here.">
                    <input :id="`${uid}-needed`" v-model="form.needed_by" class="input" type="date" :disabled="submitting" />
                </AppField>
                <AppField label="Note to your admin" :for="`${uid}-message`" :error="errors.message" hint="Optional.">
                    <input :id="`${uid}-message`" v-model="form.message" class="input" type="text" maxlength="255" :disabled="submitting" />
                </AppField>
            </div>

            <div class="actions">
                <span class="muted count">{{ totalCount ? `${pluralize(totalCount, 'PACE')} selected` : 'Nothing selected yet' }}</span>
                <button type="button" class="btn btn-secondary" :disabled="submitting" @click="close">Cancel</button>
                <button type="submit" class="btn" :disabled="submitting || totalCount === 0">
                    {{ submitting ? 'Sending…' : (totalCount > 1 ? `Send ${totalCount} requests` : 'Send request') }}
                </button>
            </div>
        </form>
    </AppModal>
</template>

<style scoped>
.batch-form {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.intro,
.state {
    margin: 0;
}

.state {
    font-size: 0.9rem;
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

.block {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.block-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}

.block-title {
    margin: 0;
    font-size: 0.95rem;
    font-weight: 600;
}

.search {
    width: 220px;
    max-width: 100%;
}

/* The catalog list scrolls inside the dialog so a long catalog stays usable. */
.catalog {
    max-height: 280px;
    overflow-y: auto;
    border: 1px solid var(--border);
    border-radius: var(--radius);
}

.row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 8px 12px;
}

.row + .row {
    border-top: 1px solid var(--border);
}

.row.picked {
    background: rgba(var(--primary-rgb), 0.05);
}

.pick {
    flex: 1;
    min-width: 0;
    align-items: flex-start;
}

.pick-text {
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.pick-label {
    font-weight: 500;
}

.small {
    font-size: 0.82rem;
}

.qty {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 2px;
}

.qty-input {
    width: 84px;
}

.extras {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.extra-row {
    display: grid;
    grid-template-columns: 130px minmax(0, 1fr) 84px auto;
    gap: 10px;
    align-items: start;
}

.extra-row .remove {
    margin-top: 26px;
}

.actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 4px;
}

.actions .count {
    margin-right: auto;
    font-size: 0.9rem;
}

@media (max-width: 560px) {
    .extra-row {
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    }

    .extra-row .remove {
        margin-top: 0;
        justify-self: end;
    }
}
</style>
