import GradeLevel from '../models/GradeLevel';
import Subject from '../models/Subject';
import Section from '../models/Section';

/**
 * The curriculum as a teacher sees it (read-only): the grade levels, sections
 * and subjects their teaching assignments cover, plus the whole branch
 * curriculum for finding open slots. Admins set the curriculum up through
 * AdminService.
 *
 *  curriculum(schoolYearId?)     GET /api/curriculum?school_year_id=       -> GradeLevel[] (the branch's, each with subjects and
 *                                                                              sections[teaching_assignments.teacher])
 *  gradeLevels(schoolYearId?)    GET /api/grade-levels?school_year_id=     -> GradeLevel[] (the ones the teacher teaches in; subjects, my sections)
 *  gradeLevel(id)                GET /api/grade-levels/{id}                -> GradeLevel (with subjects, sections, assignments[student, subject, pace])
 *  subjects(gradeLevelId?)       GET /api/subjects?grade_level_id=         -> Subject[] (the ones the teacher teaches, with their sections)
 *  subject(id)                   GET /api/subjects/{id}                    -> Subject (with gradeLevel, assignments[student, pace])
 *  sections(gradeLevelId?)       GET /api/sections?grade_level_id=         -> Section[] (the ones the teacher teaches in, with gradeLevel, teaching assignments)
 *  section(id)                   GET /api/sections/{id}                    -> Section (with gradeLevel.subjects, teaching assignments, students)
 */
export default class ReferenceService {
    /**
     * @param {import('../core/ApiClient').default} api
     */
    constructor(api) {
        this.api = api;
    }

    gradeLevelPath(id) {
        return `/grade-levels/${encodeURIComponent(id)}`;
    }

    subjectPath(id) {
        return `/subjects/${encodeURIComponent(id)}`;
    }

    sectionPath(id) {
        return `/sections/${encodeURIComponent(id)}`;
    }

    /** -> GradeLevel[] the whole branch curriculum, for browsing open slots */
    async curriculum(schoolYearId = null) {
        const body = await this.api.get('/curriculum', { school_year_id: schoolYearId });
        return GradeLevel.collection(body?.data);
    }

    /** -> GradeLevel[] (the ones the teacher teaches in, each with subjects and the teacher's sections) */
    async gradeLevels(schoolYearId = null) {
        const body = await this.api.get('/grade-levels', { school_year_id: schoolYearId });
        return GradeLevel.collection(body?.data);
    }

    /** -> GradeLevel (with its subjects, the teacher's sections and the PACE records filed under them) */
    async gradeLevel(id) {
        const body = await this.api.get(this.gradeLevelPath(id));
        return GradeLevel.fromJson(body?.data);
    }

    /** -> Subject[] */
    async subjects(gradeLevelId = null) {
        const body = await this.api.get('/subjects', { grade_level_id: gradeLevelId });
        return Subject.collection(body?.data);
    }

    /** -> Subject (with its grade level and every PACE record filed under it) */
    async subject(id) {
        const body = await this.api.get(this.subjectPath(id));
        return Subject.fromJson(body?.data);
    }

    /** -> Section[] the teacher's classes */
    async sections(gradeLevelId = null) {
        const body = await this.api.get('/sections', { grade_level_id: gradeLevelId });
        return Section.collection(body?.data);
    }

    /** -> Section (with its grade level and subjects, who teaches what, and the roster) */
    async section(id) {
        const body = await this.api.get(this.sectionPath(id));
        return Section.fromJson(body?.data);
    }
}
