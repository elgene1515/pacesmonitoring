import PaceAssignment from './PaceAssignment';
import PaceStockMovement from './PaceStockMovement';

/** At or below this many booklets a stock counts as "low". */
export const LOW_STOCK_THRESHOLD = 5;

/** Stock level labels and StatusBadge tones, keyed by `Pace.levelFor()`. */
export const STOCK_LEVEL_LABELS = Object.freeze({ out: 'None', low: 'Low', ok: 'In stock' });
export const STOCK_LEVEL_TONES = Object.freeze({ out: 'bad', low: 'warn', ok: 'ok' });

/**
 * A PACE in a branch's catalog ("1001 · Math"), shared by the branch's
 * teachers and admin. The branch's booklets of it are either in the pool
 * (`poolStock`, what the admin has on hand) or held by a teacher
 * (`holdings`, and `myStock` for the signed-in teacher). Issuing a PACE to
 * a student takes one booklet from the teacher's own stock.
 *
 * API shape: {id, branch_id, name, number, pool_stock, my_stock?, holdings_total?, holdings?: [{teacher_id, quantity, teacher?}],
 *             branch?: {id, name, code}, assignments_count?, tested_count?, issued_count?, assigned_count?,
 *             pending_requests_count?, assignments?, movements?, created_at, updated_at}
 */
export default class Pace {
    constructor({
        id = null,
        branchId = null,
        name = '',
        number = '',
        poolStock = 0,
        myStock = null,
        holdingsTotal = null,
        holdings = [],
        branch = null,
        assignmentsCount = null,
        testedCount = null,
        issuedCount = null,
        assignedCount = null,
        pendingRequestsCount = null,
        assignments = [],
        movements = [],
        createdAt = null,
        updatedAt = null,
    } = {}) {
        this.id = id;
        this.branchId = branchId;
        this.name = name;
        this.number = number;
        /** Booklets in the branch's pool (the admin's). */
        this.poolStock = poolStock;
        /** The signed-in teacher's own booklets, when the API included them; otherwise null. */
        this.myStock = myStock;
        /** Booklets held by teachers altogether, when the API included it; otherwise null. */
        this.holdingsTotal = holdingsTotal;
        /** [{teacherId, quantity, teacher: {id, name, email}|null}] when loaded (admin endpoints). */
        this.holdings = holdings;
        /** {id, name, code}|null */
        this.branch = branch;
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
        /** @type {PaceStockMovement[]} the stock ledger, newest first (admin detail) */
        this.movements = movements;
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

    get branchName() {
        return this.branch?.name ?? '';
    }

    /** The school (organization) the branch belongs to, when the list carried it. */
    get organizationName() {
        return this.branch?.organization?.name ?? '';
    }

    /* ---- the signed-in teacher's booklets ---- */

    /** The teacher's booklets as a number (0 when unknown). */
    get stock() {
        return Number(this.myStock ?? 0) || 0;
    }

    get isOutOfStock() {
        return this.stock <= 0;
    }

    get isLowStock() {
        return !this.isOutOfStock && this.stock <= LOW_STOCK_THRESHOLD;
    }

    /** 'out' | 'low' | 'ok' for the teacher's own booklets. */
    get stockLevel() {
        return Pace.levelFor(this.stock);
    }

    get stockLabel() {
        return STOCK_LEVEL_LABELS[this.stockLevel];
    }

    /** StatusBadge tone for the teacher's own booklets. */
    get stockTone() {
        return STOCK_LEVEL_TONES[this.stockLevel];
    }

    /* ---- the branch's pool ---- */

    get hasPoolStock() {
        return (Number(this.poolStock) || 0) > 0;
    }

    /** 'out' | 'low' | 'ok' for the pool. */
    get poolLevel() {
        return Pace.levelFor(this.poolStock);
    }

    get poolLabel() {
        return STOCK_LEVEL_LABELS[this.poolLevel];
    }

    get poolTone() {
        return STOCK_LEVEL_TONES[this.poolLevel];
    }

    /** Booklets in the branch altogether: the pool plus everything teachers hold (when known). */
    get totalStock() {
        return (Number(this.poolStock) || 0) + (Number(this.holdingsTotal) || 0);
    }

    get hasPendingRequest() {
        return (this.pendingRequestsCount ?? 0) > 0;
    }

    /** How many booklets a teacher holds, from the loaded holdings (0 when none). */
    holdingOf(teacherId) {
        return Number(this.holdings.find((holding) => holding.teacherId === Number(teacherId))?.quantity ?? 0);
    }

    /** 'out' | 'low' | 'ok' for any booklet count. */
    static levelFor(quantity) {
        const count = Number(quantity) || 0;

        if (count <= 0) {
            return 'out';
        }

        return count <= LOW_STOCK_THRESHOLD ? 'low' : 'ok';
    }

    /** Snake-case body for POST /admin/paces (with the pool) and PUT /admin/paces/{id} (name and number). */
    toPayload() {
        return {
            branch_id: this.branchId ? Number(this.branchId) : null,
            name: String(this.name ?? '').trim(),
            number: String(this.number ?? '').trim(),
            pool_stock: Math.max(0, Math.trunc(Number(this.poolStock) || 0)),
        };
    }

    /** Empty instance for "create" forms. */
    static blank(branchId = null) {
        return new Pace({ branchId });
    }

    static fromJson(json) {
        if (!json) {
            return null;
        }

        return new Pace({
            id: json.id ?? null,
            branchId: json.branch_id ?? null,
            name: json.name ?? '',
            number: json.number ?? '',
            poolStock: Number(json.pool_stock ?? 0) || 0,
            myStock: json.my_stock === undefined || json.my_stock === null ? null : Number(json.my_stock),
            holdingsTotal: json.holdings_total === undefined || json.holdings_total === null ? null : Number(json.holdings_total),
            holdings: (Array.isArray(json.holdings) ? json.holdings : []).map((holding) => ({
                teacherId: Number(holding.teacher_id),
                quantity: Number(holding.quantity ?? 0),
                teacher: holding.teacher ?? null,
            })),
            branch: json.branch ?? null,
            assignmentsCount: json.assignments_count === undefined ? null : Number(json.assignments_count),
            testedCount: json.tested_count === undefined ? null : Number(json.tested_count),
            issuedCount: json.issued_count === undefined ? null : Number(json.issued_count),
            assignedCount: json.assigned_count === undefined ? null : Number(json.assigned_count),
            pendingRequestsCount: json.pending_requests_count === undefined ? null : Number(json.pending_requests_count),
            assignments: PaceAssignment.collection(json.assignments),
            movements: PaceStockMovement.collection(json.movements),
            createdAt: json.created_at ?? null,
            updatedAt: json.updated_at ?? null,
        });
    }

    static collection(list) {
        return (Array.isArray(list) ? list : []).map((item) => Pace.fromJson(item)).filter(Boolean);
    }

    /** Natural order by number (shorter numbers first), then name. */
    static compare(a, b) {
        const left = String(a.number);
        const right = String(b.number);

        return left.length - right.length || left.localeCompare(right) || String(a.name).localeCompare(String(b.name));
    }
}
