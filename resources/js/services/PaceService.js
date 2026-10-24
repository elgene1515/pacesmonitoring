import Pace from '../models/Pace';

/**
 * The branch's PACE catalog as a teacher sees it (read-only), with the
 * teacher's own booklets (`myStock`) next to the branch pool. Teachers ask
 * for booklets through PaceRequestService; the admin keeps the catalog and
 * the stock through AdminService.
 *
 *  list(filters)   GET /api/paces?search=   -> Pace[] (with assignmentsCount, myStock, poolStock)
 *  get(id)         GET /api/paces/{id}      -> Pace (with assignments[student, subject], pendingRequestsCount)
 */
export default class PaceService {
    /**
     * @param {import('../core/ApiClient').default} api
     */
    constructor(api) {
        this.api = api;
    }

    itemPath(id) {
        return `/paces/${encodeURIComponent(id)}`;
    }

    async list(filters = {}) {
        const body = await this.api.get('/paces', filters);
        return Pace.collection(body?.data);
    }

    async get(id) {
        const body = await this.api.get(this.itemPath(id));
        return Pace.fromJson(body?.data);
    }
}
