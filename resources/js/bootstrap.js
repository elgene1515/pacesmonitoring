import axios from 'axios';

/*
 * Axios defaults for Sanctum SPA (cookie/session) authentication.
 * The API lives on the same origin as the SPA, so relative URLs are used
 * and the XSRF-TOKEN cookie is echoed back automatically as X-XSRF-TOKEN.
 */
// axios.defaults.baseURL = '/pacesmonitoring'; //deployment
axios.defaults.baseURL = '/'; //local
axios.defaults.withCredentials = true;
axios.defaults.withXSRFToken = true;
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
axios.defaults.headers.common['Accept'] = 'application/json';

export default axios;
