import { ROLE_LABELS } from './User';

/**
 * An account that belongs to no organization yet (owner's home page).
 *
 * API shape: {id, name, email, role, created_at, left_organization_at, unassigned_since,
 *             join_request: {id, status, organization_name, branch_name, decline_reason, created_at}|null,
 *             records: {students, paces, grade_levels, school_years, pace_requests, total}}
 */
export default class UnassignedUser {
    constructor({
        id = null,
        name = '',
        email = '',
        role = 'teacher',
        createdAt = null,
        leftOrganizationAt = null,
        unassignedSince = null,
        joinRequest = null,
        records = {},
    } = {}) {
        this.id = id;
        this.name = name;
        this.email = email;
        this.role = role;
        /** ISO timestamp the account was created. */
        this.createdAt = createdAt;
        /** ISO timestamp a school removed the member, or null when they never joined one. */
        this.leftOrganizationAt = leftOrganizationAt;
        /** ISO timestamp "not in a school" counts from: the removal, else the sign-up. */
        this.unassignedSince = unassignedSince;
        this.joinRequest = joinRequest;
        /** Records the account still owns, by kind (plus `total`). */
        this.records = records;
    }

    get roleLabel() {
        return ROLE_LABELS[this.role] ?? this.role;
    }

    get isAdmin() {
        return this.role === 'admin';
    }

    /** True when a school removed this member (they were in one before). */
    get hasLeftASchool() {
        return Boolean(this.leftOrganizationAt);
    }

    get recordCount() {
        return Number(this.records?.total ?? 0);
    }

    /** True while the account owns records of its previous school: it cannot be deleted. */
    get ownsRecords() {
        return this.recordCount > 0;
    }

    /** "2 PACEs, 1 stock request" for the tooltip that explains why deleting is blocked. */
    get recordsSummary() {
        const labels = {
            students: ['student', 'students'],
            paces: ['PACE', 'PACEs'],
            grade_levels: ['grade level', 'grade levels'],
            school_years: ['school year', 'school years'],
            pace_requests: ['stock request', 'stock requests'],
        };

        return Object.entries(labels)
            .filter(([kind]) => Number(this.records?.[kind] ?? 0) > 0)
            .map(([kind, [one, many]]) => `${this.records[kind]} ${Number(this.records[kind]) === 1 ? one : many}`)
            .join(', ');
    }

    /** What the account's latest request to join a school says, with a StatusBadge tone. */
    get joinRequestInfo() {
        const request = this.joinRequest;

        if (!request) {
            return { label: 'No request yet', tone: 'muted', detail: '' };
        }

        const school = request.organization_name || 'a school';

        switch (request.status) {
            case 'pending':
                return { label: 'Waiting', tone: 'warn', detail: `For ${school}${request.branch_name ? ` · ${request.branch_name}` : ''}` };
            case 'declined':
                return { label: 'Declined', tone: 'bad', detail: `By ${school}${request.decline_reason ? ` · “${request.decline_reason}”` : ''}` };
            case 'cancelled':
                return { label: 'Withdrawn', tone: 'muted', detail: `Request to ${school}` };
            case 'accepted':
                return { label: 'Was admitted', tone: 'ok', detail: `To ${school}` };
            default:
                return { label: request.status, tone: 'muted', detail: '' };
        }
    }

    static fromJson(json) {
        if (!json) {
            return null;
        }

        return new UnassignedUser({
            id: json.id ?? null,
            name: json.name ?? '',
            email: json.email ?? '',
            role: json.role ?? 'teacher',
            createdAt: json.created_at ?? null,
            leftOrganizationAt: json.left_organization_at ?? null,
            unassignedSince: json.unassigned_since ?? json.created_at ?? null,
            joinRequest: json.join_request ?? null,
            records: json.records ?? {},
        });
    }

    static collection(list) {
        return (Array.isArray(list) ? list : []).map((item) => UnassignedUser.fromJson(item)).filter(Boolean);
    }
}
