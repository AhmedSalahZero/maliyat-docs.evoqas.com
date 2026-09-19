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
