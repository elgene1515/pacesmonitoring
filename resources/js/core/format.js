/*
 * Presentation helpers shared by every page.
 */

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/**
 * Parse a "YYYY-MM-DD" (or ISO datetime) string into a local Date, or null.
 */
export function parseDate(value) {
    if (!value) {
        return null;
    }

    if (value instanceof Date) {
        return Number.isNaN(value.getTime()) ? null : value;
    }

    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value));
    if (match) {
        const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
        return Number.isNaN(date.getTime()) ? null : date;
    }

    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? null : date;
}

/**
 * Format a date as "Mon D, YYYY". Returns "" for empty / invalid input.
 */
export function formatDate(value) {
    const date = parseDate(value);
    if (!date) {
        return '';
    }

    return `${MONTHS[date.getMonth()]} ${date.getDate()}, ${date.getFullYear()}`;
}

/**
 * Format a Date (or date string) as "YYYY-MM-DD" for <input type="date">.
 */
export function toIsoDate(date) {
    const parsed = parseDate(date);
    if (!parsed) {
        return '';
    }

    const month = String(parsed.getMonth() + 1).padStart(2, '0');
    const day = String(parsed.getDate()).padStart(2, '0');

    return `${parsed.getFullYear()}-${month}-${day}`;
}

/** Today's date as "YYYY-MM-DD". */
export function todayIso() {
    return toIsoDate(new Date());
}

/**
 * Format a numeric score with up to two decimals. Blank when null/undefined/empty.
 */
export function formatScore(value) {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    const number = Number(value);
    if (Number.isNaN(number)) {
        return '';
    }

    return number.toFixed(2).replace(/\.?0+$/, '');
}

/**
 * Whole-year age at `asOf` (default today) for a "YYYY-MM-DD" birth date. Null when invalid.
 */
export function ageFromBirthDate(birthDate, asOf = new Date()) {
    const birth = parseDate(birthDate);
    const now = parseDate(asOf);

    if (!birth || !now || birth > now) {
        return null;
    }

    let age = now.getFullYear() - birth.getFullYear();
    const hadBirthday = now.getMonth() > birth.getMonth()
        || (now.getMonth() === birth.getMonth() && now.getDate() >= birth.getDate());

    if (!hadBirthday) {
        age -= 1;
    }

    return age;
}

/** Capitalise the first letter ("male" -> "Male"). */
export function capitalize(value) {
    const text = value === null || value === undefined ? '' : String(value);
    return text.charAt(0).toUpperCase() + text.slice(1);
}

/**
 * How long a moment has been going on, in the largest sensible units:
 * "less than a minute", "5 minutes", "3 hours", "2 days", "3 weeks",
 * "4 months", "1 year 2 months". Empty for a missing / invalid / future value.
 */
export function elapsed(value, now = new Date()) {
    if (!value) {
        return '';
    }

    // Laravel sends microseconds ("…:54.000000Z"); keep milliseconds so every browser parses it.
    const start = new Date(String(value).replace(/(\.\d{3})\d+/, '$1'));
    const seconds = Math.floor((now.getTime() - start.getTime()) / 1000);

    if (Number.isNaN(seconds) || seconds < 0) {
        return '';
    }

    if (seconds < 60) {
        return 'less than a minute';
    }

    const minutes = Math.floor(seconds / 60);

    if (minutes < 60) {
        return pluralize(minutes, 'minute');
    }

    const hours = Math.floor(minutes / 60);

    if (hours < 24) {
        return pluralize(hours, 'hour');
    }

    const days = Math.floor(hours / 24);

    if (days < 7) {
        return pluralize(days, 'day');
    }

    if (days < 30) {
        return pluralize(Math.floor(days / 7), 'week');
    }

    if (days < 365) {
        return pluralize(Math.floor(days / 30), 'month');
    }

    const years = Math.floor(days / 365);
    const months = Math.floor((days % 365) / 30);

    return months > 0 ? `${pluralize(years, 'year')} ${pluralize(months, 'month')}` : pluralize(years, 'year');
}

/** "3 days ago" / "just now" (empty for a missing value). */
export function timeAgo(value, now = new Date()) {
    const text = elapsed(value, now);

    if (text === '') {
        return '';
    }

    return text === 'less than a minute' ? 'just now' : `${text} ago`;
}

/** Pluralise a noun by count: pluralize(1, 'PACE') -> "1 PACE", pluralize(3, 'PACE') -> "3 PACEs". */
export function pluralize(count, singular, plural = `${singular}s`) {
    return `${count} ${Number(count) === 1 ? singular : plural}`;
}
