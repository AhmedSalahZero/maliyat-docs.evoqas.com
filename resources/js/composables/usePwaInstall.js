import { ref, readonly } from 'vue'

const deferredPrompt = ref(null)
const showBanner     = ref(false)
const showIosBanner  = ref(false)

const DELAY_MS            = 4000
const DISMISS_SNOOZE_MS   = 10000
const STORAGE_INSTALLED   = 'mm_pwa_installed'
const STORAGE_DISMISSED   = 'mm_pwa_dismissed'
const STORAGE_IOS_DISMISS = 'mm_pwa_ios_dismissed'

let timer            = null
let snoozeTimer      = null
let timerElapsed     = false
let listenerAttached = false
let snoozeUntil      = 0
let currentLoginCount = 0

// A guest has 0. Anyone who has signed in at least once may be asked.
const MIN_LOGINS_BEFORE_OFFER = 1

export function setPwaLoginContext(loginCount) {
    const previous = currentLoginCount
    currentLoginCount = Number(loginCount) || 0

    // Signing in changes the answer to canOfferInstall(), so re-check
    // rather than waiting for the next beforeinstallprompt — the
    // browser only fires that once per page load.
    if (previous !== currentLoginCount) {
        tryShowBanner()
    }
}

// ── Device detection ──────────────────────────────────────────────

/**
 * iPhone / iPad — Safari or any iOS browser.
 * iOS never fires beforeinstallprompt — needs its own install guide.
 */
export function isIosDevice() {
    if (typeof window === 'undefined') return false
    return (
        /iPad|iPhone|iPod/.test(navigator.userAgent) ||
        (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
    )
}

/**
 * Android browsers that may fire beforeinstallprompt.
 */
export function isAndroidDevice() {
    if (typeof window === 'undefined') return false
    return /Android/.test(navigator.userAgent)
}

function isSnoozed() {
    return Date.now() < snoozeUntil
}

function isStandaloneDisplay() {
    if (typeof window === 'undefined') return false
    return (
        window.matchMedia('(display-mode: standalone)').matches ||
        window.matchMedia('(display-mode: fullscreen)').matches ||
        window.navigator.standalone === true
    )
}

/** Detect uninstall: was marked installed but opened again in the browser. */
export function syncPwaInstallState() {
    if (typeof window === 'undefined') return

    if (isStandaloneDisplay()) {
        localStorage.setItem(STORAGE_INSTALLED, '1')
        return
    }

    if (localStorage.getItem(STORAGE_INSTALLED)) {
        localStorage.removeItem(STORAGE_INSTALLED)
        localStorage.removeItem(STORAGE_DISMISSED)
        localStorage.removeItem(STORAGE_IOS_DISMISS)
        timerElapsed = false
    }

    if (localStorage.getItem(STORAGE_DISMISSED) && !localStorage.getItem(STORAGE_INSTALLED)) {
        localStorage.removeItem(STORAGE_DISMISSED)
        timerElapsed = false
    }
}

function canOfferInstall() {
    if (typeof window === 'undefined') return false
    syncPwaInstallState()
    if (isStandaloneDisplay()) return false
    if (isSnoozed()) return false

    // Only offer the install to someone who has actually signed in.
    //
    // setPwaLoginContext() has always recorded this, but nothing ever
    // read it — so the banner was being shown to guests too, floating
    // over the login form and covering its buttons. Asking a stranger
    // to install the app before they can even sign in is the wrong
    // moment to ask, quite apart from what it was sitting on top of.
    if (currentLoginCount < MIN_LOGINS_BEFORE_OFFER) return false

    return true
}

function captureDeferredPrompt() {
    const existing = window.__maliyat_pwa_prompt
    if (!existing) return

    // Capture unconditionally. Whether we may SHOW the banner is a
    // separate question, answered by tryShowBanner() — see the note
    // on onBeforeInstallPrompt below.
    deferredPrompt.value = existing
    showIosBanner.value  = false
    tryShowBanner()
}

function tryShowBanner() {
    if (!canOfferInstall()) {
        showBanner.value    = false
        showIosBanner.value = false
        return
    }

    // Android — native install prompt (Chrome / Edge)
    if (!isIosDevice() && deferredPrompt.value && timerElapsed) {
        showBanner.value    = true
        showIosBanner.value = false
        return
    }

    showBanner.value = false

    // iOS — manual install guide (no beforeinstallprompt)
    if (isIosDevice() && timerElapsed) {
        const dismissed = localStorage.getItem(STORAGE_IOS_DISMISS)
        showIosBanner.value = !dismissed
        return
    }

    showIosBanner.value = false
}

function onBeforeInstallPrompt(e) {
    // ALWAYS capture, then decide separately whether to show.
    //
    // The browser fires this once per page load and hands over the
    // only object that can trigger an install. Bailing out here —
    // which is what the login-count gate used to do — threw that
    // object away: a guest lands on /login, the event fires, it is
    // discarded, and then they sign in by SPA navigation with no page
    // reload, so no second event ever comes. The banner could never
    // appear again for the rest of that session.
    //
    // preventDefault() also has to happen here or the browser shows
    // its own mini-infobar over the page.
    e.preventDefault()
    window.__maliyat_pwa_prompt = e
    deferredPrompt.value = e
    showIosBanner.value  = false

    // canOfferInstall() is consulted inside tryShowBanner(), so a
    // guest still sees nothing — the prompt is merely kept until they
    // are someone we may ask.
    tryShowBanner()
}

export function registerServiceWorker() {
    if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) return

    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => {})
    })
}

export function initPwaInstallListener() {
    syncPwaInstallState()
    captureDeferredPrompt()

    if (!listenerAttached) {
        window.addEventListener('beforeinstallprompt', onBeforeInstallPrompt)
        window.addEventListener('maliyat-pwa-installable', captureDeferredPrompt)

        window.addEventListener('pageshow', () => {
            syncPwaInstallState()
            captureDeferredPrompt()
            tryShowBanner()
        })

        document.addEventListener('inertia:finish', () => {
            syncPwaInstallState()
            captureDeferredPrompt()
            tryShowBanner()
        })

        listenerAttached = true
    }

    if (!timer) {
        timer = setTimeout(() => {
            timerElapsed = true
            tryShowBanner()
        }, DELAY_MS)
    }
}

export function usePwaInstall() {
    return {
        showBanner:    readonly(showBanner),
        showIosBanner: readonly(showIosBanner),
        canInstall:    readonly(deferredPrompt),
        isIos:         isIosDevice(),
        isAndroid:     isAndroidDevice(),

        async install() {
            const prompt = deferredPrompt.value
            if (!prompt) return null

            await prompt.prompt()
            const choice = await prompt.userChoice

            showBanner.value     = false
            deferredPrompt.value = null

            if (choice?.outcome === 'accepted') {
                localStorage.setItem(STORAGE_INSTALLED, '1')
            }

            return choice
        },

        dismiss() {
            showBanner.value = false
            snoozeUntil = Date.now() + DISMISS_SNOOZE_MS

            if (snoozeTimer) clearTimeout(snoozeTimer)
            snoozeTimer = setTimeout(() => {
                snoozeTimer = null
                tryShowBanner()
            }, DISMISS_SNOOZE_MS)
        },

        dismissIos() {
            showIosBanner.value = false
            localStorage.setItem(STORAGE_IOS_DISMISS, '1')
        },
    }
}
