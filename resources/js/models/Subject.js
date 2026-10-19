import GradeLevel from './GradeLevel';
import PaceAssignment from './PaceAssignment';

/**
 * Subject offered by a grade level.
 *
 * API shape: {id, grade_level_id, name, sort_order, assignments_count?, tested_count?,
 *             issued_count?, assigned_count?, grade_level?, assignments?}
 */
export default class Subject {
    constructor({
        id = null,
        gradeLevelId = null,
        name = '',
        sortOrder = 0,
        assignmentsCount = null,
        testedCount = null,
        issuedCount = null,
        assignedCount = null,
        gradeLevel = null,
        assignments = [],
    } = {}) {
        this.id = id;
        this.gradeLevelId = gradeLevelId;
        this.name = name;
        this.sortOrder = sortOrder;
        /** Number of PACE records filed under this subject, when the API included the count. */
        this.assignmentsCount = assignmentsCount;
        /** Of assignmentsCount, how many are tested / issued-only / assigned-only, when the API included the breakdown. */
        this.testedCount = testedCount;
        this.issuedCount = issuedCount;
        this.assignedCount = assignedCount;
        /** @type {GradeLevel|null} The grade level (with school year) when the detail endpoint included it. */
        this.gradeLevel = gradeLevel;
        /** @type {PaceAssignment[]} PACE records filed under this subject (detail endpoint only). */
        this.assignments = assignments;
    }

    /** Grade level name when loaded, otherwise "". */
    get gradeLevelName() {
        return this.gradeLevel?.name ?? '';
    }

    /** School year label ("2026-2027") when the grade level is loaded, otherwise "". */
    get schoolYearName() {
        return this.gradeLevel?.schoolYearName ?? '';
    }

    /** The teacher who owns the subject's grade level, when the admin API included them. */
    get teacher() {
        return this.gradeLevel?.teacher ?? null;
    }

    get teacherName() {
        return this.gradeLevel?.teacherName ?? '';
    }

    get teacherBranchName() {
        return this.gradeLevel?.teacherBranchName ?? '';
    }

    /** True when the record has been saved on the server. */
    get exists() {
        return this.id !== null && this.id !== undefined;
    }

    /** Snake-case body for POST /subjects and PUT /subjects/{id}. */
    toPayload() {
        const order = this.sortOrder;

        return {
            grade_level_id: this.gradeLevelId ? Number(this.gradeLevelId) : null,
            name: String(this.name ?? '').trim(),
            sort_order: order === '' || order === null || order === undefined ? null : Number(order),
        };
    }

    /** Empty instance for "create" forms under a grade level. */
    static blank(gradeLevelId = null) {
        return new Subject({ gradeLevelId, sortOrder: '' });
    }

    static fromJson(json) {
        if (!json) {
            return null;
        }

        return new Subject({
            id: json.id ?? null,
            gradeLevelId: json.grade_level_id ?? null,
            name: json.name ?? '',
            sortOrder: Number(json.sort_order ?? 0),
            assignmentsCount: json.assignments_count === undefined ? null : Number(json.assignments_count),
            testedCount: json.tested_count === undefined ? null : Number(json.tested_count),
            issuedCount: json.issued_count === undefined ? null : Number(json.issued_count),
            assignedCount: json.assigned_count === undefined ? null : Number(json.assigned_count),
            gradeLevel: GradeLevel.fromJson(json.grade_level),
            assignments: PaceAssignment.collection(json.assignments),
        });
    }

    /** Map an API array to Subject[] sorted by sort_order then name. */
    static collection(list) {
        return (Array.isArray(list) ? list : [])
            .map((item) => Subject.fromJson(item))
            .filter(Boolean)
            .sort(Subject.compare);
    }

    /** Display order: sort_order, then name. */
    static compare(a, b) {
        return a.sortOrder - b.sortOrder || String(a.name).localeCompare(String(b.name));
    }
}
