// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — useScrollToForm
//  Location: resources/js/composables/useScrollToForm.js
//
//  Pressing Edit on a row at the bottom of a long list has to put
//  the FORM in front of you, not the top of the document.
//
//  The entry screens used to do this with
//  window.scrollTo({ top: 0 }), which worked only because the form
//  happened to be the first thing under the page title. Once a
//  how-to panel was added above it, scrolling to zero landed on the
//  instructions and pushed the form — the whole reason for the
//  click — below the fold.
//
//  Scrolling to the form ELEMENT instead is not just the fix for
//  that; it is what the action always meant. Anything else added
//  above the form later cannot break it again.
//
//  The offset exists because .app-header is sticky: an element
//  scrolled to its exact top sits underneath it. A little breathing
//  room above that keeps the "you are editing" banner visible
//  rather than flush against the header.
// ══════════════════════════════════════════════════════════════════

/** Clearance above the target: the sticky header plus a small gap. */
const BREATHING_ROOM = 12;

function headerHeight() {
    const header = document.querySelector('.app-header');

    return header ? header.getBoundingClientRect().height : 0;
}

/**
 * Scroll so the given element sits just below the sticky header.
 *
 * @param {import('vue').Ref<HTMLElement|null>} target  The form container.
 */
export function scrollToForm(target) {
    if (typeof window === 'undefined') return;

    // One frame, so anything that appears or disappears with the
    // same click — the editing banner, a how-to panel being hidden —
    // has been laid out before its position is measured. Without
    // this the target is measured at its pre-edit position and the
    // scroll lands short.
    requestAnimationFrame(() => {
        const el = target?.value;

        if (!el) return;

        const top = el.getBoundingClientRect().top
            + window.scrollY
            - headerHeight()
            - BREATHING_ROOM;

        window.scrollTo({ top: Math.max(top, 0), behavior: 'smooth' });
    });
}
