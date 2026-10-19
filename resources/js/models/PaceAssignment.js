import Pace from './Pace';
import Subject from './Subject';
import Student from './Student';
import { quarterLabel } from '../core/schoolYear';

/** Minimum score considered a pass. */
export const PASSING_SCORE = 80;

/**
 * A PACE assigned to a student for a subject / school year / quarter.
 *
 * API shape: {id, student_id, subject_id, pace_id, school_year, quarter, issued_date, date_tested,
 *             score, remarks, retake, pace?, subject?, student?, created_at, updated_at}
 */
export default class PaceAssignment {
    constructor({
        id = null,
        studentId = null,
        subjectId = null,
        paceId = null,
        schoolYear = '',
        quarter = null,
        issuedDate = null,
        dateTested = null,
        score = null,
        remarks = '',
        retake = false,
        pace = null,
        subject = null,
        student = null,
        createdAt = null,
        updatedAt = null,
    } = {}) {
        /** True when the student is redoing this PACE. */
        this.retake = Boolean(retake);
        this.id = id;
        this.studentId = studentId;
        this.subjectId = subjectId;
        this.paceId = paceId;
        /** "YYYY-YYYY" */
        this.schoolYear = schoolYear;
        /** 1..4 */
        this.quarter = quarter;
        /** "YYYY-MM-DD" | null */
        this.issuedDate = issuedDate;
        /** "YYYY-MM-DD" | null */
        this.dateTested = dateTested;
        /** number | null */
        this.score = score;
        this.remarks = remarks;
        /** @type {Pace|null} */
        this.pace = pace;
        /** @type {Subject|null} */
        this.subject = subject;
        /** @type {Student|null} */
        this.student = student;
        this.createdAt = createdAt;
        this.updatedAt = updatedAt;
    }

    /** 'tested' when a score or test date exists, 'issued' when issued, otherwise 'assigned'. */
    get status() {
        if (this.dateTested || (this.score !== null && this.score !== undefined)) {
            return 'tested';
        }

        if (this.issuedDate) {
            return 'issued';
        }

        return 'assigned';
    }

    get isTested() {
        return this.status === 'tested';
    }

    /** True when a score exists and is >= 80. */
    get isPassed() {
        return this.score !== null && this.score !== undefined && Number(this.score) >= PASSING_SCORE;
    }

    /** "1st Quarter" etc. */
    get quarterLabel() {
        return quarterLabel(this.quarter);
    }

    /** PACE number when the pace relation is loaded, otherwise "". */
    get paceNumber() {
        return this.pace?.number ?? '';
    }

    /** PACE name when the pace relation is loaded, otherwise "". */
    get paceName() {
        return this.pace?.name ?? '';
    }

    /** Subject name when loaded, otherwise "". */
    get subjectName() {
        return this.subject?.name ?? '';
    }

    /** True when the record has been saved on the server. */
    get exists() {
        return this.id !== null && this.id !== undefined;
    }

    /**
     * Snake-case body for PUT /assignments/{id}. Every field is included; empty values become null.
     */
    toPayload() {
        const orNull = (value) => (value === '' || value === undefined ? null : value);
        const text = (value) => {
            const clean = value === null || value === undefined ? '' : String(value).trim();
            return clean === '' ? null : clean;
        };

        return {
            pace_id: this.paceId ? Number(this.paceId) : null,
            subject_id: this.subjectId ? Number(this.subjectId) : null,
            school_year: this.schoolYear || null,
            quarter: this.quarter ? Number(this.quarter) : null,
            issued_date: orNull(this.issuedDate),
            date_tested: orNull(this.dateTested),
            score: this.score === '' || this.score === null || this.score === undefined ? null : Number(this.score),
            remarks: text(this.remarks),
            retake: Boolean(this.retake),
        };
    }

    /** Shallow copy (handy for inline-edit drafts). */
    clone() {
        return new PaceAssignment({ ...this });
    }

    static fromJson(json) {
        if (!json) {
            return null;
        }

        return new PaceAssignment({
            id: json.id ?? null,
            studentId: json.student_id ?? null,
            subjectId: json.subject_id ?? null,
            paceId: json.pace_id ?? null,
            schoolYear: json.school_year ?? '',
            quarter: json.quarter === null || json.quarter === undefined ? null : Number(json.quarter),
            issuedDate: json.issued_date ? String(json.issued_date).slice(0, 10) : null,
            dateTested: json.date_tested ? String(json.date_tested).slice(0, 10) : null,
            score: json.score === null || json.score === undefined || json.score === '' ? null : Number(json.score),
            remarks: json.remarks ?? '',
            retake: Boolean(json.retake),
            pace: Pace.fromJson(json.pace),
            subject: Subject.fromJson(json.subject),
            student: Student.fromJson(json.student),
            createdAt: json.created_at ?? null,
            updatedAt: json.updated_at ?? null,
        });
    }

    static collection(list) {
        return (Array.isArray(list) ? list : []).map((item) => PaceAssignment.fromJson(item)).filter(Boolean);
    }

    /**
     * Average score across every record in the list, with unscored (assigned /
     * issued) PACEs counting as 0; null for an empty list.
     */
    static averageAll(assignments = []) {
        if (assignments.length === 0) {
            return null;
        }

        const sum = assignments.reduce((carry, item) => carry + (Number(item.score) || 0), 0);

        return sum / assignments.length;
    }

    /**
     * Aggregate a list: { total, tested, average, averageAll } where average covers
     * only scored records (null when nothing has a score) and averageAll covers every
     * record with unscored PACEs as 0 (null for an empty list).
     */
    static summarize(assignments = []) {
        const scored = assignments.filter((item) => item.score !== null && item.score !== undefined);
        const tested = assignments.filter((item) => item.isTested).length;
        const average = scored.length
            ? scored.reduce((sum, item) => sum + Number(item.score), 0) / scored.length
            : null;

        return { total: assignments.length, tested, average, averageAll: PaceAssignment.averageAll(assignments) };
    }
}
