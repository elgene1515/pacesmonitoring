/**
 * A school year of the signed-in user's organization (registered by its admins).
 *
 * API shape: {id, organization_id, name, start_date, end_date, is_current, is_organization_default,
 *             grade_levels_count?, students_count?,
 *             assignments_count?, created_at, updated_at}
 */
export default class SchoolYear {
    constructor({
        id = null,
        name = '',
        startDate = '',
        endDate = '',
        isCurrent = false,
        isOrganizationDefault = false,
        organizationId = null,
        gradeLevelsCount = null,
        studentsCount = null,
        assignmentsCount = null,
        createdAt = null,
        updatedAt = null,
    } = {}) {
        /** Number of grade levels registered under this school year, when the API included the count. */
        this.gradeLevelsCount = gradeLevelsCount;
        this.id = id;
        /** "YYYY-YYYY" */
        this.name = name;
        /** "YYYY-MM-DD" | "" */
        this.startDate = startDate;
        /** "YYYY-MM-DD" | "" */
        this.endDate = endDate;
        /** What counts as current for the signed-in user (a teacher's own pick, else the organization's default). */
        this.isCurrent = isCurrent;
        /** The organization's default school year (new teachers start on it). */
        this.isOrganizationDefault = isOrganizationDefault;
        this.organizationId = organizationId;
        this.studentsCount = studentsCount;
        this.assignmentsCount = assignmentsCount;
        this.createdAt = createdAt;
        this.updatedAt = updatedAt;
    }

    /** True when the record has been saved on the server. */
    get exists() {
        return this.id !== null && this.id !== undefined;
    }

    /** "SY 2026-2027" */
    get label() {
        return `SY ${this.name}`;
    }

    /** Snake-case body for POST /school-years and PUT /school-years/{id}. */
    toPayload() {
        return {
            name: String(this.name ?? '').trim(),
            start_date: this.startDate || null,
            end_date: this.endDate || null,
            is_current: Boolean(this.isCurrent),
        };
    }

    /** Empty instance for "create" forms. */
    static blank() {
        return new SchoolYear();
    }

    static fromJson(json) {
        if (!json) {
            return null;
        }

        const count = (value) => (value === undefined || value === null ? null : Number(value));

        return new SchoolYear({
            id: json.id ?? null,
            name: json.name ?? '',
            startDate: json.start_date ? String(json.start_date).slice(0, 10) : '',
            endDate: json.end_date ? String(json.end_date).slice(0, 10) : '',
            isCurrent: Boolean(json.is_current),
            isOrganizationDefault: Boolean(json.is_organization_default),
            organizationId: json.organization_id ?? null,
            gradeLevelsCount: count(json.grade_levels_count),
            studentsCount: count(json.students_count),
            assignmentsCount: count(json.assignments_count),
            createdAt: json.created_at ?? null,
            updatedAt: json.updated_at ?? null,
        });
    }

    /** Map an API array to SchoolYear[] sorted newest first. */
    static collection(list) {
        return (Array.isArray(list) ? list : [])
            .map((item) => SchoolYear.fromJson(item))
            .filter(Boolean)
            .sort(SchoolYear.compare);
    }

    /** Newest school year first. */
    static compare(a, b) {
        return String(b.name).localeCompare(String(a.name));
    }
}
