/**
 * Normalised error thrown by ApiClient for every failed request.
 *
 *  - status  HTTP status code (0 when the request never reached the server)
 *  - errors  Laravel 422 field map: { field: [messages...] }
 *  - data    raw response body, when there was one
 */
export default class ApiError extends Error {
    constructor(message, { status = 0, errors = {}, data = null, cause = null } = {}) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.errors = errors && typeof errors === 'object' ? errors : {};
        this.data = data;
        this.cause = cause;
    }

    get isValidation() {
        return this.status === 422;
    }

    get isUnauthenticated() {
        return this.status === 401;
    }

    get isForbidden() {
        return this.status === 403;
    }

    get isNotFound() {
        return this.status === 404;
    }

    get isNetwork() {
        return this.status === 0;
    }

    /** True when at least one field error is present. */
    get hasFieldErrors() {
        return Object.keys(this.errors).length > 0;
    }

    /** First validation message for a field, or null. */
    first(field) {
        const messages = this.errors[field];
        if (Array.isArray(messages)) {
            return messages.length ? String(messages[0]) : null;
        }
        return messages ? String(messages) : null;
    }

    /** Flat list of every validation message. */
    all() {
        return Object.values(this.errors).flatMap((value) => (Array.isArray(value) ? value : [value]));
    }

    /**
     * Build an ApiError from an axios rejection (or any thrown value).
     */
    static fromAxios(err) {
        if (err instanceof ApiError) {
            return err;
        }

        const response = err?.response;

        if (!response) {
            const message = err?.code === 'ECONNABORTED'
                ? 'The request timed out. Please try again.'
                : 'Unable to reach the server. Check your connection and try again.';
            return new ApiError(message, { status: 0, cause: err });
        }

        const data = response.data ?? null;
        const status = response.status ?? 0;
        let message = typeof data?.message === 'string' && data.message.trim() !== ''
            ? data.message
            : ApiError.defaultMessage(status);

        const errors = status === 422 && data?.errors && typeof data.errors === 'object' ? data.errors : {};

        if (status === 422 && message === 'The given data was invalid.') {
            const firstError = Object.values(errors).flat()[0];
            if (firstError) {
                message = String(firstError);
            }
        }

        return new ApiError(message, { status, errors, data, cause: err });
    }

    static defaultMessage(status) {
        switch (status) {
            case 401:
                return 'You need to sign in to continue.';
            case 403:
                return 'You are not allowed to do that.';
            case 404:
                return 'The requested record was not found.';
            case 419:
                return 'Your session has expired. Please refresh the page.';
            case 422:
                return 'Please correct the highlighted fields.';
            case 429:
                return 'Too many requests. Please slow down.';
            default:
                return status >= 500 ? 'Something went wrong on the server.' : 'Request failed.';
        }
    }
}
