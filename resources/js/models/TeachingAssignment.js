import Section from './Section';
import Subject from './Subject';

/**
 * One teacher teaching one subject to one section (no co-teaching).
 *
 * API shape: {id, section_id, subject_id, teacher_id, assigned_by, section?, subject?,
 *             teacher?: {id, name, email}, created_at}
 */
export default class TeachingAssignment {
    constructor({
        id = null,
        sectionId = null,
        subjectId = null,
        teacherId = null,
        assignedBy = null,
        section = null,
        subject = null,
        teacher = null,
        createdAt = null,
    } = {}) {
        this.id = id;
        this.sectionId = sectionId;
        this.subjectId = subjectId;
        this.teacherId = teacherId;
        /** The admin who made the assignment (null when it came from an accepted request). */
        this.assignedBy = assignedBy;
        /** @type {Section|null} */
        this.section = section;
        /** @type {Subject|null} */
        this.subject = subject;
        /** {id, name, email}|null */
        this.teacher = teacher;
        this.createdAt = createdAt;
    }

    get teacherName() {
        return this.teacher?.name ?? '';
    }

    get subjectName() {
        return this.subject?.name ?? '';
    }

    get sectionName() {
        return this.section?.name ?? '';
    }

    get gradeLevelName() {
        return this.section?.gradeLevelName ?? '';
    }

    get schoolYearName() {
        return this.section?.schoolYearName ?? '';
    }

    /** "Grade 1 · Section 1 · Math" */
    get label() {
        return [this.section?.label, this.subjectName].filter(Boolean).join(' · ');
    }

    static fromJson(json) {
        if (!json) {
            return null;
        }

        return new TeachingAssignment({
            id: json.id ?? null,
            sectionId: json.section_id === null || json.section_id === undefined ? null : Number(json.section_id),
            subjectId: json.subject_id === null || json.subject_id === undefined ? null : Number(json.subject_id),
            teacherId: json.teacher_id === null || json.teacher_id === undefined ? null : Number(json.teacher_id),
            assignedBy: json.assigned_by ?? null,
            section: Section.fromJson(json.section),
            subject: Subject.fromJson(json.subject),
            teacher: json.teacher ?? null,
            createdAt: json.created_at ?? null,
        });
    }

    static collection(list) {
        return (Array.isArray(list) ? list : []).map((item) => TeachingAssignment.fromJson(item)).filter(Boolean);
    }
}
