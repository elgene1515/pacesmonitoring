import GradeLevel from './GradeLevel';
import SchoolYear from './SchoolYear';
import PaceAssignment from './PaceAssignment';
import { ageFromBirthDate, capitalize } from '../core/format';

export const SEX_OPTIONS = Object.freeze([
    { value: 'male', label: 'Male' },
    { value: 'female', label: 'Female' },
]);

/**
 * Student record.
 *
 * API shape: {id, first_name, middle_name, last_name, extension, full_name, birth_date (Y-m-d), age, sex,
 *             grade_level_id, grade_level?, school_year_id, school_year?, assignments_count?, assignments?,
 *             creator?: {id, name, email}, created_at, updated_at}
 */
export default class Student {
    constructor({
        id = null,
        firstName = '',
        middleName = '',
        lastName = '',
        extension = '',
        birthDate = '',
        age = null,
        sex = '',
        gradeLevelId = null,
        gradeLevel = null,
        schoolYearId = null,
        schoolYear = null,
        assignmentsCount = null,
        testedCount = null,
        issuedCount = null,
        assignedCount = null,
        averageScore = null,
        assignments = [],
        creator = null,
        createdAt = null,
        updatedAt = null,
    } = {}) {
        /** Of assignmentsCount, how many are tested / issued-only / assigned-only, when the API included the breakdown. */
        this.testedCount = testedCount;
        this.issuedCount = issuedCount;
        this.assignedCount = assignedCount;
        /** Average score across every record (unscored PACEs as 0), when the API included it; null without records. */
        this.averageScore = averageScore;
        this.id = id;
        this.firstName = firstName;
        this.middleName = middleName;
        this.lastName = lastName;
        this.extension = extension;
        /** "YYYY-MM-DD" */
        this.birthDate = birthDate;
        this.age = age;
        /** 'male' | 'female' */
        this.sex = sex;
        this.gradeLevelId = gradeLevelId;
        /** @type {GradeLevel|null} */
        this.gradeLevel = gradeLevel;
        /** School year the student was registered in (null when none was recorded). */
        this.schoolYearId = schoolYearId;
        /** @type {SchoolYear|null} */
        this.schoolYear = schoolYear;
        /** Number of assignments, when the API included the count; otherwise null. */
        this.assignmentsCount = assignmentsCount;
        /** @type {PaceAssignment[]} */
        this.assignments = assignments;
        /** The teacher who registered this student (only present on admin endpoints). */
        this.creator = creator;
        this.createdAt = createdAt;
        this.updatedAt = updatedAt;
    }

    /** The owning teacher's name, when loaded (admin views only). */
    get creatorName() {
        return this.creator?.name ?? '';
    }

    /** The owning teacher's school and branch, when the API included them (admin pages). */
    get creatorOrganizationName() {
        return this.creator?.organization_name ?? '';
    }

    get creatorBranchName() {
        return this.creator?.branch_name ?? '';
    }

    /** "Last, First Middle Ext" */
    get fullName() {
        const rest = [this.firstName, this.middleName, this.extension].filter((part) => part && String(part).trim());
        return `${this.lastName}, ${rest.join(' ')}`.trim().replace(/,$/, '');
    }

    /** "First M. Last Ext" */
    get displayName() {
        const middleInitial = this.middleName && String(this.middleName).trim()
            ? `${String(this.middleName).trim().charAt(0).toUpperCase()}.`
            : '';

        return [this.firstName, middleInitial, this.lastName, this.extension]
            .filter((part) => part && String(part).trim())
            .join(' ');
    }

    /** "Male" / "Female" */
    get sexLabel() {
        return capitalize(this.sex);
    }

    /** Grade level name when loaded, otherwise "". */
    get gradeLevelName() {
        return this.gradeLevel?.name ?? '';
    }

    /** School year label ("2026-2027") when loaded, otherwise "". */
    get schoolYearName() {
        return this.schoolYear?.name ?? '';
    }

    /** True when the record has been saved on the server. */
    get exists() {
        return this.id !== null && this.id !== undefined;
    }

    /** Age derived from birthDate as of today (null when birthDate is empty). */
    get computedAge() {
        return ageFromBirthDate(this.birthDate);
    }

    /** Snake-case body for POST /students and PUT /students/{id}. */
    toPayload() {
        const clean = (value) => {
            const text = value === null || value === undefined ? '' : String(value).trim();
            return text === '' ? null : text;
        };

        return {
            first_name: String(this.firstName ?? '').trim(),
            middle_name: clean(this.middleName),
            last_name: String(this.lastName ?? '').trim(),
            extension: clean(this.extension),
            birth_date: this.birthDate || null,
            age: this.age === '' || this.age === null || this.age === undefined ? null : Number(this.age),
            sex: this.sex || null,
            grade_level_id: this.gradeLevelId ? Number(this.gradeLevelId) : null,
            school_year_id: this.schoolYearId ? Number(this.schoolYearId) : null,
        };
    }

    /** Empty instance for "create" forms. */
    static blank() {
        return new Student();
    }

    static fromJson(json) {
        if (!json) {
            return null;
        }

        return new Student({
            id: json.id ?? null,
            firstName: json.first_name ?? '',
            middleName: json.middle_name ?? '',
            lastName: json.last_name ?? '',
            extension: json.extension ?? '',
            birthDate: json.birth_date ? String(json.birth_date).slice(0, 10) : '',
            age: json.age === null || json.age === undefined ? null : Number(json.age),
            sex: json.sex ?? '',
            gradeLevelId: json.grade_level_id ?? null,
            gradeLevel: GradeLevel.fromJson(json.grade_level),
            schoolYearId: json.school_year_id ?? null,
            schoolYear: SchoolYear.fromJson(json.school_year),
            assignmentsCount: json.assignments_count === undefined ? null : Number(json.assignments_count),
            testedCount: json.tested_count === undefined ? null : Number(json.tested_count),
            issuedCount: json.issued_count === undefined ? null : Number(json.issued_count),
            assignedCount: json.assigned_count === undefined ? null : Number(json.assigned_count),
            averageScore: json.average_score === undefined || json.average_score === null ? null : Number(json.average_score),
            assignments: PaceAssignment.collection(json.assignments),
            creator: json.creator ?? null,
            createdAt: json.created_at ?? null,
            updatedAt: json.updated_at ?? null,
        });
    }

    static collection(list) {
        return (Array.isArray(list) ? list : []).map((item) => Student.fromJson(item)).filter(Boolean);
    }
}
