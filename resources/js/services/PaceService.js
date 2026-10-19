import ResourceService from './ResourceService';
import Pace from '../models/Pace';

/**
 * /api/paces
 *
 *  list(filters)          filters: { search?: string } -> Pace[] (with assignmentsCount, stock)
 *  get(id)                -> Pace (with assignments[student, subject])
 *  create(payload)        payload: Pace | {name, number, stock?} -> Pace
 *  createRange(payload)   payload: {name, start, end, stock?} -> Pace[], one per number in the range
 *  update(id, payload)    -> Pace
 *  adjustStock(id, delta) PATCH {basePath}/{id}/stock {delta} -> Pace (delta: booklets to add, negative to remove)
 *  remove(id)             -> void
 */
export default class PaceService extends ResourceService {
    constructor(api) {
        super(api, '/paces', Pace);
    }

    /** POST {basePath}/range -> Pace[] */
    async createRange(payload) {
        await this.api.ensureCsrf();
        const body = await this.api.post(`${this.basePath}/range`, payload);
        return this.model.collection(body?.data);
    }

    /** PATCH {basePath}/{id}/stock -> Pace */
    async adjustStock(id, delta) {
        await this.api.ensureCsrf();
        const body = await this.api.patch(`${this.itemPath(id)}/stock`, { delta: Number(delta) });
        return this.model.fromJson(body?.data);
    }
}
