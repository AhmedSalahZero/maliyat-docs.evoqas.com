/**
 * Today's date as 'YYYY-MM-DD', in the browser's own local time —
 * NOT UTC.
 *
 * This used to be copy-pasted into 8 different files as:
 *   new Date().toISOString().slice(0, 10)
 * which looks right but silently converts to UTC first. For anyone
 * west of Greenwich that's rarely noticed (UTC is "behind" them, so
 * it's usually still "yesterday" in UTC only for a few hours after
 * their own midnight — the opposite direction from the mistake).
 * But for an Egypt-based user (UTC+2/+3), the mistake shows up the
 * other way around: for roughly the first 2-3 hours after midnight
 * Cairo time, UTC hasn't rolled over yet and .toISOString() still
 * reports YESTERDAY's date — which is exactly why a date picker
 * could show, say, the 18th as "today" and grey out the 19th, even
 * though it's already the 19th on the user's own clock.
 *
 * getFullYear()/getMonth()/getDate() read the browser's local wall-
 * clock date directly, with no UTC conversion, so this always
 * matches what the user's own calendar says "today" is.
 */
export function todayIso() {
    const d = new Date();
    const year = d.getFullYear();
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

/**
 * A 'YYYY-MM-DD' date moved forward by a number of days, returned as
 * 'YYYY-MM-DD' — pure calendar arithmetic, no timezone involved.
 *
 * Used by the installment previews (audit finding M13). They used
 * to count from "now" and then print the result through
 * .toISOString(), which (a) started from today instead of the
 * document's own date, and (b) converted to UTC, so for 2-3 hours
 * after midnight in Cairo every due date came out one day early.
 * The server counts from the document date the same way
 * (PaymentRecorderService::buildInstallmentSchedule), so the preview
 * now shows exactly the dates that get saved.
 */
export function addDaysIso(isoDate, days) {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(isoDate || '');
    const base = match ? isoDate : todayIso();
    const [year, month, day] = base.split('-').map(Number);

    // Date.UTC + getUTC* keeps this free of any local-time shift.
    const d = new Date(Date.UTC(year, month - 1, day));
    d.setUTCDate(d.getUTCDate() + Number(days || 0));

    return `${d.getUTCFullYear()}-${String(d.getUTCMonth() + 1).padStart(2, '0')}-${String(d.getUTCDate()).padStart(2, '0')}`;
}
