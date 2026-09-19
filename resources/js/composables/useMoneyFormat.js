// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — useMoneyFormat
//  Location: resources/js/composables/useMoneyFormat.js
//
//  QA audit M-3: this same currency-code lookup + number-formatting
//  pair used to be copy-pasted into 19 separate files (every
//  Sales/Expenses/report/etc. page, plus EditPaymentsPanel). Now
//  there's exactly one copy — every page imports this instead of
//  keeping its own private version. If the formatting ever needs to
//  change, it changes here once and every screen picks it up
//  automatically, instead of someone having to remember to edit it
//  in 19 places and inevitably missing a few.
//
//  `currency` reads whatever the company chose at setup (AED, EGP,
//  ...) — see Company.currency — falling back to 'EGP' only if
//  that's somehow missing.
//
//  Two formatters, because the pages actually wanted two different
//  shapes, not one:
//    - money(v)                    → "3,453,321.10" (always 2
//      decimals, no currency word attached) — used by every
//      Sales/Expenses/report screen, which shows the currency code
//      separately right next to it in the template, e.g.
//      "{{ currency }} {{ money(v) }}".
//    - moneyWithCurrency(v, decimals = 0) → "EGP 3,453,321" (fewer
//      decimals by default, currency baked into the one string) —
//      used by the Dashboard's headline KPI tiles. Kept as its own
//      function, rather than forcing Dashboard onto the plain
//      money() shape, so this cleanup doesn't change how any screen
//      actually looks.
// ══════════════════════════════════════════════════════════════════

import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useAppTranslations } from '@/composables/useAppTranslations';

export function useMoneyFormat() {
    const page = usePage();
    const { locale } = useAppTranslations();

    const currency = computed(() => page.props.auth?.user?.company?.currency ?? 'EGP');
    const intlLocale = computed(() => (locale.value === 'ar' ? 'ar-EG' : 'en-US'));

    function money(v) {
        return new Intl.NumberFormat(intlLocale.value, {
            minimumFractionDigits: 2, maximumFractionDigits: 2,
        }).format(v || 0);
    }

    function moneyWithCurrency(value, decimals = 0) {
        const formatted = new Intl.NumberFormat(intlLocale.value, {
            maximumFractionDigits: decimals,
        }).format(value ?? 0);

        return `${currency.value} ${formatted}`;
    }

    return { currency, intlLocale, money, moneyWithCurrency };
}
