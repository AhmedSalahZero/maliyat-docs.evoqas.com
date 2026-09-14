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

export function setPwaLoginContext(loginCount) {
    currentLoginCount = Number(loginCount) || 0
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
    return true
}

function captureDeferredPrompt() {
    const existing = window.__inpractice_pwa_prompt
    if (!existing || !canOfferInstall()) return

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
    if (!canOfferInstall()) return

    e.preventDefault()
    window.__inpractice_pwa_prompt = e
    deferredPrompt.value = e
    showIosBanner.value  = false
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
        window.addEventListener('inpractice-pwa-installable', captureDeferredPrompt)

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
