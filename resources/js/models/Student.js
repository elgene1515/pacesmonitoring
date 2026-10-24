import GradeLevel from './GradeLevel';
import Section from './Section';
import SchoolYear from './SchoolYear';
import PaceAssignment from './PaceAssignment';
import Subject from './Subject';
import StudentEnrollment from './StudentEnrollment';
import { STUDENT_DETAIL_FIELDS, cleanDetail } from './studentDetails';
import { ageFromBirthDate, capitalize } from '../core/format';

export const SEX_OPTIONS = Object.freeze([
    { value: 'male', label: 'Male' },
    { value: 'female', label: 'Female' },
]);

/**
 * A student enrolled in one section, shared by every teacher of that section.
 *
 * API shape: {id, student_number, first_name, middle_name, last_name, extension, full_name, birth_date (Y-m-d), age, sex,
 *             email, mobile, address, guardian_name, guardian_relationship, guardian_phone, guardian_email,
 *             blood_type, medical_notes,
 *             section_id, section?, grade_level_id, grade_level?, school_year_id, school_year?, subjects?,
 *             assignments_count?, tested_count?, issued_count?, assigned_count?, average_score?, assignments?,
 *             enrollments?: [], registered_by?: {id, name, email}, created_at, updated_at}
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
        sectionId = null,
        section = null,
        gradeLevelId = null,
        gradeLevel = null,
        schoolYearId = null,
        schoolYear = null,
        subjects = null,
        assignmentsCount = null,
        testedCount = null,
        issuedCount = null,
        assignedCount = null,
        averageScore = null,
        assignments = [],
        enrollments = [],
        registeredBy = null,
        createdAt = null,
        updatedAt = null,
        ...details
    } = {}) {
        this.id = id;
        /*
         * Optional details (see studentDetails.js): studentNumber, email, mobile, address,
         * guardianName, guardianRelationship, guardianPhone, guardianEmail, bloodType, medicalNotes ('' when unset).
         */
        for (const field of STUDENT_DETAIL_FIELDS) {
            this[field.prop] = details[field.prop] ?? '';
        }

        this.firstName = firstName;
        this.middleName = middleName;
        this.lastName = lastName;
        this.extension = extension;
        /** "YYYY-MM-DD" */
        this.birthDate = birthDate;
        this.age = age;
        /** 'male' | 'female' */
        this.sex = sex;
        /** The section (class) the student is enrolled in. */
        this.sectionId = sectionId;
        /** @type {Section|null} */
        this.section = section;
        /** The section's grade level. */
        this.gradeLevelId = gradeLevelId;
        /** @type {GradeLevel|null} */
        this.gradeLevel = gradeLevel;
        /** School year the student was registered in (the section's grade level's). */
        this.schoolYearId = schoolYearId;
        /** @type {SchoolYear|null} */
        this.schoolYear = schoolYear;
        /** @type {Subject[]|null} the subjects the student takes (each student has their own list); null when not loaded */
        this.subjects = subjects;
        /** Number of records in the enrolled school year, when the API included the count; otherwise null. */
        this.assignmentsCount = assignmentsCount;
        /** Of assignmentsCount, how many are tested / issued-only / assigned-only. */
        this.testedCount = testedCount;
        this.issuedCount = issuedCount;
        this.assignedCount = assignedCount;
        /** Average score across every record of the enrolled year (unscored PACEs as 0); null without records. */
        this.averageScore = averageScore;
        /** @type {StudentEnrollment[]} where the student has been enrolled, latest first (detail endpoint) */
        this.enrollments = enrollments;
        /** @type {PaceAssignment[]} */
        this.assignments = assignments;
        /** Who registered the student ({id, name, email}), an audit detail (admin endpoints). */
        this.registeredBy = registeredBy;
        this.createdAt = createdAt;
        this.updatedAt = updatedAt;
    }

    /** Ids of the subjects the student takes (empty when the list is not loaded). */
    get subjectIds() {
        return (this.subjects ?? []).map((subject) => subject.id);
    }

    /** True when the student takes the subject. */
    takes(subjectId) {
        return (this.subjects ?? []).some((subject) => subject.id === Number(subjectId));
    }

    /** "English, Math, Filipino" */
    get subjectNames() {
        return (this.subjects ?? []).map((subject) => subject.name).join(', ');
    }

    get registeredByName() {
        return this.registeredBy?.name ?? '';
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

    get sectionName() {
        return this.section?.name ?? '';
    }

    /** Grade level name when loaded, otherwise "". */
    get gradeLevelName() {
        return this.gradeLevel?.name ?? this.section?.gradeLevelName ?? '';
    }

    /** "Grade 1 · Section 1" */
    get classLabel() {
        return [this.gradeLevelName, this.sectionName].filter(Boolean).join(' · ');
    }

    get branchName() {
        return this.section?.branchName ?? this.gradeLevel?.branchName ?? '';
    }

    /** The school the student's class belongs to, when the API carried it. */
    get organizationName() {
        return this.section?.organizationName ?? this.gradeLevel?.organizationName ?? '';
    }

    /** School year label ("2026-2027") when loaded, otherwise "". */
    get schoolYearName() {
        return this.schoolYear?.name ?? '';
    }

    /** The teachers of the student's section (from the loaded section), by name. */
    get teachers() {
        return this.section?.teachers ?? [];
    }

    /** True when the record has been saved on the server. */
    get exists() {
        return this.id !== null && this.id !== undefined;
    }

    /** Age derived from birthDate as of today (null when birthDate is empty). */
    get computedAge() {
        return ageFromBirthDate(this.birthDate);
    }

    /** Snake-case body for POST /students, POST /admin/students and PUT /admin/students/{id}. */
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
            ...Object.fromEntries(STUDENT_DETAIL_FIELDS.map((field) => [field.key, cleanDetail(this[field.prop])])),
            section_id: this.sectionId ? Number(this.sectionId) : null,
            // Only sent when the list was chosen (null/absent = every subject of the grade level, or keep as is).
            ...(this.subjects === null ? {} : { subject_ids: this.subjectIds }),
        };
    }

    /** Empty instance for "create" forms. */
    static blank(sectionId = null) {
        return new Student({ sectionId });
    }

    static fromJson(json) {
        if (!json) {
            return null;
        }

        const section = Section.fromJson(json.section);

        return new Student({
            id: json.id ?? null,
            firstName: json.first_name ?? '',
            middleName: json.middle_name ?? '',
            lastName: json.last_name ?? '',
            extension: json.extension ?? '',
            birthDate: json.birth_date ? String(json.birth_date).slice(0, 10) : '',
            age: json.age === null || json.age === undefined ? null : Number(json.age),
            sex: json.sex ?? '',
            sectionId: json.section_id ?? null,
            section,
            gradeLevelId: json.grade_level_id ?? section?.gradeLevelId ?? null,
            gradeLevel: GradeLevel.fromJson(json.grade_level) ?? section?.gradeLevel ?? null,
            schoolYearId: json.school_year_id ?? null,
            schoolYear: SchoolYear.fromJson(json.school_year),
            subjects: Array.isArray(json.subjects) ? Subject.collection(json.subjects) : null,
            assignmentsCount: json.assignments_count === undefined ? null : Number(json.assignments_count),
            testedCount: json.tested_count === undefined ? null : Number(json.tested_count),
            issuedCount: json.issued_count === undefined ? null : Number(json.issued_count),
            assignedCount: json.assigned_count === undefined ? null : Number(json.assigned_count),
            averageScore: json.average_score === undefined || json.average_score === null ? null : Number(json.average_score),
            assignments: PaceAssignment.collection(json.assignments),
            enrollments: StudentEnrollment.collection(json.enrollments),
            registeredBy: json.registered_by ?? null,
            createdAt: json.created_at ?? null,
            updatedAt: json.updated_at ?? null,
            ...Object.fromEntries(STUDENT_DETAIL_FIELDS.map((field) => [field.prop, json[field.key] ?? ''])),
        });
    }

    static collection(list) {
        return (Array.isArray(list) ? list : []).map((item) => Student.fromJson(item)).filter(Boolean);
    }
}
