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
 * API shape: {id, name, first_name, middle_name, last_name, suffix, phone, address, terms_accepted_at, terms_version,
 *             email, email_verified, role, is_owner, is_admin, is_teacher, is_platform, organization_suspended,
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
        givenName = '',
        middleName = '',
        surname = '',
        suffix = '',
        phone = '',
        address = '',
        termsAcceptedAt = null,
        termsVersion = '',
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
        pendingCounts = null,
        createdAt = null,
    } = {}) {
        this.id = id;
        /** The full name ("First Middle Surname Suffix"), kept in step with the parts below. */
        this.name = name;
        /** The name in parts; empty for accounts made before the parts existed (then only `name` is known). */
        this.givenName = givenName;
        this.middleName = middleName;
        this.surname = surname;
        this.suffix = suffix;
        /** Contact number and (optional) address. */
        this.phone = phone;
        this.address = address;
        /** When the Terms of Service and Privacy Policy were accepted (sign-up), and which version. */
        this.termsAcceptedAt = termsAcceptedAt;
        this.termsVersion = termsVersion;
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
        /** Platform staff only: requests waiting across every organization {joinRequests, planRequests, paceRequests, teachingRequests, studentChanges}. */
        this.pendingCounts = pendingCounts;
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

    /** An admin who signed up but has neither registered a school nor asked to join one. */
    get needsOrganizationSetup() {
        return this.isAdmin && !this.hasOrganization && !this.joinRequest?.isPending;
    }

    /**
     * A teacher who has not been admitted into an organization yet, or an admin
     * waiting on their request to join a registered school.
     */
    get needsToJoin() {
        return !this.hasOrganization && (this.isTeacher || (this.isAdmin && Boolean(this.joinRequest?.isPending)));
    }

    /** An admin without a school may register one or ask to join one by code. */
    get mayJoinByCode() {
        return this.isAdmin && !this.hasOrganization;
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

    /** Up to two uppercase initials, e.g. "Jane Doe" -> "JD" (first name and surname when known). */
    get initials() {
        const parts = this.givenName && this.surname
            ? [this.givenName, this.surname]
            : this.name.split(/\s+/).filter(Boolean).slice(0, 2);

        return parts.map((part) => part.charAt(0).toUpperCase()).join('');
    }

    /** The first name, used in greetings (the first word of the name for older accounts). */
    get firstName() {
        return this.givenName || (this.name.trim().split(/\s+/)[0] ?? '');
    }

    /**
     * The name in parts for a form: the stored parts, or for an older account a best guess from
     * `name` (first word = first name, last word = surname, the words between = middle name).
     * `guessed` is true then, so the form can ask the person to check it.
     */
    get nameParts() {
        if (this.givenName || this.surname) {
            return { first: this.givenName, middle: this.middleName, last: this.surname, suffix: this.suffix, guessed: false };
        }

        const words = this.name.trim().split(/\s+/).filter(Boolean);

        return {
            first: words[0] ?? '',
            middle: words.length > 2 ? words.slice(1, -1).join(' ') : '',
            last: words.length > 1 ? words[words.length - 1] : '',
            suffix: '',
            guessed: words.length > 1,
        };
    }

    static fromJson(json) {
        if (!json) {
            return null;
        }

        return new User({
            id: json.id ?? null,
            name: json.name ?? '',
            givenName: json.first_name ?? '',
            middleName: json.middle_name ?? '',
            surname: json.last_name ?? '',
            suffix: json.suffix ?? '',
            phone: json.phone ?? '',
            address: json.address ?? '',
            termsAcceptedAt: json.terms_accepted_at ?? null,
            termsVersion: json.terms_version ?? '',
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
            pendingCounts: json.pending_counts ? {
                joinRequests: Number(json.pending_counts.join_requests ?? 0),
                planRequests: Number(json.pending_counts.plan_requests ?? 0),
                paceRequests: Number(json.pending_counts.pace_requests ?? 0),
                teachingRequests: Number(json.pending_counts.teaching_requests ?? 0),
                studentChanges: Number(json.pending_counts.student_changes ?? 0),
            } : null,
            createdAt: json.created_at ?? null,
        });
    }

    static collection(list) {
        return (Array.isArray(list) ? list : []).map((item) => User.fromJson(item)).filter(Boolean);
    }
}
