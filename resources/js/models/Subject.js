import GradeLevel from './GradeLevel';
import TeachingAssignment from './TeachingAssignment';
import PaceAssignment from './PaceAssignment';

/**
 * A subject of a grade level, shared by every section of that grade level.
 *
 * API shape: {id, grade_level_id, name, sort_order, assignments_count?, tested_count?,
 *             issued_count?, assigned_count?, grade_level?, teaching_assignments?, assignments?}
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
        teachingAssignments = [],
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
        /** @type {TeachingAssignment[]} who teaches this subject, per section, when loaded */
        this.teachingAssignments = teachingAssignments;
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

    get branchName() {
        return this.gradeLevel?.branchName ?? '';
    }

    /** The school the subject's grade level belongs to, when the API carried it. */
    get organizationName() {
        return this.gradeLevel?.organizationName ?? '';
    }

    /** The distinct teachers of this subject (across sections), by name. */
    get teachers() {
        const seen = new Map();

        for (const assignment of this.teachingAssignments) {
            if (assignment.teacher && !seen.has(assignment.teacher.id)) {
                seen.set(assignment.teacher.id, assignment.teacher);
            }
        }

        return [...seen.values()].sort((a, b) => String(a.name).localeCompare(String(b.name)));
    }

    /** "Ana Cruz, Ben Reyes" */
    get teacherNames() {
        return this.teachers.map((teacher) => teacher.name).join(', ');
    }

    /** True when the record has been saved on the server. */
    get exists() {
        return this.id !== null && this.id !== undefined;
    }

    /** Snake-case body for POST /admin/grade-levels/{id}/subjects and PUT /admin/subjects/{id}. */
    toPayload() {
        const order = this.sortOrder;

        return {
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
            teachingAssignments: TeachingAssignment.collection(json.teaching_assignments),
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
