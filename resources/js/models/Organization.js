import Branch from './Branch';
import Subscription from './Subscription';
import PlanRequest from './PlanRequest';

/**
 * A school (the tenant).
 *
 * API shape: {id, name, code, address, contact_email, contact_phone, branches?, subscription?, subscriptions?,
 *             branches_count?, teachers_count?, admins_count?, pending_join_requests_count?, students_count?,
 *             assignments_count?, creator?, created_at}
 */
export default class Organization {
    constructor({
        id = null,
        name = '',
        code = '',
        address = '',
        contactEmail = '',
        contactPhone = '',
        branches = [],
        subscription = null,
        subscriptions = [],
        branchesCount = null,
        teachersCount = null,
        adminsCount = null,
        pendingJoinRequestsCount = null,
        pendingPlanRequestsCount = null,
        pendingPaceRequestsCount = null,
        planRequests = [],
        deletedAt = null,
        isDeleted = false,
        studentsCount = null,
        assignmentsCount = null,
        creator = null,
        createdAt = null,
    } = {}) {
        this.id = id;
        this.name = name;
        /** Public code teachers type in to join, e.g. "SCH-7F3K2A". */
        this.code = code;
        this.address = address;
        this.contactEmail = contactEmail;
        this.contactPhone = contactPhone;
        /** Branch[] when loaded. */
        this.branches = branches;
        /** Subscription|null — the current one, when loaded. */
        this.subscription = subscription;
        /** Subscription[] — the history, when loaded (owner pages). */
        this.subscriptions = subscriptions;
        this.branchesCount = branchesCount;
        this.teachersCount = teachersCount;
        this.adminsCount = adminsCount;
        this.pendingJoinRequestsCount = pendingJoinRequestsCount;
        this.pendingPlanRequestsCount = pendingPlanRequestsCount;
        /** Teachers' PACE stock requests waiting for the school's admins. */
        this.pendingPaceRequestsCount = pendingPaceRequestsCount;
        /** PlanRequest[] when loaded (owner pages). */
        this.planRequests = planRequests;
        /** Set once the owner deactivated the school (soft delete). */
        this.deletedAt = deletedAt;
        this.isDeleted = isDeleted;
        this.studentsCount = studentsCount;
        this.assignmentsCount = assignmentsCount;
        /** {id, name, email}|null */
        this.creator = creator;
        this.createdAt = createdAt;
    }

    get exists() {
        return this.id !== null && this.id !== undefined;
    }

    get mainBranch() {
        return this.branches.find((branch) => branch.isMain) ?? this.branches[0] ?? null;
    }

    /** Effective subscription status ('none' without a subscription). */
    get subscriptionStatus() {
        return this.subscription?.effectiveStatus ?? 'none';
    }

    get subscriptionLabel() {
        return this.subscription?.statusLabel ?? 'No subscription';
    }

    get planName() {
        return this.subscription?.plan?.name ?? '';
    }

    get allowsWrites() {
        return Boolean(this.subscription?.allowsWrites);
    }

    /** Branch limit of the current plan, or null when unlimited / unknown. */
    get branchLimit() {
        return this.subscription?.plan?.maxBranches ?? null;
    }

    get teacherLimit() {
        return this.subscription?.plan?.maxTeachers ?? null;
    }

    get studentLimit() {
        return this.subscription?.plan?.maxStudents ?? null;
    }

    static fromJson(json) {
        if (!json) {
            return null;
        }

        const count = (value) => (value === undefined || value === null ? null : Number(value));

        return new Organization({
            id: json.id ?? null,
            name: json.name ?? '',
            code: json.code ?? '',
            address: json.address ?? '',
            contactEmail: json.contact_email ?? '',
            contactPhone: json.contact_phone ?? '',
            branches: Branch.collection(json.branches),
            subscription: Subscription.fromJson(json.subscription),
            subscriptions: Subscription.collection(json.subscriptions),
            branchesCount: count(json.branches_count),
            teachersCount: count(json.teachers_count),
            adminsCount: count(json.admins_count),
            pendingJoinRequestsCount: count(json.pending_join_requests_count),
            pendingPlanRequestsCount: count(json.pending_plan_requests_count),
            pendingPaceRequestsCount: count(json.pending_pace_requests_count),
            planRequests: PlanRequest.collection(json.plan_requests),
            deletedAt: json.deleted_at ?? null,
            isDeleted: Boolean(json.is_deleted),
            studentsCount: count(json.students_count),
            assignmentsCount: count(json.assignments_count),
            creator: json.creator ?? null,
            createdAt: json.created_at ?? null,
        });
    }

    static collection(list) {
        return (Array.isArray(list) ? list : []).map((item) => Organization.fromJson(item)).filter(Boolean);
    }
}
