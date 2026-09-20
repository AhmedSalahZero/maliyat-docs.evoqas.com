// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — usePrivateCache
//  Location: resources/js/composables/usePrivateCache.js
//
//  Wipes every browser-side cache that could be holding one
//  account's pages, and does it before the session ends.
//
//  Why this exists
//  ───────────────
//  The service worker used to cache every navigation into an
//  'inertia-pages' cache for 24 hours (see vite.config.js, where
//  that rule is now removed and the reasoning is written out in
//  full). Every page in this app carries its whole Inertia payload
//  inline in the HTML — the signed-in user, their company, and all
//  of that page's figures — and the Cache Storage API keys on the
//  URL alone. So /app/dashboard was a single entry shared by every
//  account ever used on that device, and it was handed to whoever
//  signed in next whenever the network hiccuped. That is how one
//  company's admin ended up looking at another company's books.
//
//  Removing the caching rule stops NEW poisoning. It does nothing
//  for the devices already carrying a poisoned cache — a browser
//  keeps what it has until something deletes it, and an updated
//  service worker does not clear caches its previous version made.
//  So this runs on sign-out and clears them, which is what actually
//  gets existing users out of the hole.
//
//  Deliberately wipes ALL caches, not just the known names: a cache
//  written by an older build under a name this version has never
//  heard of is exactly the one still holding stale tenant data.
//  Losing the precached JS/CSS costs one re-download and nothing
//  else, which is a trade worth making every time.
// ══════════════════════════════════════════════════════════════════

/**
 * Delete every Cache Storage entry for this origin.
 *
 * Never throws and never blocks sign-out: a browser with Cache
 * Storage unavailable or blocked (private mode, disabled site data,
 * an insecure origin) must still be able to log out normally.
 *
 * @returns {Promise<void>}
 */
export async function clearPrivateCaches() {
    if (typeof window === 'undefined' || !('caches' in window)) return;

    try {
        const names = await window.caches.keys();
        await Promise.all(names.map((name) => window.caches.delete(name)));
    } catch {
        // Nothing to do — see the note above on why this is non-fatal.
    }
}

/**
 * Tell the active service worker to stop controlling this page.
 *
 * Belt and braces alongside clearPrivateCaches(): a worker from an
 * older build is still running its own fetch handler until it is
 * unregistered, and that handler is the thing that was serving
 * cached pages. Unregistering means the next load goes to the
 * network and picks up the current worker instead.
 *
 * @returns {Promise<void>}
 */
export async function unregisterServiceWorkers() {
    if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) return;

    try {
        const registrations = await navigator.serviceWorker.getRegistrations();
        await Promise.all(registrations.map((registration) => registration.unregister()));
    } catch {
        // Non-fatal, same reasoning as above.
    }
}

/**
 * Everything that has to be forgotten when a session ends.
 *
 * Call this immediately BEFORE posting to /logout, not after: the
 * logout response redirects and tears the page down, so work started
 * afterwards is not guaranteed to finish.
 *
 * @returns {Promise<void>}
 */
export async function purgeSessionArtifacts() {
    await Promise.all([
        clearPrivateCaches(),
        unregisterServiceWorkers(),
    ]);
}
