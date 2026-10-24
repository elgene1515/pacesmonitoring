import Student from '../models/Student';
import SchoolYear from '../models/SchoolYear';

/**
 * Home-page summary numbers (what the teacher's teaching assignments cover, for one school year).
 */
export default class DashboardService {
    /**
     * @param {import('../core/ApiClient').default} api
     */
    constructor(api) {
        this.api = api;
    }

    /**
     * GET /api/dashboard/summary?school_year_id=
     *
     * Without a school year id the API uses the user's current school year; `'all'`
     * requests all-time totals across every school year; a user with no school years
     * gets all-time counts either way, and `schoolYear` is then null.
     * `filters` may carry gradeLevelId, sectionId, subjectId and/or paceId to narrow the figures further.
     *
     * -> { schoolYear: SchoolYear|null, gradeLevels, sections, students, paces, assignments, tested, scored, passed: number,
     *      issued, assigned, retakes, failedCount: number,
     *      recentStudents: Student[],
     *      byQuarter: { quarter, total, tested, issued, assigned, passed, failed, retakes: number }[],
     *      bySubject: { id, name, gradeLevelName: string, total, tested, issued, assigned: number, averageScore: ?number }[],
     *      byGradeLevel: { id: number, name, branchName: string, total, male, female: number }[],
     *      yearTrend: { schoolYear: string, assignments, tested: number }[],
     *      failed: { id, studentId, quarter: number, score: number|null, failed, retake: boolean, studentName, subjectName, paceNumber, paceName, schoolYear, dateTested: string }[],
     *              (PACEs scored below 80 and/or flagged as retake, most recent first)
     *      topPerformers: { studentId, averageScore, scoredCount, passedCount, failedCount, retakeCount: number, studentName: string }[],
     *      scoreDistribution: { label: string, total: number }[],
     *      totals: { schoolYears, gradeLevels, sections, students, paces, assignments: number } }
     */
    async summary(schoolYearId = null, filters = {}) {
        const body = await this.api.get('/dashboard/summary', {
            school_year_id: schoolYearId ?? '',
            grade_level_id: filters.gradeLevelId ?? '',
            section_id: filters.sectionId ?? '',
            subject_id: filters.subjectId ?? '',
            pace_id: filters.paceId ?? '',
        });
        const data = body?.data ?? {};
        const totals = data.totals ?? {};

        return {
            schoolYear: SchoolYear.fromJson(data.school_year),
            gradeLevels: Number(data.grade_levels ?? 0),
            sections: Number(data.sections ?? 0),
            students: Number(data.students ?? 0),
            paces: Number(data.paces ?? 0),
            assignments: Number(data.assignments ?? 0),
            tested: Number(data.tested ?? 0),
            scored: Number(data.scored ?? 0),
            passed: Number(data.passed ?? 0),
            issued: Number(data.issued ?? 0),
            assigned: Number(data.assigned ?? 0),
            retakes: Number(data.retakes ?? 0),
            failedCount: Number(data.failed_count ?? 0),
            recentStudents: Student.collection(data.recent_students),
            byQuarter: (data.by_quarter ?? []).map((row) => ({
                quarter: Number(row.quarter ?? 0),
                total: Number(row.total ?? 0),
                tested: Number(row.tested ?? 0),
                issued: Number(row.issued ?? 0),
                assigned: Number(row.assigned ?? 0),
                passed: Number(row.passed ?? 0),
                failed: Number(row.failed ?? 0),
                retakes: Number(row.retakes ?? 0),
            })),
            bySubject: (data.by_subject ?? []).map((row) => ({
                id: Number(row.id ?? 0),
                name: row.name ?? '',
                gradeLevelName: row.grade_level_name ?? '',
                total: Number(row.total ?? 0),
                tested: Number(row.tested ?? 0),
                issued: Number(row.issued ?? 0),
                assigned: Number(row.assigned ?? 0),
                averageScore: row.average_score === null || row.average_score === undefined ? null : Number(row.average_score),
            })),
            byGradeLevel: (data.by_grade_level ?? []).map((row) => ({
                id: Number(row.id ?? 0),
                name: row.name ?? '',
                branchName: row.branch_name ?? '',
                total: Number(row.total ?? 0),
                male: Number(row.male ?? 0),
                female: Number(row.female ?? 0),
            })),
            yearTrend: (data.year_trend ?? []).map((row) => ({
                schoolYear: row.school_year ?? '',
                assignments: Number(row.assignments ?? 0),
                tested: Number(row.tested ?? 0),
            })),
            failed: (data.failed ?? []).map((row) => ({
                id: Number(row.id ?? 0),
                failed: Boolean(row.failed),
                retake: Boolean(row.retake),
                studentId: Number(row.student_id ?? 0),
                studentName: row.student_name ?? '',
                subjectName: row.subject_name ?? '',
                paceNumber: row.pace_number ?? '',
                paceName: row.pace_name ?? '',
                score: row.score === null || row.score === undefined ? null : Number(row.score),
                quarter: Number(row.quarter ?? 0),
                schoolYear: row.school_year ?? '',
                dateTested: row.date_tested ?? null,
            })),
            topPerformers: (data.top_performers ?? []).map((row) => ({
                studentId: Number(row.student_id ?? 0),
                studentName: row.student_name ?? '',
                averageScore: row.average_score === null || row.average_score === undefined ? null : Number(row.average_score),
                scoredCount: Number(row.scored_count ?? 0),
                passedCount: Number(row.passed_count ?? 0),
                failedCount: Number(row.failed_count ?? 0),
                retakeCount: Number(row.retake_count ?? 0),
            })),
            scoreDistribution: (data.score_distribution ?? []).map((row) => ({
                label: row.label ?? '',
                total: Number(row.total ?? 0),
            })),
            totals: {
                schoolYears: Number(totals.school_years ?? 0),
                gradeLevels: Number(totals.grade_levels ?? 0),
                sections: Number(totals.sections ?? 0),
                students: Number(totals.students ?? 0),
                paces: Number(totals.paces ?? 0),
                assignments: Number(totals.assignments ?? 0),
            },
        };
    }

    /** Empty summary used as initial state before the first load. */
    static empty() {
        return {
            schoolYear: null,
            gradeLevels: 0,
            sections: 0,
            students: 0,
            paces: 0,
            assignments: 0,
            tested: 0,
            scored: 0,
            passed: 0,
            issued: 0,
            assigned: 0,
            retakes: 0,
            failedCount: 0,
            recentStudents: [],
            byQuarter: [],
            bySubject: [],
            byGradeLevel: [],
            yearTrend: [],
            failed: [],
            topPerformers: [],
            scoreDistribution: [],
            totals: { schoolYears: 0, gradeLevels: 0, sections: 0, students: 0, paces: 0, assignments: 0 },
        };
    }
}
