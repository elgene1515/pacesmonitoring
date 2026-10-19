import Plan from './Plan';

/** Subscription statuses (mirrors App\Enums\SubscriptionStatus) plus 'none'. */
export const SUBSCRIPTION_STATUSES = Object.freeze([
    { value: 'trial', label: 'Trial' },
    { value: 'active', label: 'Active' },
    { value: 'past_due', label: 'Past due' },
    { value: 'expired', label: 'Expired' },
    { value: 'cancelled', label: 'Cancelled' },
]);

export const STATUS_LABELS = Object.freeze({
    trial: 'Trial',
    active: 'Active',
    past_due: 'Past due',
    expired: 'Expired',
    cancelled: 'Cancelled',
    none: 'No subscription',
});

/**
 * One subscription period of an organization.
 *
 * API shape: {id, organization_id, plan_id, plan?, status, effective_status, status_label, allows_writes,
 *             is_read_only, starts_at, ends_at, days_left, expiring_soon, notes, created_at}
 */
export default class Subscription {
    constructor({
        id = null,
        organizationId = null,
        planId = null,
        plan = null,
        status = 'trial',
        effectiveStatus = 'trial',
        statusLabel = 'Trial',
        allowsWrites = true,
        startsAt = '',
        endsAt = '',
        daysLeft = null,
        expiringSoon = false,
        notes = '',
        createdAt = null,
    } = {}) {
        this.id = id;
        this.organizationId = organizationId;
        this.planId = planId;
        /** Plan|null */
        this.plan = plan;
        /** Stored status. */
        this.status = status;
        /** Stored status, or 'expired' once the end date has passed. */
        this.effectiveStatus = effectiveStatus;
        this.statusLabel = statusLabel;
        this.allowsWrites = allowsWrites;
        this.startsAt = startsAt;
        this.endsAt = endsAt;
        /** Whole days until the end date (negative once passed); null when open-ended. */
        this.daysLeft = daysLeft;
        this.expiringSoon = expiringSoon;
        this.notes = notes;
        this.createdAt = createdAt;
    }

    get isReadOnly() {
        return !this.allowsWrites;
    }

    get isTrial() {
        return this.effectiveStatus === 'trial';
    }

    get isPastDue() {
        return this.effectiveStatus === 'past_due';
    }

    /** CSS modifier for status badges: ok / warn / bad. */
    get tone() {
        if (this.effectiveStatus === 'active') {
            return 'ok';
        }

        if (this.effectiveStatus === 'trial' || this.effectiveStatus === 'past_due') {
            return 'warn';
        }

        return 'bad';
    }

    /** Snake-case body for POST/PUT subscriptions (owner). */
    toPayload() {
        return {
            plan_id: this.planId === null || this.planId === '' ? null : Number(this.planId),
            status: this.status,
            starts_at: this.startsAt || null,
            ends_at: this.endsAt || null,
            notes: this.notes || null,
        };
    }

    static fromJson(json) {
        if (!json) {
            return null;
        }

        return new Subscription({
            id: json.id ?? null,
            organizationId: json.organization_id ?? null,
            planId: json.plan_id ?? null,
            plan: Plan.fromJson(json.plan),
            status: json.status ?? 'trial',
            effectiveStatus: json.effective_status ?? json.status ?? 'trial',
            statusLabel: json.status_label ?? STATUS_LABELS[json.effective_status ?? json.status] ?? '',
            allowsWrites: json.allows_writes === undefined ? !json.is_read_only : Boolean(json.allows_writes),
            startsAt: json.starts_at ? String(json.starts_at).slice(0, 10) : '',
            endsAt: json.ends_at ? String(json.ends_at).slice(0, 10) : '',
            daysLeft: json.days_left === null || json.days_left === undefined ? null : Number(json.days_left),
            expiringSoon: Boolean(json.expiring_soon),
            notes: json.notes ?? '',
            createdAt: json.created_at ?? null,
        });
    }

    static collection(list) {
        return (Array.isArray(list) ? list : []).map((item) => Subscription.fromJson(item)).filter(Boolean);
    }
}
