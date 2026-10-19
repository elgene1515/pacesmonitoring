import Subject from './Subject';
import SchoolYear from './SchoolYear';
import PaceAssignment from './PaceAssignment';

/**
 * Grade level created by the signed-in user under one school year, with its subjects.
 *
 * API shape: {id, name, sort_order, school_year_id, school_year?, students_count?, subjects_count?, subjects?: [],
 *             assignments_count?, tested_count?, issued_count?, assigned_count?, assignments?: []}
 */
export default class GradeLevel {
    constructor({
        id = null,
        userId = null,
        name = '',
        sortOrder = 0,
        schoolYearId = null,
        schoolYear = null,
        studentsCount = null,
        subjectsCount = null,
        subjects = [],
        assignmentsCount = null,
        testedCount = null,
        issuedCount = null,
        assignedCount = null,
        assignments = [],
        teacher = null,
    } = {}) {
        /** {id, name, email, branch_name}|null — the owning teacher, when the admin API included them. */
        this.teacher = teacher;
        /** Number of PACE records under all subjects, when the detail endpoint included the count. */
        this.assignmentsCount = assignmentsCount;
        /** Of assignmentsCount, how many are tested / issued-only / assigned-only. */
        this.testedCount = testedCount;
        this.issuedCount = issuedCount;
        this.assignedCount = assignedCount;
        /** @type {PaceAssignment[]} PACE records under all subjects (detail endpoint only). */
        this.assignments = assignments;
        this.id = id;
        /** The teacher who owns the grade level (admin pages). */
        this.userId = userId;
        this.name = name;
        this.sortOrder = sortOrder;
        /** The school year this grade level (and its subjects) is registered under. */
        this.schoolYearId = schoolYearId;
        /** @type {SchoolYear|null} */
        this.schoolYear = schoolYear;
        /** Number of enrolled students, when the API included the count; otherwise null. */
        this.studentsCount = studentsCount;
        /** Number of subjects, when the API included the count; otherwise null. */
        this.subjectsCount = subjectsCount;
        /** @type {Subject[]} */
        this.subjects = subjects;
    }

    /** Find one of this grade level's subjects by id (number or numeric string). */
    subjectById(subjectId) {
        return this.subjects.find((subject) => subject.id === Number(subjectId)) ?? null;
    }

    /** True when the record has been saved on the server. */
    get exists() {
        return this.id !== null && this.id !== undefined;
    }

    /** True when at least one student is enrolled (prevents deletion). */
    get hasStudents() {
        return Number(this.studentsCount ?? 0) > 0;
    }

    /** School year label ("2026-2027") when loaded, otherwise "". */
    get schoolYearName() {
        return this.schoolYear?.name ?? '';
    }

    get teacherName() {
        return this.teacher?.name ?? '';
    }

    /** The owning teacher's branch, when the admin API included it. */
    get teacherBranchName() {
        return this.teacher?.branch_name ?? '';
    }

    /** "Grade 1 · SY 2026-2027" (just the name when the school year is unknown). */
    get labelWithYear() {
        return this.schoolYearName ? `${this.name} · SY ${this.schoolYearName}` : this.name;
    }

    /** Snake-case body for POST /grade-levels and PUT /grade-levels/{id}. */
    toPayload() {
        const order = this.sortOrder;

        return {
            name: String(this.name ?? '').trim(),
            school_year_id: this.schoolYearId ? Number(this.schoolYearId) : null,
            sort_order: order === '' || order === null || order === undefined ? null : Number(order),
        };
    }

    /** Empty instance for "create" forms. */
    static blank(schoolYearId = null) {
        return new GradeLevel({ sortOrder: '', schoolYearId });
    }

    static fromJson(json) {
        if (!json) {
            return null;
        }

        const subjects = Subject.collection(json.subjects);

        return new GradeLevel({
            id: json.id ?? null,
            userId: json.user_id ?? null,
            name: json.name ?? '',
            sortOrder: Number(json.sort_order ?? 0),
            schoolYearId: json.school_year_id ?? null,
            schoolYear: SchoolYear.fromJson(json.school_year),
            studentsCount: json.students_count === undefined ? null : Number(json.students_count),
            subjectsCount: json.subjects_count === undefined
                ? (Array.isArray(json.subjects) ? subjects.length : null)
                : Number(json.subjects_count),
            subjects,
            assignmentsCount: json.assignments_count === undefined ? null : Number(json.assignments_count),
            testedCount: json.tested_count === undefined ? null : Number(json.tested_count),
            issuedCount: json.issued_count === undefined ? null : Number(json.issued_count),
            assignedCount: json.assigned_count === undefined ? null : Number(json.assigned_count),
            assignments: PaceAssignment.collection(json.assignments),
            teacher: json.teacher ?? null,
        });
    }

    /** Map an API array to GradeLevel[] sorted newest school year first, then sort_order, then name. */
    static collection(list) {
        return (Array.isArray(list) ? list : [])
            .map((item) => GradeLevel.fromJson(item))
            .filter(Boolean)
            .sort(GradeLevel.compare);
    }

    /** Display order: newest school year first, then sort_order, then name. */
    static compare(a, b) {
        return String(b.schoolYearName).localeCompare(String(a.schoolYearName))
            || a.sortOrder - b.sortOrder
            || String(a.name).localeCompare(String(b.name));
    }
}
