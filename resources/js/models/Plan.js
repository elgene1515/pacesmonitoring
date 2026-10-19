/**
 * A subscription tier.
 *
 * API shape: {id, name, slug, description, max_branches, max_teachers, price_per_year, currency, is_active,
 *             sort_order, subscriptions_count?, created_at}
 */
export default class Plan {
    constructor({
        id = null,
        name = '',
        slug = '',
        description = '',
        maxBranches = 1,
        maxAdmins = null,
        maxTeachers = null,
        maxStudents = null,
        pricePerYear = 0,
        currency = 'PHP',
        isActive = true,
        sortOrder = 0,
        subscriptionsCount = null,
        createdAt = null,
    } = {}) {
        this.id = id;
        this.name = name;
        this.slug = slug;
        this.description = description;
        this.maxBranches = maxBranches;
        /** null = unlimited */
        this.maxAdmins = maxAdmins;
        /** null = unlimited */
        this.maxTeachers = maxTeachers;
        /** null = unlimited */
        this.maxStudents = maxStudents;
        this.pricePerYear = pricePerYear;
        this.currency = currency;
        this.isActive = isActive;
        this.sortOrder = sortOrder;
        this.subscriptionsCount = subscriptionsCount;
        this.createdAt = createdAt;
    }

    get exists() {
        return this.id !== null && this.id !== undefined;
    }

    /** "₱15,000 / year" */
    get priceLabel() {
        const symbol = this.currency === 'PHP' ? '₱' : `${this.currency} `;
        return `${symbol}${Number(this.pricePerYear).toLocaleString('en-PH', { maximumFractionDigits: 2 })} / year`;
    }

    /** "up to 2 branches" */
    get branchesLabel() {
        return this.maxBranches === 1 ? '1 branch' : `up to ${this.maxBranches} branches`;
    }

    get adminsLabel() {
        if (this.maxAdmins === null) {
            return 'unlimited admins';
        }

        return this.maxAdmins === 1 ? '1 admin' : `up to ${this.maxAdmins} admins`;
    }

    get teachersLabel() {
        if (this.maxTeachers === null) {
            return 'unlimited teachers';
        }

        return this.maxTeachers === 1 ? '1 teacher' : `up to ${this.maxTeachers} teachers`;
    }

    get studentsLabel() {
        if (this.maxStudents === null) {
            return 'unlimited students';
        }

        return this.maxStudents === 1 ? '1 student' : `up to ${this.maxStudents} students`;
    }

    /** Snake-case body for POST /owner/plans and PUT /owner/plans/{id}. */
    toPayload() {
        return {
            name: String(this.name ?? '').trim(),
            slug: this.slug || null,
            description: this.description || null,
            max_branches: Number(this.maxBranches),
            max_admins: this.maxAdmins === null || this.maxAdmins === '' ? null : Number(this.maxAdmins),
            max_teachers: this.maxTeachers === null || this.maxTeachers === '' ? null : Number(this.maxTeachers),
            max_students: this.maxStudents === null || this.maxStudents === '' ? null : Number(this.maxStudents),
            price_per_year: Number(this.pricePerYear),
            currency: this.currency || 'PHP',
            is_active: Boolean(this.isActive),
            sort_order: this.sortOrder === null || this.sortOrder === '' ? null : Number(this.sortOrder),
        };
    }

    static blank() {
        return new Plan();
    }

    static fromJson(json) {
        if (!json) {
            return null;
        }

        const count = (value) => (value === undefined || value === null ? null : Number(value));

        return new Plan({
            id: json.id ?? null,
            name: json.name ?? '',
            slug: json.slug ?? '',
            description: json.description ?? '',
            maxBranches: Number(json.max_branches ?? 1),
            maxAdmins: json.max_admins === null || json.max_admins === undefined ? null : Number(json.max_admins),
            maxTeachers: json.max_teachers === null || json.max_teachers === undefined ? null : Number(json.max_teachers),
            maxStudents: json.max_students === null || json.max_students === undefined ? null : Number(json.max_students),
            pricePerYear: Number(json.price_per_year ?? 0),
            currency: json.currency ?? 'PHP',
            isActive: Boolean(json.is_active),
            sortOrder: Number(json.sort_order ?? 0),
            subscriptionsCount: count(json.subscriptions_count),
            createdAt: json.created_at ?? null,
        });
    }

    static collection(list) {
        return (Array.isArray(list) ? list : []).map((item) => Plan.fromJson(item)).filter(Boolean);
    }
}
