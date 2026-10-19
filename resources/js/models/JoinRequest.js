/**
 * A teacher's request to join an organization's branch.
 *
 * API shape: {id, status, message, decline_reason, user_id, organization_id, branch_id,
 *             user?: {id, name, email, created_at}, organization?: {id, name, code}, branch?: {id, name, code},
 *             decided_by?: {id, name}, decided_at, created_at}
 */
export default class JoinRequest {
    constructor({
        id = null,
        status = 'pending',
        message = '',
        declineReason = '',
        userId = null,
        organizationId = null,
        branchId = null,
        user = null,
        organization = null,
        branch = null,
        decidedBy = null,
        decidedAt = null,
        createdAt = null,
    } = {}) {
        this.id = id;
        /** 'pending' | 'accepted' | 'declined' | 'cancelled' */
        this.status = status;
        this.message = message;
        this.declineReason = declineReason;
        this.userId = userId;
        this.organizationId = organizationId;
        this.branchId = branchId;
        this.user = user;
        this.organization = organization;
        this.branch = branch;
        this.decidedBy = decidedBy;
        this.decidedAt = decidedAt;
        this.createdAt = createdAt;
    }

    get isPending() {
        return this.status === 'pending';
    }

    get isDeclined() {
        return this.status === 'declined';
    }

    get isAccepted() {
        return this.status === 'accepted';
    }

    get statusLabel() {
        return { pending: 'Pending', accepted: 'Accepted', declined: 'Declined', cancelled: 'Cancelled' }[this.status] ?? this.status;
    }

    get userName() {
        return this.user?.name ?? '';
    }

    get organizationName() {
        return this.organization?.name ?? '';
    }

    get branchName() {
        return this.branch?.name ?? '';
    }

    static fromJson(json) {
        if (!json) {
            return null;
        }

        return new JoinRequest({
            id: json.id ?? null,
            status: json.status ?? 'pending',
            message: json.message ?? '',
            declineReason: json.decline_reason ?? '',
            userId: json.user_id ?? null,
            organizationId: json.organization_id ?? null,
            branchId: json.branch_id ?? null,
            user: json.user ?? null,
            organization: json.organization ?? null,
            branch: json.branch ?? null,
            decidedBy: json.decided_by ?? null,
            decidedAt: json.decided_at ?? null,
            createdAt: json.created_at ?? null,
        });
    }

    static collection(list) {
        return (Array.isArray(list) ? list : []).map((item) => JoinRequest.fromJson(item)).filter(Boolean);
    }
}
