import Pace from './Pace';

/** Mirrors App\Enums\PaceRequestStatus. */
export const PACE_REQUEST_STATUSES = Object.freeze({
    PENDING: 'pending',
    GIVEN: 'given',
    DECLINED: 'declined',
    CANCELLED: 'cancelled',
});

export const PACE_REQUEST_STATUS_LABELS = Object.freeze({
    pending: 'Pending',
    given: 'Given',
    declined: 'Declined',
    cancelled: 'Withdrawn',
});

/**
 * A teacher's request to their school admin for PACE booklets.
 *
 * API shape: {id, organization_id, branch_id, user_id, pace_id, pace_number, pace_name, quantity, needed_by, message,
 *             status, decision_note, teacher?: {id, name, email, branch?: {id, name}}, pace?: Pace,
 *             organization?: {id, name, code}, decided_by?: {id, name}, decided_at, created_at, updated_at}
 */
export default class PaceRequest {
    constructor({
        id = null,
        organizationId = null,
        branchId = null,
        userId = null,
        paceId = null,
        paceNumber = '',
        paceName = '',
        quantity = 1,
        neededBy = null,
        message = '',
        status = PACE_REQUEST_STATUSES.PENDING,
        decisionNote = '',
        teacher = null,
        pace = null,
        organization = null,
        decidedBy = null,
        decidedAt = null,
        createdAt = null,
        updatedAt = null,
    } = {}) {
        this.id = id;
        this.organizationId = organizationId;
        /** The branch whose catalog and pool the request is about. */
        this.branchId = branchId;
        this.userId = userId;
        /** The catalog PACE this is for, or null when the teacher named one not in the catalog yet. */
        this.paceId = paceId;
        this.paceNumber = paceNumber;
        this.paceName = paceName;
        /** Booklets asked for (or, once given, handed over). */
        this.quantity = quantity;
        /** "YYYY-MM-DD" | null */
        this.neededBy = neededBy;
        /** The teacher's note. */
        this.message = message;
        /** 'pending' | 'given' | 'declined' | 'cancelled' */
        this.status = status;
        /** The admin's note on the decision. */
        this.decisionNote = decisionNote;
        /** {id, name, email, branch?}|null */
        this.teacher = teacher;
        /** @type {Pace|null} */
        this.pace = pace;
        /** {id, name, code}|null */
        this.organization = organization;
        /** {id, name}|null */
        this.decidedBy = decidedBy;
        this.decidedAt = decidedAt;
        this.createdAt = createdAt;
        this.updatedAt = updatedAt;
    }

    get isPending() {
        return this.status === PACE_REQUEST_STATUSES.PENDING;
    }

    get isGiven() {
        return this.status === PACE_REQUEST_STATUSES.GIVEN;
    }

    get isDeclined() {
        return this.status === PACE_REQUEST_STATUSES.DECLINED;
    }

    get isCancelled() {
        return this.status === PACE_REQUEST_STATUSES.CANCELLED;
    }

    /** True while the request points at a PACE already in the branch's catalog. */
    get isLinked() {
        return this.paceId !== null && this.paceId !== undefined;
    }

    get statusLabel() {
        return PACE_REQUEST_STATUS_LABELS[this.status] ?? this.status;
    }

    /** StatusBadge tone. */
    get tone() {
        if (this.isGiven) {
            return 'ok';
        }

        if (this.isPending) {
            return 'warn';
        }

        return this.isDeclined ? 'bad' : 'muted';
    }

    /** "#1001 · Math" */
    get label() {
        return `#${this.paceNumber} · ${this.paceName}`;
    }

    get teacherName() {
        return this.teacher?.name ?? '';
    }

    get teacherEmail() {
        return this.teacher?.email ?? '';
    }

    get teacherBranch() {
        return this.teacher?.branch?.name ?? '';
    }

    get organizationName() {
        return this.organization?.name ?? '';
    }

    get deciderName() {
        return this.decidedBy?.name ?? '';
    }

    /** Snake-case body for POST / PUT /pace-requests. */
    toPayload() {
        return {
            pace_id: this.paceId ? Number(this.paceId) : null,
            pace_number: String(this.paceNumber ?? '').trim(),
            pace_name: String(this.paceName ?? '').trim(),
            quantity: Math.max(1, Math.trunc(Number(this.quantity) || 1)),
            needed_by: this.neededBy || null,
            message: String(this.message ?? '').trim() || null,
        };
    }

    static fromJson(json) {
        if (!json) {
            return null;
        }

        return new PaceRequest({
            id: json.id ?? null,
            organizationId: json.organization_id ?? null,
            branchId: json.branch_id ?? null,
            userId: json.user_id ?? null,
            paceId: json.pace_id ?? null,
            paceNumber: json.pace_number ?? '',
            paceName: json.pace_name ?? '',
            quantity: Number(json.quantity ?? 1),
            neededBy: json.needed_by ? String(json.needed_by).slice(0, 10) : null,
            message: json.message ?? '',
            status: json.status ?? PACE_REQUEST_STATUSES.PENDING,
            decisionNote: json.decision_note ?? '',
            teacher: json.teacher ?? null,
            pace: Pace.fromJson(json.pace),
            organization: json.organization ?? null,
            decidedBy: json.decided_by ?? null,
            decidedAt: json.decided_at ?? null,
            createdAt: json.created_at ?? null,
            updatedAt: json.updated_at ?? null,
        });
    }

    static collection(list) {
        return (Array.isArray(list) ? list : []).map((item) => PaceRequest.fromJson(item)).filter(Boolean);
    }
}
