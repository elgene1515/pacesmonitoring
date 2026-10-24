/**
 * One stay of a student in a section (the enrollment history on a student's
 * page). Names are kept on the row, so a deleted section still shows up.
 *
 * API shape: {id, student_id, section_id, grade_level_id, school_year_id, branch_id,
 *             section_name, grade_level_name, school_year_name, branch_name,
 *             started_on (Y-m-d), ended_on (Y-m-d|null), is_current, moved_by?: {id, name}|null}
 */
export default class StudentEnrollment {
    constructor({
        id = null,
        studentId = null,
        sectionId = null,
        gradeLevelId = null,
        schoolYearId = null,
        branchId = null,
        sectionName = '',
        gradeLevelName = '',
        schoolYearName = '',
        branchName = '',
        startedOn = '',
        endedOn = null,
        isCurrent = false,
        movedBy = null,
    } = {}) {
        this.id = id;
        this.studentId = studentId;
        /** Null once the section was deleted; the name below stays. */
        this.sectionId = sectionId;
        this.gradeLevelId = gradeLevelId;
        this.schoolYearId = schoolYearId;
        this.branchId = branchId;
        this.sectionName = sectionName;
        this.gradeLevelName = gradeLevelName;
        this.schoolYearName = schoolYearName;
        this.branchName = branchName;
        /** "YYYY-MM-DD" */
        this.startedOn = startedOn;
        /** "YYYY-MM-DD", or null while the stay goes on. */
        this.endedOn = endedOn;
        this.isCurrent = isCurrent;
        /** {id, name} of who enrolled / moved the student here, when known. */
        this.movedBy = movedBy;
    }

    /** "Grade 1 · Section 2" */
    get classLabel() {
        return [this.gradeLevelName, this.sectionName].filter(Boolean).join(' · ');
    }

    static fromJson(json) {
        if (!json) {
            return null;
        }

        return new StudentEnrollment({
            id: json.id ?? null,
            studentId: json.student_id ?? null,
            sectionId: json.section_id ?? null,
            gradeLevelId: json.grade_level_id ?? null,
            schoolYearId: json.school_year_id ?? null,
            branchId: json.branch_id ?? null,
            sectionName: json.section_name ?? '',
            gradeLevelName: json.grade_level_name ?? '',
            schoolYearName: json.school_year_name ?? '',
            branchName: json.branch_name ?? '',
            startedOn: json.started_on ? String(json.started_on).slice(0, 10) : '',
            endedOn: json.ended_on ? String(json.ended_on).slice(0, 10) : null,
            isCurrent: Boolean(json.is_current ?? json.ended_on === null),
            movedBy: json.moved_by ?? null,
        });
    }

    static collection(list) {
        return (Array.isArray(list) ? list : []).map((item) => StudentEnrollment.fromJson(item)).filter(Boolean);
    }
}
