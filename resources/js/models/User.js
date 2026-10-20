import Organization from './Organization';
import Branch from './Branch';
import JoinRequest from './JoinRequest';

/** Account roles (mirrors App\Enums\Role). */
export const ROLES = Object.freeze({
    OWNER: 'owner',
    VICE_ADMIN: 'vice_admin',
    ADMIN: 'admin',
    TEACHER: 'teacher',
});

/** Display names: the platform owner is the CEO, platform staff are Admins, a school's administrator is a School Admin. */
export const ROLE_LABELS = Object.freeze({
    owner: 'CEO',
    vice_admin: 'Admin',
    admin: 'School Admin',
    teacher: 'Teacher',
});

/** What platform accounts show where members show their school and branch. */
export const PLATFORM_ORGANIZATION = 'PACES Monitoring System';
export const PLATFORM_BRANCH = 'Management';

/** Platform-side roles (not members of any organization). */
export const PLATFORM_ROLES = Object.freeze([ROLES.OWNER, ROLES.VICE_ADMIN]);

/**
 * A user account.
 *
 * API shape: {id, name, email, email_verified, role, is_owner, is_admin, is_teacher, is_platform, organization_suspended,
 *             organization_id, branch_id, current_school_year_id, organization?, branch?, join_request?, created_at}
 *
 *  - owner:      the platform owner (every organization, plans, subscriptions, staff)
 *  - vice_admin: platform staff appointed by the owner (same view, minus staff / deactivation / plan deletion)
 *  - admin:      a school administrator (manages one organization)
 *  - teacher:    a member of one organization/branch who owns their own records
 */
export default class User {
    constructor({
        id = null,
        name = '',
        email = '',
        emailVerified = true,
        role = ROLES.TEACHER,
        organizationId = null,
        branchId = null,
        currentSchoolYearId = null,
        organization = null,
        organizationSuspended = false,
        branch = null,
        joinRequest = null,
        createdAt = null,
    } = {}) {
        this.id = id;
        this.name = name;
        this.email = email;
        /** False until the user has clicked the link emailed at sign-up (or after changing their address). */
        this.emailVerified = emailVerified;
        this.role = role;
        this.organizationId = organizationId;
        this.branchId = branchId;
        this.currentSchoolYearId = currentSchoolYearId;
        /** Organization|null — present on the signed-in user and in member lists. */
        this.organization = organization;
        /** True when the member's organization was deactivated by the owner. */
        this.organizationSuspended = organizationSuspended;
        /** Branch|null */
        this.branch = branch;
        /** JoinRequest|null — a teacher's latest request (pending / declined / ...). */
        this.joinRequest = joinRequest;
        this.createdAt = createdAt;
    }

    /** The platform owner (strict). */
    get isOwner() {
        return this.role === ROLES.OWNER;
    }

    get isViceAdmin() {
        return this.role === ROLES.VICE_ADMIN;
    }

    /** Platform staff: owner or vice admin. */
    get isPlatform() {
        return PLATFORM_ROLES.includes(this.role);
    }

    /** A school administrator (not platform staff). */
    get isAdmin() {
        return this.role === ROLES.ADMIN;
    }

    get isTeacher() {
        return this.role === ROLES.TEACHER;
    }

    get roleLabel() {
        return ROLE_LABELS[this.role] ?? this.role;
    }

    get hasOrganization() {
        return this.organizationId !== null && this.organizationId !== undefined;
    }

    /** An admin who signed up but has not registered their school yet. */
    get needsOrganizationSetup() {
        return this.isAdmin && !this.hasOrganization;
    }

    /** A teacher who has not been admitted into an organization yet. */
    get needsToJoin() {
        return this.isTeacher && !this.hasOrganization;
    }

    /** A member whose school the owner deactivated. */
    get isSuspended() {
        return !this.isPlatform && this.hasOrganization && this.organizationSuspended;
    }

    /** Anything that keeps the user off the regular pages (onboarding or a deactivated school). */
    get needsSetup() {
        return this.needsOrganizationSetup || this.needsToJoin || this.isSuspended;
    }

    get organizationName() {
        return this.organization?.name ?? '';
    }

    get branchName() {
        return this.branch?.name ?? '';
    }

    /** The school's name, or the company for platform accounts. */
    get organizationLabel() {
        if (this.isPlatform) {
            return PLATFORM_ORGANIZATION;
        }

        return this.organizationName || (this.hasOrganization ? '' : 'Not in a school yet');
    }

    /** The branch's name, or the company unit for platform accounts. */
    get branchLabel() {
        if (this.isPlatform) {
            return PLATFORM_BRANCH;
        }

        // School admins manage every branch, so only teachers show one.
        return this.isTeacher ? this.branchName : '';
    }

    /** The organization's current subscription, when the API included it. */
    get subscription() {
        return this.organization?.subscription ?? null;
    }

    /** True while the organization's subscription allows changes (platform staff: always). */
    get canWrite() {
        if (this.isPlatform) {
            return true;
        }

        if (this.isSuspended) {
            return false;
        }

        return this.subscription ? this.subscription.allowsWrites : this.hasOrganization;
    }

    /** Up to two uppercase initials, e.g. "Jane Doe" -> "JD". */
    get initials() {
        return this.name
            .split(/\s+/)
            .filter(Boolean)
            .slice(0, 2)
            .map((part) => part.charAt(0).toUpperCase())
            .join('');
    }

    /** First word of the name, used in greetings. */
    get firstName() {
        return this.name.trim().split(/\s+/)[0] ?? '';
    }

    static fromJson(json) {
        if (!json) {
            return null;
        }

        return new User({
            id: json.id ?? null,
            name: json.name ?? '',
            email: json.email ?? '',
            emailVerified: json.email_verified ?? true,
            role: json.role ?? (json.is_owner ? ROLES.OWNER : json.is_admin ? ROLES.ADMIN : ROLES.TEACHER),
            organizationId: json.organization_id ?? null,
            branchId: json.branch_id ?? null,
            currentSchoolYearId: json.current_school_year_id ?? null,
            organization: Organization.fromJson(json.organization),
            organizationSuspended: Boolean(json.organization_suspended),
            branch: Branch.fromJson(json.branch),
            joinRequest: JoinRequest.fromJson(json.join_request),
            createdAt: json.created_at ?? null,
        });
    }

    static collection(list) {
        return (Array.isArray(list) ? list : []).map((item) => User.fromJson(item)).filter(Boolean);
    }
}
