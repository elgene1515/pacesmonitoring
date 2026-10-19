/**
 * Ring definitions for the LayeredRings chart from a list of PaceAssignment records.
 *
 * Outer to inner: assigned / issued / tested / retakes are shares of every record
 * in the list; pass and fail are shares of the records that have a score (so the
 * two add up to everything scored).
 *
 * @param {import('../models/PaceAssignment').default[]} records
 * @returns {{ key: string, label: string, value: number, total: number, color: string, hint: string }[]}
 */
export function buildRecordRings(records = []) {
    const total = records.length;
    const scored = records.filter((item) => item.score !== null && item.score !== undefined);
    const passed = scored.filter((item) => item.isPassed).length;
    const count = (predicate) => records.filter(predicate).length;

    return [
        { key: 'assigned', label: 'Assigned', value: count((item) => item.status === 'assigned'), total, color: 'var(--status-assigned)', hint: 'Assigned but not yet issued' },
        { key: 'issued', label: 'Issued', value: count((item) => item.status === 'issued'), total, color: 'var(--status-issued)', hint: 'Issued but not yet tested' },
        { key: 'tested', label: 'Tested', value: count((item) => item.status === 'tested'), total, color: 'var(--status-tested)', hint: 'Tested PACEs' },
        { key: 'pass', label: 'Pass', value: passed, total: scored.length, color: '#7c3aed', hint: 'Scored 80 or above, out of scored PACEs' },
        { key: 'retake', label: 'Retakes', value: count((item) => item.retake), total, color: '#d67014', hint: 'PACEs flagged as a retake' },
        { key: 'fail', label: 'Fail', value: scored.length - passed, total: scored.length, color: 'var(--danger)', hint: 'Scored below 80, out of scored PACEs' },
    ];
}
