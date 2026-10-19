import axios from '../bootstrap';
import ApiClient from '../core/ApiClient';
import AuthService from './AuthService';
import TenantService from './TenantService';
import ReferenceService from './ReferenceService';
import SchoolYearService from './SchoolYearService';
import StudentService from './StudentService';
import PaceService from './PaceService';
import PaceRequestService from './PaceRequestService';
import AssignmentService from './AssignmentService';
import DashboardService from './DashboardService';
import AdminService from './AdminService';
import OwnerService from './OwnerService';

/** Single shared HTTP client for the whole SPA. */
export const api = new ApiClient(axios);

export const authService = new AuthService(api);
export const tenantService = new TenantService(api);
export const referenceService = new ReferenceService(api);
export const schoolYearService = new SchoolYearService(api);
export const studentService = new StudentService(api);
export const paceService = new PaceService(api);
export const paceRequestService = new PaceRequestService(api);
export const assignmentService = new AssignmentService(api);
export const dashboardService = new DashboardService(api);
export const adminService = new AdminService(api);
export const ownerService = new OwnerService(api);

export default {
    api,
    authService,
    tenantService,
    referenceService,
    schoolYearService,
    studentService,
    paceService,
    paceRequestService,
    assignmentService,
    dashboardService,
    adminService,
    ownerService,
};
