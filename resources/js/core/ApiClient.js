import axios from 'axios';
import ApiError from './ApiError';

/**
 * Thin OOP wrapper around axios.
 *
 * Every verb returns `response.data` (the JSON body) and rejects with an ApiError.
 * Relative paths are prefixed with `/api`; absolute URLs (starting with "http")
 * and paths that already carry the prefix are sent as-is.
 *
 * Scope: the owner may look at the admin area one organization at a time.
 * `setScope({ organization_id })` makes every GET under /api/admin carry
 * that query parameter without each page having to pass it along.
 */
export default class ApiClient {
    /**
     * @param {import('axios').AxiosInstance} http
     * @param {{ prefix?: string }} options
     */
    constructor(http = axios, { prefix = '/api' } = {}) {
        this.http = http;
        this.prefix = prefix;
        this.unauthenticatedHandlers = new Set();
        this.csrfPrimed = false;
        this.scope = {};
        this.scopedPrefixes = [`${prefix}/admin`];
    }

    /** Register a callback fired whenever a request returns 401. Returns an unsubscribe fn. */
    onUnauthenticated(handler) {
        this.unauthenticatedHandlers.add(handler);
        return () => this.unauthenticatedHandlers.delete(handler);
    }

    /** Query parameters appended to every GET under the scoped prefixes ({} clears). */
    setScope(params = {}) {
        this.scope = ApiClient.cleanParams(params);
    }

    /** Fetch the Sanctum CSRF cookie (needed before the first state-changing request). */
    async csrf() {
        try {
            await this.http.get('/sanctum/csrf-cookie');
            this.csrfPrimed = true;
        } catch (err) {
            throw ApiError.fromAxios(err);
        }
    }

    /** Ensure the CSRF cookie was requested at least once in this page session. */
    async ensureCsrf() {
        if (!this.csrfPrimed) {
            await this.csrf();
        }
    }

    /**
     * GET request. `params` becomes the query string (empty values are dropped).
     */
    get(url, params = {}, config = {}) {
        const target = this.resolve(url);
        const scoped = this.scopedPrefixes.some((scopedPrefix) => target === scopedPrefix || target.startsWith(`${scopedPrefix}/`));

        return this.request('get', target, undefined, {
            ...config,
            params: ApiClient.cleanParams({ ...(scoped ? this.scope : {}), ...(config.params ?? {}), ...params }),
        });
    }

    post(url, data = {}, config = {}) {
        return this.request('post', url, data, config);
    }

    put(url, data = {}, config = {}) {
        return this.request('put', url, data, config);
    }

    patch(url, data = {}, config = {}) {
        return this.request('patch', url, data, config);
    }

    delete(url, data = undefined, config = {}) {
        return this.request('delete', url, data, config);
    }

    /**
     * Low-level request used by every verb.
     */
    async request(method, url, data = undefined, config = {}) {
        const target = this.resolve(url);

        try {
            const response = await this.http.request({
                ...config,
                method,
                url: target,
                data,
            });

            return response.data;
        } catch (err) {
            const apiError = ApiError.fromAxios(err);

            if (apiError.isUnauthenticated) {
                this.unauthenticatedHandlers.forEach((handler) => {
                    try {
                        handler(apiError);
                    } catch (handlerError) {
                        console.error(handlerError);
                    }
                });
            }

            throw apiError;
        }
    }

    /** Prefix relative API paths with `/api`. */
    resolve(url) {
        const path = String(url);

        if (/^https?:\/\//i.test(path) || path === this.prefix || path.startsWith(`${this.prefix}/`)) {
            return path;
        }

        return `${this.prefix}/${path.replace(/^\/+/, '')}`;
    }

    /**
     * Remove empty-string / null / undefined entries so they are not sent as query params.
     */
    static cleanParams(params = {}) {
        return Object.fromEntries(
            Object.entries(params).filter(([, value]) => value !== '' && value !== null && value !== undefined),
        );
    }
}
