/** Numbers, dates and durations in the panel's language. */

const locale = () => (document.documentElement.lang === 'ru' ? 'ru-RU' : 'uz-Latn-UZ');

export function number(value) {
    return new Intl.NumberFormat(locale()).format(Number(value ?? 0));
}

export function percent(part, total) {
    if (!total) return '0%';
    return `${Math.round((part / total) * 100)}%`;
}

export function dateTime(iso) {
    if (!iso) return '—';
    return new Intl.DateTimeFormat(locale(), {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(iso));
}

export function time(iso) {
    if (!iso) return '—';
    return new Intl.DateTimeFormat(locale(), { hour: '2-digit', minute: '2-digit', second: '2-digit' }).format(new Date(iso));
}

const relativeFormats = {};

/**
 * Intl.RelativeTimeFormat for the panel's language, or null when the browser has no
 * words for it: Chrome ships no Uzbek data and prints "-4 min", "+13 min".
 */
function relativeFormat() {
    const tag = locale();
    if (!(tag in relativeFormats)) {
        const rtf = new Intl.RelativeTimeFormat(tag, { numeric: 'auto' });
        relativeFormats[tag] = /^[-+]/.test(rtf.format(-5, 'minute')) ? null : rtf;
    }

    return relativeFormats[tag];
}

/** The panel's own words (common.js.relative) for a browser without them. */
function relativeWords(value, unit) {
    const words = window.__t?.relative;
    if (!words) return null;
    if (value === 0) return words.now;
    const pattern = (value < 0 ? words.ago : words.in)?.[unit];

    return pattern ? pattern.replace(':n', number(Math.abs(value))) : null;
}

function say(value, unit) {
    const rtf = relativeFormat();
    if (rtf) return rtf.format(value, unit);

    return relativeWords(value, unit) ?? new Intl.RelativeTimeFormat(locale(), { numeric: 'auto' }).format(value, unit);
}

/** "3 min ago", "in 2 h". */
export function relative(iso) {
    if (!iso) return '—';
    const seconds = Math.round((new Date(iso).getTime() - Date.now()) / 1000);
    const abs = Math.abs(seconds);

    if (abs < 45) return say(seconds, 'second');
    if (abs < 2700) return say(Math.round(seconds / 60), 'minute');
    if (abs < 79200) return say(Math.round(seconds / 3600), 'hour');
    if (abs < 2592000) return say(Math.round(seconds / 86400), 'day');

    return dateTime(iso);
}

/** 3725 -> "1:02:05", 65 -> "1:05". */
export function clock(totalSeconds) {
    const s = Math.max(0, Math.floor(totalSeconds));
    const h = Math.floor(s / 3600);
    const m = Math.floor((s % 3600) / 60);
    const sec = String(s % 60).padStart(2, '0');

    return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${sec}` : `${m}:${sec}`;
}

/**
 * A number of minutes in days, hours and minutes: 90 -> "1 soat 30 daq".
 * `units` are the page's translated patterns { min, hour, day } with ":n".
 */
export function minutes(total, units) {
    let rest = Math.max(0, Math.round(Number(total) || 0));
    const days = Math.floor(rest / 1440);
    rest -= days * 1440;
    const hours = Math.floor(rest / 60);
    rest -= hours * 60;

    const parts = [];
    if (days) parts.push(units.day.replace(':n', number(days)));
    if (hours) parts.push(units.hour.replace(':n', number(hours)));
    if (rest || !parts.length) parts.push(units.min.replace(':n', number(rest)));

    return parts.join(' ');
}

export function bytes(size) {
    if (!size) return '—';
    const units = ['B', 'KB', 'MB', 'GB'];
    let i = 0;
    let n = Number(size);
    while (n >= 1024 && i < units.length - 1) {
        n /= 1024;
        i++;
    }

    return `${n.toFixed(i ? 1 : 0)} ${units[i]}`;
}

/** Tailwind classes per tone, the one place badge colours are decided. */
export const tones = {
    success: 'bg-success-50 text-success-700 dark:bg-success-500/15 dark:text-success-300',
    error: 'bg-error-50 text-error-700 dark:bg-error-500/15 dark:text-error-300',
    warning: 'bg-warning-50 text-warning-800 dark:bg-warning-500/15 dark:text-warning-300',
    info: 'bg-info-50 text-info-700 dark:bg-info-500/15 dark:text-info-300',
    brand: 'bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-300',
    neutral: 'bg-gray-100 text-gray-600 dark:bg-white/[0.06] dark:text-gray-300',
};

export const dotTones = {
    success: 'text-success-500',
    error: 'text-error-500',
    warning: 'text-warning-500',
    info: 'text-info-500',
    brand: 'text-brand-500',
    neutral: 'text-gray-400',
};

export function tone(name) {
    return tones[name] ?? tones.neutral;
}

export function dot(name) {
    return dotTones[name] ?? dotTones.neutral;
}
