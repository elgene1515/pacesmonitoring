/**
 * A branch (campus) of an organization.
 *
 * API shape: {id, organization_id, name, code, address, is_main, teachers_count?, users_count?, created_at}
 */
export default class Branch {
    constructor({
        id = null,
        organizationId = null,
        name = '',
        code = '',
        address = '',
        isMain = false,
        teachersCount = null,
        usersCount = null,
        createdAt = null,
    } = {}) {
        this.id = id;
        this.organizationId = organizationId;
        this.name = name;
        /** Short code unique within the organization, e.g. "MAIN". */
        this.code = code;
        this.address = address;
        this.isMain = isMain;
        this.teachersCount = teachersCount;
        this.usersCount = usersCount;
        this.createdAt = createdAt;
    }

    get exists() {
        return this.id !== null && this.id !== undefined;
    }

    /** "Main (MAIN)" */
    get label() {
        return this.code ? `${this.name} (${this.code})` : this.name;
    }

    static fromJson(json) {
        if (!json) {
            return null;
        }

        const count = (value) => (value === undefined || value === null ? null : Number(value));

        return new Branch({
            id: json.id ?? null,
            organizationId: json.organization_id ?? null,
            name: json.name ?? '',
            code: json.code ?? '',
            address: json.address ?? '',
            isMain: Boolean(json.is_main),
            teachersCount: count(json.teachers_count),
            usersCount: count(json.users_count),
            createdAt: json.created_at ?? null,
        });
    }

    static collection(list) {
        return (Array.isArray(list) ? list : []).map((item) => Branch.fromJson(item)).filter(Boolean);
    }
}
