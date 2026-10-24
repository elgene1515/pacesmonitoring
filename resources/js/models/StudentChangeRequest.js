import Student from './Student';
import { capitalize, formatDate } from '../core/format';
import { STUDENT_DETAIL_FIELDS } from './studentDetails';

/** Mirrors App\Enums\StudentChangeRequestStatus. */
export const CHANGE_REQUEST_STATUS_LABELS = Object.freeze({
    pending: 'Pending',
    accepted: 'Applied',
    declined: 'Declined',
    cancelled: 'Withdrawn',
});

/** The student fields a teacher may propose a change to, with their labels. */
export const CHANGEABLE_FIELDS = Object.freeze([
    { key: 'first_name', label: 'First name' },
    { key: 'middle_name', label: 'Middle name' },
    { key: 'last_name', label: 'Last name' },
    { key: 'extension', label: 'Extension' },
    { key: 'birth_date', label: 'Date of birth' },
    { key: 'sex', label: 'Sex' },
    ...STUDENT_DETAIL_FIELDS.map((field) => ({ key: field.key, label: field.label })),
]);

/**
 * A teacher's proposed edit to a student's details, applied once an admin accepts it.
 *
 * API shape: {id, student_id, organization_id, requested_by, changes: {field: value}, status, decline_reason,
 *             decided_by, decided_at, student?, requester?: {id, name, email, branch_name}, decider?: {id, name}, created_at}
 */
export default class StudentChangeRequest {
    constructor({
        id = null,
        studentId = null,
        organizationId = null,
        requestedBy = null,
        changes = {},
        status = 'pending',
        declineReason = '',
        decidedBy = null,
        decidedAt = null,
        student = null,
        requester = null,
        decider = null,
        createdAt = null,
    } = {}) {
        this.id = id;
        this.studentId = studentId;
        this.organizationId = organizationId;
        this.requestedBy = requestedBy;
        /** Only the fields being changed, snake_case keys. */
        this.changes = changes;
        /** 'pending' | 'accepted' | 'declined' | 'cancelled' */
        this.status = status;
        this.declineReason = declineReason;
        this.decidedBy = decidedBy;
        this.decidedAt = decidedAt;
        /** @type {Student|null} */
        this.student = student;
        /** {id, name, email, branch_name}|null */
        this.requester = requester;
        /** {id, name}|null */
        this.decider = decider;
        this.createdAt = createdAt;
    }

    get isPending() {
        return this.status === 'pending';
    }

    get isAccepted() {
        return this.status === 'accepted';
    }

    get isDeclined() {
        return this.status === 'declined';
    }

    get statusLabel() {
        return CHANGE_REQUEST_STATUS_LABELS[this.status] ?? this.status;
    }

    get tone() {
        if (this.isAccepted) {
            return 'ok';
        }

        if (this.isPending) {
            return 'warn';
        }

        return this.isDeclined ? 'bad' : 'muted';
    }

    get studentName() {
        return this.student?.displayName ?? '';
    }

    get requesterName() {
        return this.requester?.name ?? '';
    }

    get requesterBranchName() {
        return this.requester?.branch_name ?? '';
    }

    get deciderName() {
        return this.decider?.name ?? '';
    }

    /**
     * The proposed changes as display rows: [{ key, label, from, to }]. `from` is
     * the student's current value when the student is loaded (blank otherwise).
     */
    get rows() {
        const current = this.student;

        return CHANGEABLE_FIELDS
            .filter((field) => Object.prototype.hasOwnProperty.call(this.changes ?? {}, field.key))
            .map((field) => ({
                key: field.key,
                label: field.label,
                from: StudentChangeRequest.display(field.key, current ? StudentChangeRequest.currentValue(current, field.key) : null),
                to: StudentChangeRequest.display(field.key, this.changes[field.key]),
            }));
    }

    /** "First name, Date of birth" */
    get summary() {
        return this.rows.map((row) => row.label).join(', ');
    }

    static currentValue(student, key) {
        switch (key) {
            case 'first_name':
                return student.firstName;
            case 'middle_name':
                return student.middleName;
            case 'last_name':
                return student.lastName;
            case 'extension':
                return student.extension;
            case 'birth_date':
                return student.birthDate;
            case 'sex':
                return student.sex;
            default:
                return student[STUDENT_DETAIL_FIELDS.find((field) => field.key === key)?.prop] ?? null;
        }
    }

    static display(key, value) {
        if (value === null || value === undefined || value === '') {
            return '—';
        }

        if (key === 'birth_date') {
            return formatDate(value) || String(value);
        }

        if (key === 'sex') {
            return capitalize(value);
        }

        return String(value);
    }

    static fromJson(json) {
        if (!json) {
            return null;
        }

        return new StudentChangeRequest({
            id: json.id ?? null,
            studentId: json.student_id ?? null,
            organizationId: json.organization_id ?? null,
            requestedBy: json.requested_by ?? null,
            changes: json.changes && typeof json.changes === 'object' ? json.changes : {},
            status: json.status ?? 'pending',
            declineReason: json.decline_reason ?? '',
            decidedBy: json.decided_by ?? null,
            decidedAt: json.decided_at ?? null,
            student: Student.fromJson(json.student),
            requester: json.requester ?? null,
            decider: json.decider ?? null,
            createdAt: json.created_at ?? null,
        });
    }

    static collection(list) {
        return (Array.isArray(list) ? list : []).map((item) => StudentChangeRequest.fromJson(item)).filter(Boolean);
    }
}
