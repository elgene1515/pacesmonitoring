import TeachingAssignment from '../models/TeachingAssignment';
import TeachingAssignmentRequest from '../models/TeachingAssignmentRequest';

/**
 * The teacher's teaching assignments (which subject they teach to which
 * section) and their requests for open slots, decided by the school admin.
 *
 *  assignments()         GET    /api/teaching-assignments                 -> TeachingAssignment[] (with section.gradeLevel, subject)
 *  requests()            GET    /api/teaching-assignment-requests         -> TeachingAssignmentRequest[] (pending first)
 *  request(payload)      POST   /api/teaching-assignment-requests         -> TeachingAssignmentRequest
 *                        payload: {section_id, subject_id, message?}
 *  cancelRequest(id)     DELETE /api/teaching-assignment-requests/{id}    -> void (withdraw a pending request)
 */
export default class TeachingService {
    /**
     * @param {import('../core/ApiClient').default} api
     */
    constructor(api) {
        this.api = api;
    }

    requestPath(id) {
        return `/teaching-assignment-requests/${encodeURIComponent(id)}`;
    }

    async assignments() {
        const body = await this.api.get('/teaching-assignments');
        return TeachingAssignment.collection(body?.data);
    }

    async requests() {
        const body = await this.api.get('/teaching-assignment-requests');
        return TeachingAssignmentRequest.collection(body?.data);
    }

    async request({ sectionId, subjectId, message = '' }) {
        await this.api.ensureCsrf();
        const body = await this.api.post('/teaching-assignment-requests', {
            section_id: Number(sectionId),
            subject_id: Number(subjectId),
            message: message ? String(message).trim() : null,
        });

        return TeachingAssignmentRequest.fromJson(body?.data);
    }

    async cancelRequest(id) {
        await this.api.ensureCsrf();
        await this.api.delete(this.requestPath(id));
    }
}
