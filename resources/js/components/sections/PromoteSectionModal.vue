<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { adminService } from '../../services';
import { notify } from '../../stores/notifications';
import { pluralize } from '../../core/format';
import AppModal from '../ui/AppModal.vue';
import AppField from '../ui/AppField.vue';

/**
 * Year-end promotion of a section (admin): pick the school year and the
 * section the students go to, adjust single students (a repeater goes to
 * the same grade level's section, a student who left stays), and move
 * everyone in one go. Each student keeps every PACE record; the move is
 * written to their enrollment history.
 *
 *  props   modelValue  Boolean   open state (v-model)
 *          section     Section   the section being promoted (with gradeLevel: branchId, schoolYearId, sortOrder)
 *          students    Student[] its roster
 *  emits   update:modelValue(Boolean)
 *          done({moved, stayed})
 */
const props = defineProps({
    modelValue: { type: Boolean, default: false },
    section: { type: Object, required: true },
    students: { type: Array, default: () => [] },
});

const emit = defineEmits(['update:modelValue', 'done']);

const STAY = 'stay';

const uid = `promote-${Math.random().toString(36).slice(2, 8)}`;
const schoolYears = ref([]);
const loadingYears = ref(false);
const yearId = ref('');
const gradeLevels = ref([]);
const loadingLevels = ref(false);
/** The section every student goes to unless changed per row. */
const targetId = ref('');
/** student id -> section id | 'stay' */
const choices = reactive({});
const submitting = ref(false);
const error = ref('');

const currentLevel = computed(() => props.section?.gradeLevel ?? null);

/** Every section of the chosen year's grade levels (in the section's branch), labelled "Grade 2 · Section A". */
const sectionOptions = computed(() => gradeLevels.value.flatMap((level) => level.sections.map((section) => ({
    id: section.id,
    label: `${level.name} · ${section.name}`,
    gradeLevelId: level.id,
}))).filter((option) => Number(option.id) !== Number(props.section?.id)));

const targetOption = computed(() => sectionOptions.value.find((option) => Number(option.id) === Number(targetId.value)) ?? null);

const movingCount = computed(() => props.students.filter((student) => choices[student.id] && choices[student.id] !== STAY).length);
const stayingCount = computed(() => props.students.length - movingCount.value);

const yearLabel = (year) => `${year.name}${year.isCurrent ? ' (current)' : ''}`;

/** Suggest the year after the section's: the next newer one in the list (newest first). */
function suggestYear() {
    const index = schoolYears.value.findIndex((year) => Number(year.id) === Number(currentLevel.value?.schoolYearId));

    if (index > 0) {
        return schoolYears.value[index - 1].id;
    }

    return schoolYears.value[0]?.id ?? '';
}

/**
 * Suggest the next grade level's first section. The next grade level is found by name: the
 * one whose number is one higher ("Grade 1" -> "Grade 2"); a level without a number
 * (Kindergarten) goes to the lowest level of the new year; otherwise the first section offered.
 * (Sort order is no guide: the new year's list need not start at the same place.)
 */
function suggestTarget() {
    const withSections = gradeLevels.value.filter((level) => level.sections.length > 0);
    const match = /^(.*?)(\d+)\s*$/.exec(String(currentLevel.value?.name ?? '').trim());

    let next = null;

    if (match) {
        const wanted = `${match[1]}${Number(match[2]) + 1}`.trim().toLowerCase();
        next = withSections.find((level) => String(level.name).trim().toLowerCase() === wanted) ?? null;
    } else {
        next = [...withSections].sort((a, b) => Number(a.sortOrder) - Number(b.sortOrder))[0] ?? null;
    }

    const option = next ? sectionOptions.value.find((candidate) => candidate.gradeLevelId === next.id) : null;

    return option?.id ?? sectionOptions.value[0]?.id ?? '';
}

function applyTargetToAll() {
    for (const student of props.students) {
        choices[student.id] = targetId.value || STAY;
    }
}

async function loadYears() {
    loadingYears.value = true;

    try {
        schoolYears.value = await adminService.listSchoolYears();
        yearId.value = suggestYear();
        await loadLevels();
    } catch (err) {
        notify.error(err?.message ?? 'Failed to load the school years.');
    } finally {
        loadingYears.value = false;
    }
}

async function loadLevels() {
    gradeLevels.value = [];
    targetId.value = '';

    if (!yearId.value) {
        applyTargetToAll();
        return;
    }

    loadingLevels.value = true;

    try {
        gradeLevels.value = await adminService.listGradeLevels({
            school_year_id: yearId.value,
            branch_id: currentLevel.value?.branchId ?? '',
        });
        targetId.value = suggestTarget();
    } catch (err) {
        notify.error(err?.message ?? 'Failed to load the grade levels.');
    } finally {
        loadingLevels.value = false;
        applyTargetToAll();
    }
}

