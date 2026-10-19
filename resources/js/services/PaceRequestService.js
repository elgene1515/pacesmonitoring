import PaceRequest from '../models/PaceRequest';

/**
 * The teacher's PACE stock requests to the school admin.
 *
 *  list(filters)        GET    /api/pace-requests?status=            -> PaceRequest[] (pending first)
 *  create(payload)      POST   /api/pace-requests                    -> PaceRequest
 *                       payload: PaceRequest | {pace_id?, pace_number?, pace_name?, quantity, needed_by?, message?}
 *  update(id, payload)  PUT    /api/pace-requests/{id} (partial)     -> PaceRequest
 *  cancel(id)           DELETE /api/pace-requests/{id}               -> void (withdraw a pending request)
 */
export default class PaceRequestService {
    /**
     * @param {import('../core/ApiClient').default} api
     */
    constructor(api) {
        this.api = api;
    }

    itemPath(id) {
        return `/pace-requests/${encodeURIComponent(id)}`;
    }

    async list(filters = {}) {
        const body = await this.api.get('/pace-requests', filters);
        return PaceRequest.collection(body?.data);
    }

    async create(payload) {
        await this.api.ensureCsrf();
        const body = await this.api.post('/pace-requests', this.normalize(payload));
        return PaceRequest.fromJson(body?.data);
    }

    async update(id, payload) {
        await this.api.ensureCsrf();
        const body = await this.api.put(this.itemPath(id), this.normalize(payload));
        return PaceRequest.fromJson(body?.data);
    }

    async cancel(id) {
        await this.api.ensureCsrf();
        await this.api.delete(this.itemPath(id));
    }

    normalize(payload) {
        return payload && typeof payload.toPayload === 'function' ? payload.toPayload() : (payload ?? {});
    }
}
