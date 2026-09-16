// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — useBusinessType
//  Location: resources/js/composables/useBusinessType.js
//
//  What kind of business this company is (Service / Trading /
//  Production, multi-select), read from the shared company props
//  (HandleInertiaRequests::resolveCompany). Drives which tabs and
//  fields show up:
//    - Service-only     → Inventory (Items, Inventory Purchases,
//                          Inventory Statement) is hidden entirely.
//    - Production        → the Production Orders tab appears, and
//                          Items gets a Raw Material/Product picker.
//
//  This is presentation only, same caveat as usePermissions: the
//  server doesn't gate these routes by business type today (see
//  ProductionOrderController's doc comment) — hiding the tab is a
//  courtesy so a company that never turned Production on never sees
//  a screen that wouldn't have anything useful in it yet.
// ══════════════════════════════════════════════════════════════════

import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

export function useBusinessType() {
    const page = usePage();

    // businessTypes() on the backend always returns at least
    // ['trading'], but default to it here too in case props haven't
    // loaded yet on first render.
    const businessTypes = computed(() => page.props.auth?.user?.company?.business_types ?? ['trading']);

    function hasType(type) {
        return businessTypes.value.includes(type);
    }

    const isProduction = computed(() => hasType('production'));
    const isTrading    = computed(() => hasType('trading'));
    const isService    = computed(() => hasType('service'));

    // Nothing to buy, stock, or sell as goods.
    const needsInventory = computed(() => isTrading.value || isProduction.value);

    /**
     * Filters an array of items (QUICK_RECORD_ACTIONS, REPORTS) down
     * to what this company's business type should see. An item with
     * a `requires` key needs that exact business type; one with
     * `requiresInventory: true` needs needsInventory; anything else
     * always shows.
     */
    function visibleFor(actions) {
        return actions.filter((action) => {
            if (action.requiresInventory) return needsInventory.value;
            if (action.requires) return hasType(action.requires);
            return true;
        });
    }

    return { businessTypes, hasType, isProduction, isTrading, isService, needsInventory, visibleFor };
}
