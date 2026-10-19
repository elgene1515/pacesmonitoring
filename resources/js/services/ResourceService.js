/**
 * Generic CRUD service for a REST resource that returns `{ data }` envelopes.
 *
 * Subclasses provide the base path (e.g. "/students") and a model class exposing
 * `static fromJson(json)` and `static collection(list)`.
 */
export default class ResourceService {
    /**
     * @param {import('../core/ApiClient').default} api
     * @param {string} basePath
     * @param {{ fromJson: Function, collection: Function }} model
     */
    constructor(api, basePath, model) {
        this.api = api;
        this.basePath = basePath.replace(/\/+$/, '');
        this.model = model;
    }

    /** Path for one record. */
    itemPath(id) {
        return `${this.basePath}/${encodeURIComponent(id)}`;
    }

    /** GET {basePath}?filters -> Model[] */
    async list(filters = {}) {
        const body = await this.api.get(this.basePath, filters);
        return this.model.collection(body?.data);
    }

    /** GET {basePath}/{id} -> Model */
    async get(id) {
        const body = await this.api.get(this.itemPath(id));
        return this.model.fromJson(body?.data);
    }

    /** POST {basePath} -> Model */
    async create(payload) {
        await this.api.ensureCsrf();
        const body = await this.api.post(this.basePath, this.normalize(payload));
        return this.model.fromJson(body?.data);
    }

    /** PUT {basePath}/{id} -> Model */
    async update(id, payload) {
        await this.api.ensureCsrf();
        const body = await this.api.put(this.itemPath(id), this.normalize(payload));
        return this.model.fromJson(body?.data);
    }

    /** DELETE {basePath}/{id} -> void */
    async remove(id) {
        await this.api.ensureCsrf();
        await this.api.delete(this.itemPath(id));
    }

    /** Accept either a model instance (with toPayload) or a plain object. */
    normalize(payload) {
        if (payload && typeof payload.toPayload === 'function') {
            return payload.toPayload();
        }

        return payload ?? {};
    }
}
