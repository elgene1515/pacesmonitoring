import Plan from './Plan';
import Subscription from './Subscription';

export const PLAN_REQUEST_ACTIONS = Object.freeze({
    CHANGE: 'change',
    CANCEL: 'cancel',
});

/**
 * A school admin's request to change (or cancel) the organization's plan.
 *
 * API shape: {id, organization_id, action, status, message, decision_note, plan_id, plan?,
 *             organization?: {id, name, code, current_plan_name}, requested_by?: {id, name, email},
 *             decided_by?: {id, name}, subscription?, decided_at, created_at}
 */
export default class PlanRequest {
    constructor({
        id = null,
        organizationId = null,
        action = PLAN_REQUEST_ACTIONS.CHANGE,
        status = 'pending',
        message = '',
        decisionNote = '',
        planId = null,
        plan = null,
        organization = null,
        requestedBy = null,
        decidedBy = null,
        subscription = null,
        decidedAt = null,
        createdAt = null,
    } = {}) {
        this.id = id;
        this.organizationId = organizationId;
        /** 'change' (to a plan) | 'cancel' */
        this.action = action;
        /** 'pending' | 'approved' | 'declined' | 'cancelled' */
        this.status = status;
        this.message = message;
        this.decisionNote = decisionNote;
        this.planId = planId;
        /** Plan|null — the plan asked for (null for a cancellation). */
        this.plan = plan;
        this.organization = organization;
        this.requestedBy = requestedBy;
        this.decidedBy = decidedBy;
        /** Subscription|null — the period recorded when the request was approved. */
        this.subscription = subscription;
        this.decidedAt = decidedAt;
        this.createdAt = createdAt;
    }

    get isPending() {
        return this.status === 'pending';
    }

    get isApproved() {
        return this.status === 'approved';
    }

    get isDeclined() {
        return this.status === 'declined';
    }

    get isCancellation() {
        return this.action === PLAN_REQUEST_ACTIONS.CANCEL;
    }

    get statusLabel() {
        return { pending: 'Pending', approved: 'Approved', declined: 'Declined', cancelled: 'Withdrawn' }[this.status] ?? this.status;
    }

    /** "Move to Growth" / "Cancel subscription" */
    get summary() {
        return this.isCancellation ? 'Cancel the subscription' : `Move to ${this.plan?.name ?? 'another plan'}`;
    }

    get organizationName() {
        return this.organization?.name ?? '';
    }

    get requesterName() {
        return this.requestedBy?.name ?? '';
    }

    get tone() {
        if (this.isApproved) {
            return 'ok';
        }

        if (this.isPending) {
            return 'warn';
        }

        return this.isDeclined ? 'bad' : 'muted';
    }

    static fromJson(json) {
        if (!json) {
            return null;
        }

        return new PlanRequest({
            id: json.id ?? null,
            organizationId: json.organization_id ?? null,
            action: json.action ?? PLAN_REQUEST_ACTIONS.CHANGE,
            status: json.status ?? 'pending',
            message: json.message ?? '',
            decisionNote: json.decision_note ?? '',
            planId: json.plan_id ?? null,
            plan: Plan.fromJson(json.plan),
            organization: json.organization ?? null,
            requestedBy: json.requested_by ?? null,
            decidedBy: json.decided_by ?? null,
            subscription: Subscription.fromJson(json.subscription),
            decidedAt: json.decided_at ?? null,
            createdAt: json.created_at ?? null,
        });
    }

    static collection(list) {
        return (Array.isArray(list) ? list : []).map((item) => PlanRequest.fromJson(item)).filter(Boolean);
    }
}
