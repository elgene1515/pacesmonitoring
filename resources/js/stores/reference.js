import { computed, ref } from 'vue';
import { defineStore } from 'pinia';
import { referenceService } from '../services';
import { GradeLevel } from '../models';
import { notify } from './notifications';

/**
 * The curriculum as the signed-in teacher sees it: the grade levels they
 * teach in, each with its subjects and the teacher's sections (cached for
 * the session, read-only; the admin maintains the curriculum).
 *
 *  gradeLevels                ref<GradeLevel[]>
 *  loaded / loading           ref<boolean>
 *  hasGradeLevels             computed<boolean>
 *  sections                   computed<Section[]>   every section the teacher teaches in (with gradeLevel set)
 *  hasSections                computed<boolean>
 *  load(force = false)        -> Promise<GradeLevel[]>  (toasts on failure, never throws)
 *  gradeLevelById(id)         -> GradeLevel|null
 *  gradeLevelName(id)         -> string ("" when unknown)
 *  gradeLevelsFor(schoolYearId) -> GradeLevel[]
 *  subjectsFor(gradeLevelId)  -> Subject[]            the grade level's subjects
 *  mySubjectsFor(sectionId)   -> Subject[]            the subjects the teacher handles in that section
 *  subjectName(subjectId)     -> string
 *  sectionById(id)            -> Section|null
 *  sectionsFor(gradeLevelId)  -> Section[]
 *  sectionsForYear(schoolYearId) -> Section[]
 *  teaches(sectionId, subjectId) -> boolean
 *  teachesSubject(subjectId) -> boolean             the teacher teaches it in some section (so its subject page opens)
 *  reset()                    forget everything (called when the session ends)
 */
export const useReferenceStore = defineStore('reference', () => {
    const gradeLevels = ref([]);
    const loaded = ref(false);
    const loading = ref(false);
    let inFlight = null;

    const hasGradeLevels = computed(() => gradeLevels.value.length > 0);

    /** Every section the teacher teaches in, with its grade level attached, in curriculum order. */
    const sections = computed(() => gradeLevels.value.flatMap((level) => level.sections.map((section) => {
        if (!section.gradeLevel) {
            section.gradeLevel = level;
        }

        return section;
    })));

    const hasSections = computed(() => sections.value.length > 0);

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
                gradeLevels.value.sort(GradeLevel.compare);
                loaded.value = true;
            } catch (err) {
                notify.error(err?.message ?? 'Failed to load your classes.');
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

    /** Grade levels registered under one school year (all of them when no id is given). */
    function gradeLevelsFor(schoolYearId) {
        if (schoolYearId === null || schoolYearId === undefined || schoolYearId === '') {
            return gradeLevels.value;
        }

        const numeric = Number(schoolYearId);
        return gradeLevels.value.filter((level) => level.schoolYearId === numeric);
    }

    function subjectsFor(gradeLevelId) {
        return gradeLevelById(gradeLevelId)?.subjects ?? [];
    }

    function sectionById(id) {
        const numeric = Number(id);
        return sections.value.find((section) => section.id === numeric) ?? null;
    }

    function sectionsFor(gradeLevelId) {
        return gradeLevelById(gradeLevelId)?.sections ?? [];
    }

    /** Sections under one school year (all of them when no id is given). */
    function sectionsForYear(schoolYearId) {
        if (schoolYearId === null || schoolYearId === undefined || schoolYearId === '') {
            return sections.value;
        }

        const numeric = Number(schoolYearId);
        return sections.value.filter((section) => section.gradeLevel?.schoolYearId === numeric);
    }

    /** The subjects the signed-in teacher handles in a section. */
    function mySubjectsFor(sectionId) {
        const section = sectionById(sectionId);

        if (!section) {
            return [];
        }

        const level = gradeLevelById(section.gradeLevelId);
        const ids = section.mySubjectIds ?? [];

        return (level?.subjects ?? []).filter((subject) => ids.includes(subject.id));
    }

    function teaches(sectionId, subjectId) {
        return sectionById(sectionId)?.teaches(subjectId) ?? false;
    }

    function teachesSubject(subjectId) {
        return sections.value.some((section) => section.teaches(subjectId));
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
        sections,
        hasSections,
        load,
        gradeLevelById,
        gradeLevelName,
        gradeLevelsFor,
        subjectsFor,
        mySubjectsFor,
        subjectName,
        sectionById,
        sectionsFor,
        sectionsForYear,
        teaches,
        teachesSubject,
        reset,
    };
});
