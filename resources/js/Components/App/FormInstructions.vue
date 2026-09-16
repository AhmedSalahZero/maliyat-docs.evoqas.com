<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — FormInstructions.vue
//  Location: resources/js/Components/App/FormInstructions.vue
//
//  "How do I use this screen?" — numbered steps, in plain language,
//  sitting above the form itself.
//
//  Written for somebody who runs a shop, not somebody who knows
//  bookkeeping. The steps describe what to DO on this screen, in the
//  order the fields appear, and never explain accounting — a step
//  that says "this debits Accounts Receivable" has failed.
//
//  COLLAPSED BY DEFAULT after the first read. Somebody recording
//  their fortieth sale does not want a paragraph between them and
//  the form, and somebody recording their first one does. The
//  open/closed state is remembered per screen in localStorage, so it
//  opens for a newcomer and stays out of the way for a regular —
//  without either of them having to manage a setting.
//
//  Storage can throw (private windows, blocked site data), and a
//  help panel must never be the reason a page fails to render, so
//  every read and write is guarded and falls back to "open".
//
//  ON A PHONE this matters more, not less: the form is a long
//  scroll and the steps are the map. The trigger is a full-width
//  tap target and the list reflows to one column — see the styles.
// ══════════════════════════════════════════════════════════════════

import { ref, computed, onMounted } from 'vue';
import { useAppTranslations } from '@/composables/useAppTranslations';

const props = defineProps({
    // Which screen this belongs to — used as the storage key, so
    // each form remembers its own state.
    formKey: { type: String, required: true },

    // Translation keys, not strings, so the steps are bilingual like
    // everything else. Each entry is a key in appTranslations.
    steps: { type: Array, required: true },

    // Optional one-line note under the steps for the single thing
    // most likely to trip someone up on this screen.
    tipKey: { type: String, default: '' },
});

const { t } = useAppTranslations();

const STORAGE_PREFIX = 'maliyat:instructions:';

const open = ref(true);

function storageKey() {
    return `${STORAGE_PREFIX}${props.formKey}`;
}

onMounted(() => {
    try {
        // Only an explicit "closed" collapses it. A missing key means
        // this person has not used this screen before, and they get
        // the steps.
        open.value = localStorage.getItem(storageKey()) !== 'closed';
    } catch {
        open.value = true;
    }
});

function toggle() {
    open.value = !open.value;

    try {
        localStorage.setItem(storageKey(), open.value ? 'open' : 'closed');
    } catch {
        // Nothing to do — the panel still works for this visit.
    }
}

const stepText = computed(() => props.steps.map((key) => t(key)));
</script>

<template>
    <section class="how-to no-print" :class="{ 'how-to--open': open }">
        <button
            type="button"
            class="how-to__trigger"
            :aria-expanded="open"
            :aria-controls="`how-to-${props.formKey}`"
            @click="toggle"
        >
            <span class="how-to__icon" aria-hidden="true">?</span>
            <span class="how-to__title">{{ t('howToUseLbl') }}</span>
            <span class="how-to__chevron" aria-hidden="true">
                <svg width="14" height="14" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="5 8 10 13 15 8" />
                </svg>
            </span>
        </button>

        <div v-show="open" :id="`how-to-${props.formKey}`" class="how-to__body">
            <ol class="how-to__steps">
                <li v-for="(text, index) in stepText" :key="index" class="how-to__step">
                    <span class="how-to__num" aria-hidden="true">{{ index + 1 }}</span>
                    <span class="how-to__text">{{ text }}</span>
                </li>
            </ol>

            <p v-if="props.tipKey" class="how-to__tip">
                <span class="how-to__tip-label">{{ t('goodToKnowLbl') }}</span>
                {{ t(props.tipKey) }}
            </p>
        </div>
    </section>
</template>

<style scoped>
.how-to {
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    background: var(--color-surface);
    margin-bottom: 16px;
    overflow: hidden;
}

.how-to--open { border-color: var(--color-primary-soft); }

/* The whole header is the tap target — on a phone a small chevron
   alone is a miss waiting to happen. */
.how-to__trigger {
    display: flex;
    align-items: center;
    gap: 10px;
    width: 100%;
    min-height: 46px;
    padding: 10px 14px;
    border: none;
    background: var(--color-primary-soft);
    color: var(--color-primary-dark);
    font-family: inherit;
    font-size: 13.5px;
    font-weight: 600;
    text-align: start;
    cursor: pointer;
}

.how-to__icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 20px;
    height: 20px;
    flex-shrink: 0;
    border-radius: 50%;
    background: var(--color-primary);
    color: var(--color-text-on-primary);
    font-size: 12px;
    font-weight: 700;
    line-height: 1;
}

.how-to__title { flex: 1 1 auto; min-width: 0; }

.how-to__chevron {
    display: inline-flex;
    flex-shrink: 0;
    transition: transform var(--duration-fast) var(--ease-out);
}

.how-to--open .how-to__chevron { transform: rotate(180deg); }

.how-to__body { padding: 14px; }

.how-to__steps {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.how-to__step {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    font-size: 13.5px;
    line-height: 1.55;
    color: var(--color-text-primary);
}

.how-to__num {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    flex-shrink: 0;
    margin-top: 1px;
    border-radius: 50%;
    background: var(--color-surface-alt);
    color: var(--color-primary-dark);
    font-family: var(--font-mono);
    font-size: 11.5px;
    font-weight: 700;
    line-height: 1;
}

.how-to__text { min-width: 0; }

.how-to__tip {
    margin: 14px 0 0;
    padding-top: 12px;
    border-top: 1px dashed var(--color-border);
    font-size: 12.5px;
    line-height: 1.55;
    color: var(--color-text-muted);
}

.how-to__tip-label {
    font-weight: 600;
    color: var(--color-primary-dark);
    margin-inline-end: 4px;
}

/* The chevron points the same way in both directions of text, but
   the rotation origin must not drift in RTL. */
html[dir="rtl"] .how-to__chevron { transform-origin: center; }

@media (max-width: 480px) {
    .how-to { margin-bottom: 14px; }
    .how-to__trigger { min-height: 48px; font-size: 14px; }
    .how-to__body { padding: 12px; }
    .how-to__step { font-size: 14px; }
}
</style>
