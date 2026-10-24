/** Mirrors App\Enums\PaceStockMovementType. */
export const MOVEMENT_TYPE_LABELS = Object.freeze({
    received: 'Received',
    distributed: 'Given to teacher',
    returned: 'Returned to pool',
    adjusted: 'Pool adjusted',
});

/**
 * One entry of a PACE's stock ledger.
 *
 * API shape: {id, pace_id, type, quantity, from_user_id, to_user_id, performed_by, pace_request_id, note,
 *             from_teacher?: {id, name}, to_teacher?: {id, name}, performer?: {id, name}, created_at}
 */
export default class PaceStockMovement {
    constructor({
        id = null,
        paceId = null,
        type = 'received',
        quantity = 0,
        fromUserId = null,
        toUserId = null,
        performedBy = null,
        paceRequestId = null,
        note = '',
        fromTeacher = null,
        toTeacher = null,
        performer = null,
        createdAt = null,
    } = {}) {
        this.id = id;
        this.paceId = paceId;
        /** 'received' | 'distributed' | 'returned' | 'adjusted' */
        this.type = type;
        /** Signed for adjustments; positive otherwise. */
        this.quantity = quantity;
        this.fromUserId = fromUserId;
        this.toUserId = toUserId;
        this.performedBy = performedBy;
        /** The stock request this move fulfilled, when there was one. */
        this.paceRequestId = paceRequestId;
        this.note = note;
        this.fromTeacher = fromTeacher;
        this.toTeacher = toTeacher;
        this.performer = performer;
        this.createdAt = createdAt;
    }

    get typeLabel() {
        return MOVEMENT_TYPE_LABELS[this.type] ?? this.type;
    }

    /** What the move did to the pool: received / returned / positive adjustments add, distributions / negative adjustments take. */
    get poolDelta() {
        switch (this.type) {
            case 'received':
            case 'returned':
                return Math.abs(Number(this.quantity) || 0);
            case 'distributed':
                return -Math.abs(Number(this.quantity) || 0);
            default:
                return Number(this.quantity) || 0;
        }
    }

    /** "+12" / "−4" for the pool column. */
    get poolDeltaLabel() {
        const delta = this.poolDelta;

        return delta > 0 ? `+${delta}` : `−${Math.abs(delta)}`;
    }

    /** The teacher involved, for distributions and returns. */
    get teacherName() {
        return this.toTeacher?.name ?? this.fromTeacher?.name ?? '';
    }

    get performerName() {
        return this.performer?.name ?? '';
    }

    /** "Given to Ana Cruz" / "Returned by Ana Cruz" / "Received" / "Pool adjusted" */
    get description() {
        switch (this.type) {
            case 'distributed':
                return this.teacherName ? `Given to ${this.teacherName}` : this.typeLabel;
            case 'returned':
                return this.teacherName ? `Returned by ${this.teacherName}` : this.typeLabel;
            default:
                return this.typeLabel;
        }
    }

    static fromJson(json) {
        if (!json) {
            return null;
        }

        return new PaceStockMovement({
            id: json.id ?? null,
            paceId: json.pace_id ?? null,
            type: json.type ?? 'received',
            quantity: Number(json.quantity ?? 0),
            fromUserId: json.from_user_id ?? null,
            toUserId: json.to_user_id ?? null,
            performedBy: json.performed_by ?? null,
            paceRequestId: json.pace_request_id ?? null,
            note: json.note ?? '',
            fromTeacher: json.from_teacher ?? null,
            toTeacher: json.to_teacher ?? null,
            performer: json.performer ?? null,
            createdAt: json.created_at ?? null,
        });
    }

    static collection(list) {
        return (Array.isArray(list) ? list : []).map((item) => PaceStockMovement.fromJson(item)).filter(Boolean);
    }
}
