import PaceAssignment from './PaceAssignment';

/** At or below this many booklets a PACE counts as "low stock". */
export const LOW_STOCK_THRESHOLD = 5;

/** Stock level labels and StatusBadge tones, keyed by `Pace.stockLevel`. */
export const STOCK_LEVEL_LABELS = Object.freeze({ out: 'Out of stock', low: 'Low stock', ok: 'In stock' });
export const STOCK_LEVEL_TONES = Object.freeze({ out: 'bad', low: 'warn', ok: 'ok' });

/**
 * PACE booklet. A PACE is not tied to any particular school year. Its stock
 * is the number of booklets the teacher has on hand: issuing a PACE to a
 * student takes one.
 *
 * API shape: {id, name, number, stock, assignments_count?, tested_count?, issued_count?, assigned_count?,
 *             pending_requests_count?, assignments?, created_at, updated_at}
 */
export default class Pace {
    constructor({
        id = null,
        name = '',
        number = '',
        stock = 0,
        assignmentsCount = null,
        testedCount = null,
        issuedCount = null,
        assignedCount = null,
        pendingRequestsCount = null,
        assignments = [],
        teacher = null,
        createdAt = null,
        updatedAt = null,
    } = {}) {
        /** {id, name, email, branch_name}|null — the teacher who registered the PACE, when the admin API included them. */
        this.teacher = teacher;
        this.id = id;
        this.name = name;
        this.number = number;
        /** Booklets on hand. */
        this.stock = stock;
        /** Number of assignments, when the API included the count; otherwise null. */
        this.assignmentsCount = assignmentsCount;
        /** Of assignmentsCount, how many are tested / issued-only / assigned-only, when the API included the breakdown. */
        this.testedCount = testedCount;
        this.issuedCount = issuedCount;
        this.assignedCount = assignedCount;
        /** Stock requests still waiting for the admin, when the API included the count; otherwise null. */
        this.pendingRequestsCount = pendingRequestsCount;
        /** @type {PaceAssignment[]} */
        this.assignments = assignments;
        this.createdAt = createdAt;
        this.updatedAt = updatedAt;
    }

    /** "#1001 · Math 1001" */
    get label() {
        return `#${this.number} · ${this.name}`;
    }

    /** True when the record has been saved on the server. */
    get exists() {
        return this.id !== null && this.id !== undefined;
    }

    get isOutOfStock() {
        return (Number(this.stock) || 0) <= 0;
    }

    get isLowStock() {
        return !this.isOutOfStock && Number(this.stock) <= LOW_STOCK_THRESHOLD;
    }

    /** 'out' | 'low' | 'ok' */
    get stockLevel() {
        if (this.isOutOfStock) {
            return 'out';
        }

        return this.isLowStock ? 'low' : 'ok';
    }

    get stockLabel() {
        return STOCK_LEVEL_LABELS[this.stockLevel];
    }

    /** StatusBadge tone for the stock level. */
    get stockTone() {
        return STOCK_LEVEL_TONES[this.stockLevel];
    }

    get hasPendingRequest() {
        return (this.pendingRequestsCount ?? 0) > 0;
    }

    get teacherName() {
        return this.teacher?.name ?? '';
    }

    /** The registering teacher's branch, when the admin API included it. */
    get teacherBranchName() {
        return this.teacher?.branch_name ?? '';
    }

    /** Snake-case body for POST /paces and PUT /paces/{id}. */
    toPayload() {
        return {
            name: String(this.name ?? '').trim(),
            number: String(this.number ?? '').trim(),
            stock: Math.max(0, Math.trunc(Number(this.stock) || 0)),
        };
    }

    /** Empty instance for "create" forms. */
    static blank() {
        return new Pace();
    }

    static fromJson(json) {
        if (!json) {
            return null;
        }

        return new Pace({
            id: json.id ?? null,
            name: json.name ?? '',
            number: json.number ?? '',
            stock: Number(json.stock ?? 0) || 0,
            assignmentsCount: json.assignments_count === undefined ? null : Number(json.assignments_count),
            testedCount: json.tested_count === undefined ? null : Number(json.tested_count),
            issuedCount: json.issued_count === undefined ? null : Number(json.issued_count),
            assignedCount: json.assigned_count === undefined ? null : Number(json.assigned_count),
            pendingRequestsCount: json.pending_requests_count === undefined ? null : Number(json.pending_requests_count),
            assignments: PaceAssignment.collection(json.assignments),
            teacher: json.teacher ?? null,
            createdAt: json.created_at ?? null,
            updatedAt: json.updated_at ?? null,
        });
    }

    static collection(list) {
        return (Array.isArray(list) ? list : []).map((item) => Pace.fromJson(item)).filter(Boolean);
    }
}
