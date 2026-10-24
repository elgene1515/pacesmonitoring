import Subject from './Subject';
import Section from './Section';
import SchoolYear from './SchoolYear';
import PaceAssignment from './PaceAssignment';

/**
 * A grade level of a branch under one school year, with its subjects and
 * sections. Admins set it up; teachers see the grade levels they teach in.
 *
 * API shape: {id, branch_id, school_year_id, created_by, name, sort_order, school_year?, branch?: {id, name, code},
 *             students_count?, subjects_count?, sections_count?, subjects?: [], sections?: [],
 *             assignments_count?, tested_count?, issued_count?, assigned_count?, assignments?: []}
 */
export default class GradeLevel {
    constructor({
        id = null,
        branchId = null,
        createdBy = null,
        name = '',
        sortOrder = 0,
        schoolYearId = null,
        schoolYear = null,
        branch = null,
        studentsCount = null,
        subjectsCount = null,
        sectionsCount = null,
        subjects = [],
        sections = [],
        assignmentsCount = null,
        testedCount = null,
        issuedCount = null,
        assignedCount = null,
        assignments = [],
    } = {}) {
        this.id = id;
        /** The branch (campus) the grade level belongs to. */
        this.branchId = branchId;
        /** Who set it up (an audit detail). */
        this.createdBy = createdBy;
        this.name = name;
        this.sortOrder = sortOrder;
        /** The school year this grade level (and its subjects) is registered under. */
        this.schoolYearId = schoolYearId;
        /** @type {SchoolYear|null} */
        this.schoolYear = schoolYear;
        /** {id, name, code}|null */
        this.branch = branch;
        /** Number of enrolled students (across every section), when the API included the count. */
        this.studentsCount = studentsCount;
        this.subjectsCount = subjectsCount;
        this.sectionsCount = sectionsCount;
        /** @type {Subject[]} */
        this.subjects = subjects;
        /** @type {Section[]} every section (admin) or the ones the teacher teaches in (teacher endpoints) */
        this.sections = sections;
        /** Number of PACE records under all subjects, when the detail endpoint included the count. */
        this.assignmentsCount = assignmentsCount;
        /** Of assignmentsCount, how many are tested / issued-only / assigned-only. */
        this.testedCount = testedCount;
        this.issuedCount = issuedCount;
        this.assignedCount = assignedCount;
        /** @type {PaceAssignment[]} PACE records under all subjects (detail endpoint only). */
        this.assignments = assignments;
    }

    /** Find one of this grade level's subjects by id (number or numeric string). */
    subjectById(subjectId) {
        return this.subjects.find((subject) => subject.id === Number(subjectId)) ?? null;
    }

    /** Find one of this grade level's sections by id. */
    sectionById(sectionId) {
        return this.sections.find((section) => section.id === Number(sectionId)) ?? null;
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

    get branchName() {
        return this.branch?.name ?? '';
    }

    /** The school (organization) of the branch, when the API carried it. */
    get organizationName() {
        return this.branch?.organization?.name ?? '';
    }

    /** "Grade 1 · SY 2026-2027" (just the name when the school year is unknown). */
    get labelWithYear() {
        return this.schoolYearName ? `${this.name} · SY ${this.schoolYearName}` : this.name;
    }

    /** "Grade 1 · North · SY 2026-2027" */
    get labelWithBranch() {
        return [this.name, this.branchName, this.schoolYearName ? `SY ${this.schoolYearName}` : ''].filter(Boolean).join(' · ');
    }

    /**
     * Snake-case body for POST /admin/grade-levels (with branch_id) and
     * PUT /admin/grade-levels/{id} (name and sort order only).
     */
    toPayload() {
        const order = this.sortOrder;

        return {
            branch_id: this.branchId ? Number(this.branchId) : null,
            name: String(this.name ?? '').trim(),
            school_year_id: this.schoolYearId ? Number(this.schoolYearId) : null,
            sort_order: order === '' || order === null || order === undefined ? null : Number(order),
        };
    }

    /** Empty instance for "create" forms. */
    static blank(schoolYearId = null, branchId = null) {
        return new GradeLevel({ sortOrder: '', schoolYearId, branchId });
    }

    static fromJson(json) {
        if (!json) {
            return null;
        }

        const subjects = Subject.collection(json.subjects);
        const sections = Section.collection(json.sections);

        return new GradeLevel({
            id: json.id ?? null,
            branchId: json.branch_id ?? null,
            createdBy: json.created_by ?? null,
            name: json.name ?? '',
            sortOrder: Number(json.sort_order ?? 0),
            schoolYearId: json.school_year_id ?? null,
            schoolYear: SchoolYear.fromJson(json.school_year),
            branch: json.branch ?? null,
            studentsCount: json.students_count === undefined ? null : Number(json.students_count),
            subjectsCount: json.subjects_count === undefined
                ? (Array.isArray(json.subjects) ? subjects.length : null)
                : Number(json.subjects_count),
            sectionsCount: json.sections_count === undefined
                ? (Array.isArray(json.sections) ? sections.length : null)
                : Number(json.sections_count),
            subjects,
            sections,
            assignmentsCount: json.assignments_count === undefined ? null : Number(json.assignments_count),
            testedCount: json.tested_count === undefined ? null : Number(json.tested_count),
            issuedCount: json.issued_count === undefined ? null : Number(json.issued_count),
            assignedCount: json.assigned_count === undefined ? null : Number(json.assigned_count),
            assignments: PaceAssignment.collection(json.assignments),
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