function onYearChange() {
    loadLevels();
}

function onTargetChange() {
    applyTargetToAll();
}

watch(
    () => props.modelValue,
    (open) => {
        if (!open) {
            return;
        }

        error.value = '';
        Object.keys(choices).forEach((key) => delete choices[key]);
        loadYears();
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

    if (movingCount.value === 0) {
        error.value = 'Nobody is being moved: pick a destination for at least one student.';
        return;
    }

    submitting.value = true;
    error.value = '';

    const moves = props.students.map((student) => ({
        student_id: student.id,
        section_id: choices[student.id] && choices[student.id] !== STAY ? Number(choices[student.id]) : null,
    }));

    try {
        const result = await adminService.promoteSection(props.section.id, moves);
        notify.success(`${pluralize(result.moved, 'student')} moved${result.stayed ? `, ${result.stayed} stayed` : ''}.`);
        emit('done', result);
        emit('update:modelValue', false);
    } catch (err) {
        error.value = err?.isValidation ? (err.first('moves') ?? err.message) : (err?.message ?? 'Unable to move the students.');
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <AppModal
        :model-value="modelValue"
        :title="`Promote ${section?.label ?? 'section'}`"
        width="680px"
        :closable="!submitting"
        :close-on-backdrop="false"
        @update:model-value="close"
    >
        <form v-if="modelValue" class="promote-form" novalidate @submit.prevent="submit">
            <p class="muted intro">
                Move the students to the section they continue in. Change a single student's destination for a
                repeater, or leave them where they are if they are no longer with the school. PACE records stay
                filed under this school year; the move goes into each student's enrollment history.
            </p>

            <p v-if="error" class="form-error" role="alert">{{ error }}</p>

            <div class="grid-2">
                <AppField label="School year" :for="`${uid}-year`">
                    <select :id="`${uid}-year`" v-model="yearId" class="input" :disabled="loadingYears || submitting" @change="onYearChange">
                        <option value="">Select a school year</option>
                        <option v-for="year in schoolYears" :key="year.id" :value="year.id">{{ yearLabel(year) }}</option>
                    </select>
                </AppField>
                <AppField label="Everyone goes to" :for="`${uid}-target`" hint="Then adjust single students below.">
                    <select :id="`${uid}-target`" v-model="targetId" class="input" :disabled="loadingLevels || submitting || !yearId" @change="onTargetChange">
                        <option value="">{{ loadingLevels ? 'Loading…' : (sectionOptions.length ? 'Select a section' : 'No sections in this school year') }}</option>
                        <option v-for="option in sectionOptions" :key="option.id" :value="option.id">{{ option.label }}</option>
                    </select>
                </AppField>
            </div>

            <p v-if="yearId && !loadingLevels && sectionOptions.length === 0" class="muted note">
                Register the grade levels and sections of that school year under Grade Levels first.
            </p>

            <div class="table-wrap roster">
                <table class="table">
                    <colgroup>
                        <col />
                        <col class="col-destination" />
                    </colgroup>
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Goes to</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="student in students" :key="student.id" :class="{ stays: choices[student.id] === STAY }">
                            <td class="ellipsis">{{ student.fullName }}</td>
                            <td>
                                <select v-model="choices[student.id]" class="input select-sm" :aria-label="`Destination of ${student.fullName}`" :disabled="submitting">
                                    <option :value="STAY">Stays in {{ section?.name }}</option>
                                    <option v-for="option in sectionOptions" :key="option.id" :value="option.id">{{ option.label }}</option>
                                </select>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="actions">
                <span class="muted count">{{ pluralize(movingCount, 'student') }} moving<template v-if="stayingCount"> · {{ stayingCount }} staying</template></span>
                <button type="button" class="btn btn-secondary" :disabled="submitting" @click="close">Cancel</button>
                <button type="submit" class="btn" :disabled="submitting || movingCount === 0">
                    {{ submitting ? 'Moving…' : (targetOption ? `Move ${movingCount} to ${targetOption.label}` : `Move ${movingCount}`) }}
                </button>
            </div>
        </form>
    </AppModal>
</template>

<style scoped>
.promote-form {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.intro,
.note {
    margin: 0;
}

.note {
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

/* The roster scrolls inside the dialog. */
.roster {
    max-height: 320px;
    overflow-y: auto;
    border: 1px solid var(--border);
    border-radius: var(--radius);
}

.roster .table {
    table-layout: fixed;
}

.col-destination {
    width: 46%;
}

.ellipsis {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.stays td {
    color: var(--muted);
}

.select-sm {
    padding-top: 4px;
    padding-bottom: 4px;
}

.actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
}

.actions .count {
    margin-right: auto;
    font-size: 0.9rem;
}
</style>
