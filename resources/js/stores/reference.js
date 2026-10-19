import { computed, ref } from 'vue';
import { defineStore } from 'pinia';
import { referenceService } from '../services';
import { GradeLevel, Subject } from '../models';
import { notify } from './notifications';

/**
 * The signed-in user's grade levels + subjects (cached for the session).
 *
 *  gradeLevels                ref<GradeLevel[]>
 *  loaded / loading           ref<boolean>
 *  hasGradeLevels             computed<boolean>
 *  load(force = false)        -> Promise<GradeLevel[]>  (toasts on failure, never throws)
 *  gradeLevelById(id)         -> GradeLevel|null
 *  gradeLevelName(id)         -> string ("" when unknown)
 *  subjectsFor(gradeLevelId)  -> Subject[]
 *  subjectName(subjectId)     -> string
 *  createGradeLevel(payload)  -> Promise<GradeLevel>   (throws ApiError)
 *  updateGradeLevel(id, p)    -> Promise<GradeLevel>   (throws ApiError)
 *  removeGradeLevel(id)       -> Promise<void>         (throws ApiError)
 *  createSubject(payload)     -> Promise<Subject>      (throws ApiError)
 *  updateSubject(id, p)       -> Promise<Subject>      (throws ApiError)
 *  removeSubject(id)          -> Promise<void>         (throws ApiError)
 *  reset()                    forget everything (called when the session ends)
 */
export const useReferenceStore = defineStore('reference', () => {
    const gradeLevels = ref([]);
    const loaded = ref(false);
    const loading = ref(false);
    let inFlight = null;

    const hasGradeLevels = computed(() => gradeLevels.value.length > 0);

    function sortLevels() {
        gradeLevels.value.sort(GradeLevel.compare);
    }

    async function load(force = false) {
        if (loaded.value && !force) {
            return gradeLevels.value;
        }

        if (inFlight) {
            return inFlight;
        }

        loading.value = true;

        inFlight = (async () => {
            try {
                gradeLevels.value = await referenceService.gradeLevels();
                loaded.value = true;
            } catch (err) {
                notify.error(err?.message ?? 'Failed to load grade levels.');
            } finally {
                loading.value = false;
                inFlight = null;
            }

            return gradeLevels.value;
        })();

        return inFlight;
    }

    function gradeLevelById(id) {
        const numeric = Number(id);
        return gradeLevels.value.find((level) => level.id === numeric) ?? null;
    }

    function gradeLevelName(id) {
        return gradeLevelById(id)?.name ?? '';
    }

    function subjectsFor(gradeLevelId) {
        return gradeLevelById(gradeLevelId)?.subjects ?? [];
    }

    /** Grade levels registered under one school year (all of them when no id is given). */
    function gradeLevelsFor(schoolYearId) {
        if (schoolYearId === null || schoolYearId === undefined || schoolYearId === '') {
            return gradeLevels.value;
        }

        const numeric = Number(schoolYearId);
        return gradeLevels.value.filter((level) => level.schoolYearId === numeric);
    }

    function subjectName(subjectId) {
        const numeric = Number(subjectId);

        for (const level of gradeLevels.value) {
            const subject = level.subjects.find((item) => item.id === numeric);
            if (subject) {
                return subject.name;
            }
        }

        return '';
    }

    function upsertLevel(level) {
        const index = gradeLevels.value.findIndex((item) => item.id === level.id);

        if (index === -1) {
            gradeLevels.value.push(level);
        } else {
            gradeLevels.value.splice(index, 1, level);
        }

        sortLevels();
    }

    async function createGradeLevel(payload) {
        const level = await referenceService.createGradeLevel(payload);
        upsertLevel(level);
        loaded.value = true;
        return level;
    }

    async function updateGradeLevel(id, payload) {
        const level = await referenceService.updateGradeLevel(id, payload);
        upsertLevel(level);
        return level;
    }

    async function removeGradeLevel(id) {
        await referenceService.removeGradeLevel(id);
        const numeric = Number(id);
        gradeLevels.value = gradeLevels.value.filter((level) => level.id !== numeric);
    }

    /** Place a subject inside its grade level (insert or replace) and keep the subject order. */
    function upsertSubject(subject) {
        const level = gradeLevelById(subject.gradeLevelId);

        if (!level) {
            return;
        }

        const index = level.subjects.findIndex((item) => item.id === subject.id);

        if (index === -1) {
            level.subjects.push(subject);
            level.subjectsCount = level.subjects.length;
        } else {
            level.subjects.splice(index, 1, subject);
        }

        level.subjects.sort(Subject.compare);
    }

    async function createSubject(payload) {
        const subject = await referenceService.createSubject(payload);
        upsertSubject(subject);
        return subject;
    }

    async function updateSubject(id, payload) {
        const subject = await referenceService.updateSubject(id, payload);
        upsertSubject(subject);
        return subject;
    }

    async function removeSubject(id) {
        await referenceService.removeSubject(id);
        const numeric = Number(id);

        for (const level of gradeLevels.value) {
            const before = level.subjects.length;
            level.subjects = level.subjects.filter((subject) => subject.id !== numeric);

            if (level.subjects.length !== before) {
                level.subjectsCount = level.subjects.length;
            }
        }
    }

    function reset() {
        gradeLevels.value = [];
        loaded.value = false;
        loading.value = false;
        inFlight = null;
    }

    return {
        gradeLevels,
        loaded,
        loading,
        hasGradeLevels,
        load,
        gradeLevelById,
        gradeLevelName,
        subjectsFor,
        gradeLevelsFor,
        subjectName,
        createGradeLevel,
        updateGradeLevel,
        removeGradeLevel,
        createSubject,
        updateSubject,
        removeSubject,
        reset,
    };
});
