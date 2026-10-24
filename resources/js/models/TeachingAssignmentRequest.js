import Section from './Section';
import Subject from './Subject';

/** Mirrors App\Enums\TeachingAssignmentRequestStatus. */
export const TEACHING_REQUEST_STATUS_LABELS = Object.freeze({
    pending: 'Pending',
    accepted: 'Accepted',
    declined: 'Declined',
    cancelled: 'Withdrawn',
});

/**
 * A teacher's request to teach a subject to a section, decided by the school admin.
 *
 * API shape: {id, user_id, organization_id, section_id, subject_id, message, status, decline_reason,
 *             decided_by, decided_at, teacher?: {id, name, email, branch_name}, section?, subject?,
 *             decider?: {id, name}, created_at}
 */
export default class TeachingAssignmentRequest {
    constructor({
        id = null,
        userId = null,
        organizationId = null,
        sectionId = null,
        subjectId = null,
        message = '',
        status = 'pending',
        declineReason = '',
        decidedBy = null,
        decidedAt = null,
        teacher = null,
        section = null,
        subject = null,
        decider = null,
        createdAt = null,
    } = {}) {
        this.id = id;
        this.userId = userId;
        this.organizationId = organizationId;
        this.sectionId = sectionId;
        this.subjectId = subjectId;
        this.message = message;
        /** 'pending' | 'accepted' | 'declined' | 'cancelled' */
        this.status = status;
        this.declineReason = declineReason;
        this.decidedBy = decidedBy;
        this.decidedAt = decidedAt;
        /** {id, name, email, branch_name}|null */
        this.teacher = teacher;
        /** @type {Section|null} */
        this.section = section;
        /** @type {Subject|null} */
        this.subject = subject;
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
        return TEACHING_REQUEST_STATUS_LABELS[this.status] ?? this.status;
    }

    /** StatusBadge tone. */
    get tone() {
        if (this.isAccepted) {
            return 'ok';
        }

        if (this.isPending) {
            return 'warn';
        }

        return this.isDeclined ? 'bad' : 'muted';
    }

    get teacherName() {
        return this.teacher?.name ?? '';
    }

    get teacherBranchName() {
        return this.teacher?.branch_name ?? '';
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

    get deciderName() {
        return this.decider?.name ?? '';
    }

    static fromJson(json) {
        if (!json) {
            return null;
        }

        return new TeachingAssignmentRequest({
            id: json.id ?? null,
            userId: json.user_id ?? null,
            organizationId: json.organization_id ?? null,
            sectionId: json.section_id === null || json.section_id === undefined ? null : Number(json.section_id),
            subjectId: json.subject_id === null || json.subject_id === undefined ? null : Number(json.subject_id),
            message: json.message ?? '',
            status: json.status ?? 'pending',
            declineReason: json.decline_reason ?? '',
            decidedBy: json.decided_by ?? null,
            decidedAt: json.decided_at ?? null,
            teacher: json.teacher ?? null,
            section: Section.fromJson(json.section),
            subject: Subject.fromJson(json.subject),
            decider: json.decider ?? null,
            createdAt: json.created_at ?? null,
        });
    }

    static collection(list) {
        return (Array.isArray(list) ? list : []).map((item) => TeachingAssignmentRequest.fromJson(item)).filter(Boolean);
    }
}
