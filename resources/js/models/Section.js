import GradeLevel from './GradeLevel';
import TeachingAssignment from './TeachingAssignment';
import Student from './Student';

/**
 * A class under a grade level ("Grade 7 · Section 1"): the roster students
 * are enrolled in. Its subjects are the grade level's; who teaches each of
 * them to this section is a teaching assignment.
 *
 * API shape: {id, grade_level_id, name, sort_order, students_count?, grade_level?, subject_ids?: [] (the subjects it offers),
 *             teaching_assignments?: [], my_subject_ids?: [], students?: []}
 */
export default class Section {
    constructor({
        id = null,
        gradeLevelId = null,
        name = '',
        sortOrder = 0,
        studentsCount = null,
        gradeLevel = null,
        subjectIds = null,
        teachingAssignments = [],
        mySubjectIds = null,
        students = [],
    } = {}) {
        this.id = id;
        this.gradeLevelId = gradeLevelId;
        this.name = name;
        this.sortOrder = sortOrder;
        /** Number of enrolled students, when the API included the count; otherwise null. */
        this.studentsCount = studentsCount;
        /** @type {GradeLevel|null} */
        this.gradeLevel = gradeLevel;
        /** Ids of the grade level's subjects this section offers, or null when the API did not say (then all count). */
        this.subjectIds = subjectIds;
        /** @type {TeachingAssignment[]} who teaches what in this section, when loaded */
        this.teachingAssignments = teachingAssignments;
        /** Subject ids the signed-in teacher handles here (teacher endpoints), or null when unknown. */
        this.mySubjectIds = mySubjectIds;
        /** @type {Student[]} the roster (detail endpoint only) */
        this.students = students;
    }

    get exists() {
        return this.id !== null && this.id !== undefined;
    }

    get hasStudents() {
        return Number(this.studentsCount ?? this.students.length ?? 0) > 0;
    }

    get gradeLevelName() {
        return this.gradeLevel?.name ?? '';
    }

    /** School year label of the grade level ("2026-2027") when loaded, otherwise "". */
    get schoolYearName() {
        return this.gradeLevel?.schoolYearName ?? '';
    }

    get branchName() {
        return this.gradeLevel?.branchName ?? '';
    }

    /** The school the section belongs to, when the API carried it. */
    get organizationName() {
        return this.gradeLevel?.organizationName ?? '';
    }

    /** "Grade 1 · Section 1" (just the section name when the grade level is unknown). */
    get label() {
        return this.gradeLevelName ? `${this.gradeLevelName} · ${this.name}` : this.name;
    }

    /** "Grade 1 · Section 1 · SY 2026-2027" */
    get labelWithYear() {
        return this.schoolYearName ? `${this.label} · SY ${this.schoolYearName}` : this.label;
    }

    /** True when the section offers the subject (every subject counts while the list is unknown). */
    offers(subjectId) {
        return this.subjectIds === null || this.subjectIds.includes(Number(subjectId));
    }

    /** The grade level's subjects this section offers (needs the grade level with its subjects). */
    get offeredSubjects() {
        return (this.gradeLevel?.subjects ?? []).filter((subject) => this.offers(subject.id));
    }

    /** The teaching assignment for a subject, if anyone teaches it here. */
    assignmentFor(subjectId) {
        return this.teachingAssignments.find((item) => item.subjectId === Number(subjectId)) ?? null;
    }

    /** Who teaches a subject in this section ({id, name, email} or null). */
    teacherFor(subjectId) {
        return this.assignmentFor(subjectId)?.teacher ?? null;
    }

    /** True when the signed-in teacher (or the given teacher id) handles the subject here. */
    teaches(subjectId, teacherId = null) {
        if (teacherId !== null) {
            return this.assignmentFor(subjectId)?.teacherId === Number(teacherId);
        }

        return Array.isArray(this.mySubjectIds) && this.mySubjectIds.includes(Number(subjectId));
    }

    /** The distinct teachers of this section, by name. */
    get teachers() {
        const seen = new Map();

        for (const assignment of this.teachingAssignments) {
            if (assignment.teacher && !seen.has(assignment.teacher.id)) {
                seen.set(assignment.teacher.id, assignment.teacher);
            }
        }

        return [...seen.values()].sort((a, b) => String(a.name).localeCompare(String(b.name)));
    }

    /** Snake-case body for POST /admin/grade-levels/{id}/sections and PUT /admin/sections/{id}. */
    toPayload() {
        const order = this.sortOrder;

        return {
            name: String(this.name ?? '').trim(),
            sort_order: order === '' || order === null || order === undefined ? null : Number(order),
        };
    }

    static blank(gradeLevelId = null) {
        return new Section({ gradeLevelId, sortOrder: '' });
    }

    static fromJson(json) {
        if (!json) {
            return null;
        }

        return new Section({
            id: json.id ?? null,
            gradeLevelId: json.grade_level_id ?? null,
            name: json.name ?? '',
            sortOrder: Number(json.sort_order ?? 0),
            studentsCount: json.students_count === undefined ? null : Number(json.students_count),
            gradeLevel: GradeLevel.fromJson(json.grade_level),
            subjectIds: Array.isArray(json.subject_ids) ? json.subject_ids.map((id) => Number(id)) : null,
            teachingAssignments: TeachingAssignment.collection(json.teaching_assignments),
            mySubjectIds: Array.isArray(json.my_subject_ids) ? json.my_subject_ids.map((id) => Number(id)) : null,
            students: Student.collection(json.students),
        });
    }

    /** Map an API array to Section[] sorted by sort_order then name. */
    static collection(list) {
        return (Array.isArray(list) ? list : [])
            .map((item) => Section.fromJson(item))
            .filter(Boolean)
            .sort(Section.compare);
    }

    static compare(a, b) {
        return a.sortOrder - b.sortOrder || String(a.name).localeCompare(String(b.name));
    }
}
