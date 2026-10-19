import PaceAssignment from '../models/PaceAssignment';

/**
 * PACE assignments.
 *
 *  forStudent(studentId, filters)  GET /api/students/{id}/assignments?school_year=&quarter=&subject_id=
 *  assign(studentId, payload)      POST /api/students/{id}/assignments
 *                                  payload: { subject_id, pace_ids: number[], school_year, quarter, issued_date? }
 *  update(id, payload)             PUT /api/assignments/{id} (partial; PaceAssignment or snake_case object)
 *  remove(id)                      DELETE /api/assignments/{id}
 */
export default class AssignmentService {
    /**
     * @param {import('../core/ApiClient').default} api
     */
    constructor(api) {
        this.api = api;
    }

    studentPath(studentId) {
        return `/students/${encodeURIComponent(studentId)}/assignments`;
    }

    itemPath(id) {
        return `/assignments/${encodeURIComponent(id)}`;
    }

    /** -> PaceAssignment[] (each with pace, subject) */
    async forStudent(studentId, filters = {}) {
        const body = await this.api.get(this.studentPath(studentId), filters);
        return PaceAssignment.collection(body?.data);
    }

    /** -> PaceAssignment[] created (one per pace id) */
    async assign(studentId, payload) {
        await this.api.ensureCsrf();

        const data = {
            ...payload,
            subject_id: Number(payload.subject_id),
            pace_ids: (payload.pace_ids ?? []).map((id) => Number(id)),
            quarter: Number(payload.quarter),
        };

        if (data.issued_date === '' || data.issued_date === undefined) {
            delete data.issued_date;
        }

        const body = await this.api.post(this.studentPath(studentId), data);
        return PaceAssignment.collection(body?.data);
    }

    /** -> PaceAssignment (with pace, subject) */
    async update(id, payload) {
        await this.api.ensureCsrf();
        const data = payload && typeof payload.toPayload === 'function' ? payload.toPayload() : (payload ?? {});
        const body = await this.api.put(this.itemPath(id), data);
        return PaceAssignment.fromJson(body?.data);
    }

    /** -> void */
    async remove(id) {
        await this.api.ensureCsrf();
        await this.api.delete(this.itemPath(id));
    }
}
