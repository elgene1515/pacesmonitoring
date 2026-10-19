import ResourceService from './ResourceService';
import SchoolYear from '../models/SchoolYear';

/**
 * /api/school-years (the organization's school years)
 *
 *  list()               -> SchoolYear[] (newest first, with counts; is_current reflects the viewer)
 *  get(id)              -> SchoolYear
 *  create(payload)      admin only. payload: SchoolYear | {name, start_date?, end_date?, is_current?} -> SchoolYear
 *  update(id, payload)  admin only -> SchoolYear
 *  select(id)           teacher only: make this school year "my current" -> SchoolYear
 */
export default class SchoolYearService extends ResourceService {
    constructor(api) {
        super(api, '/school-years', SchoolYear);
    }

    /** PUT /api/school-years/{id}/select -> SchoolYear */
    async select(id) {
        await this.api.ensureCsrf();
        const body = await this.api.put(`${this.itemPath(id)}/select`);
        return SchoolYear.fromJson(body?.data);
    }

    /** School years cannot be deleted; records filed under them must stay reachable. */
    async remove() {
        throw new Error('School years cannot be deleted.');
    }
}
