/*
 * School-year helpers. A school year runs June -> May and is written "YYYY-YYYY".
 */

export const SCHOOL_YEAR_PATTERN = /^\d{4}-\d{4}$/;

/** First month of the school year (0-based, June). */
export const SCHOOL_YEAR_START_MONTH = 5;

/**
 * Current school year label for a date: month >= June -> "Y-(Y+1)", otherwise "(Y-1)-Y".
 */
export function currentSchoolYear(date = new Date()) {
    const year = date.getFullYear();

    return date.getMonth() >= SCHOOL_YEAR_START_MONTH
        ? `${year}-${year + 1}`
        : `${year - 1}-${year}`;
}

/** Shift a "YYYY-YYYY" label by `offset` years. */
export function shiftSchoolYear(schoolYear, offset) {
    const [start, end] = String(schoolYear).split('-').map(Number);

    return `${start + offset}-${end + offset}`;
}

/**
 * Options for a school-year select: previous, current and next (in that order).
 * @returns {{ value: string, label: string }[]}
 */
export function schoolYearOptions(date = new Date()) {
    const current = currentSchoolYear(date);
    const previous = shiftSchoolYear(current, -1);
    const next = shiftSchoolYear(current, 1);

    return [
        { value: previous, label: `${previous} (previous)` },
        { value: current, label: `${current} (current)` },
        { value: next, label: `${next} (next)` },
    ];
}

export const QUARTERS = Object.freeze([
    { value: 1, label: '1st Quarter', short: 'Q1' },
    { value: 2, label: '2nd Quarter', short: 'Q2' },
    { value: 3, label: '3rd Quarter', short: 'Q3' },
    { value: 4, label: '4th Quarter', short: 'Q4' },
]);

/** "1st Quarter" etc. for 1..4; "" otherwise. */
export function quarterLabel(quarter) {
    return QUARTERS.find((item) => item.value === Number(quarter))?.label ?? '';
}
