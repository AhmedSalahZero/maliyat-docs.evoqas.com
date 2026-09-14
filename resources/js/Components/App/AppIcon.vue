<script setup>
// ══════════════════════════════════════════════════════════════════
//  Maliyat Docs — AppIcon.vue
//  Location: resources/js/Components/App/AppIcon.vue
//
//  <AppIcon name="sale" />  — renders one of the icons below as an
//  inline SVG (currentColor, so it inherits text color and reacts
//  to Light/Dark theme automatically — no separate dark-mode icon
//  set needed).
//
//  The six "sale / expense / inventory / equipment / custody /
//  cashflow" paths are copied 1:1 from ledger-prototype-v8.html's
//  ICONS table so the icon a user sees on the Home card is pixel-
//  identical to the one they'll see inside that feature later.
// ══════════════════════════════════════════════════════════════════

const props = defineProps({
    name: { type: String, required: true },
});

// Paths copied verbatim from ledger-prototype-v8.html → ICONS
const PROTOTYPE_ICONS = {
    sale:          '<path d="M6 8V6a4 4 0 0 1 8 0v2"/><rect x="3" y="8" width="14" height="12" rx="2"/>',
    expense:       '<rect x="5" y="3" width="12" height="18" rx="1.5"/><line x1="8" y1="8" x2="14" y2="8"/><line x1="8" y1="12" x2="14" y2="12"/><line x1="8" y1="16" x2="12" y2="16"/>',
    inventory:     '<path d="M3 7l7-4 7 4-7 4-7-4z"/><path d="M3 7v9l7 4 7-4V7"/><line x1="10" y1="11" x2="10" y2="20"/>',
    equipment:     '<rect x="1" y="8" width="12" height="9" rx="1"/><path d="M13 11h4l3 3v3h-7z"/><circle cx="5.5" cy="19" r="1.6"/><circle cx="16.5" cy="19" r="1.6"/>',
    custody:       '<rect x="2" y="6" width="16" height="11" rx="2"/><path d="M13 11.5h3"/><path d="M2 9.5h16"/>',
    cashflow:      '<polyline points="3 15 8 9 12 13 18 5"/><polyline points="18 10 18 5 13 5"/>',
    ledger:        '<rect x="3" y="4.5" width="2" height="2"/><line x1="8" y1="5.5" x2="19" y2="5.5"/><rect x="3" y="10" width="2" height="2"/><line x1="8" y1="11" x2="19" y2="11"/><rect x="3" y="15.5" width="2" height="2"/><line x1="8" y1="16.5" x2="19" y2="16.5"/>',
    pl:            '<line x1="4" y1="19" x2="4" y2="12" stroke-width="3"/><line x1="9" y1="19" x2="9" y2="6" stroke-width="3"/><line x1="14" y1="19" x2="14" y2="14" stroke-width="3"/><line x1="19" y1="19" x2="19" y2="3" stroke-width="3"/>',
    statement_cust:'<circle cx="10" cy="7" r="3.2"/><path d="M3 19c0-4 3.2-6.5 7-6.5s7 2.5 7 6.5"/>',
    statement_supp:'<rect x="4" y="4" width="13" height="16"/><rect x="7" y="7.5" width="2" height="2"/><rect x="12" y="7.5" width="2" height="2"/><rect x="7" y="12" width="2" height="2"/><rect x="12" y="12" width="2" height="2"/><line x1="8.5" y1="20" x2="12.5" y2="20"/>',
    inv_statement: '<rect x="3" y="4" width="15" height="6.5" rx="1.5"/><rect x="3" y="12.5" width="15" height="6.5" rx="1.5"/>',
};

// New icons, drawn to match the same 20x20 / stroke style
const NEW_ICONS = {
    home:     '<path d="M3 10.5 10 4l7 6.5"/><path d="M5 9v7a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V9"/><path d="M8 17v-4h4v4"/>',
    plus:     '<line x1="10" y1="4" x2="10" y2="16"/><line x1="4" y1="10" x2="16" y2="10"/>',
    close:    '<line x1="5" y1="5" x2="15" y2="15"/><line x1="15" y1="5" x2="5" y2="15"/>',
    menu:     '<line x1="3" y1="6" x2="17" y2="6"/><line x1="3" y1="10" x2="17" y2="10"/><line x1="3" y1="14" x2="17" y2="14"/>',
    team:     '<circle cx="7" cy="7" r="2.6"/><circle cx="14" cy="8" r="2.2"/><path d="M2.5 17c0-3.1 2-5 4.5-5s4.5 1.9 4.5 5"/><path d="M12 12.3c2 .2 3.5 1.9 3.5 4.7"/>',
    tag:      '<path d="M10 3h5a2 2 0 0 1 2 2v5l-8.5 8.5a1.5 1.5 0 0 1-2 0L3 15a1.5 1.5 0 0 1 0-2z"/><circle cx="13.5" cy="6.5" r="1.2"/>',
    box:      '<path d="M3 6.5 10 3l7 3.5-7 3.5-7-3.5z"/><path d="M3 6.5v7L10 17l7-3.5v-7"/><line x1="10" y1="10" x2="10" y2="17"/>',
    gear:     '<circle cx="10" cy="10" r="2.6"/><path d="M10 3v1.8M10 15.2V17M17 10h-1.8M4.8 10H3M14.7 5.3l-1.3 1.3M6.6 13.1l-1.3 1.3M14.7 14.7l-1.3-1.3M6.6 6.9 5.3 5.6"/>',
    logout:   '<path d="M8 17H5a1.5 1.5 0 0 1-1.5-1.5v-11A1.5 1.5 0 0 1 5 3h3"/><path d="M13 14l4-4-4-4"/><line x1="17" y1="10" x2="7.5" y2="10"/>',
    globe:    '<circle cx="10" cy="10" r="7.2"/><path d="M2.8 10h14.4M10 2.8c2 2.2 3 4.8 3 7.2s-1 5-3 7.2c-2-2.2-3-4.8-3-7.2s1-5 3-7.2z"/>',
    chevron:  '<polyline points="7.5 5 12.5 10 7.5 15"/>',
    building: '<rect x="4" y="2.5" width="12" height="15" rx="1"/><line x1="7" y1="6" x2="7" y2="6.01"/><line x1="10" y1="6" x2="10" y2="6.01"/><line x1="13" y1="6" x2="13" y2="6.01"/><line x1="7" y1="9.5" x2="7" y2="9.51"/><line x1="10" y1="9.5" x2="10" y2="9.51"/><line x1="13" y1="9.5" x2="13" y2="9.51"/><path d="M8 17.5v-3.5h4v3.5"/>',
    moon:     '<path d="M16.5 12.3A6.8 6.8 0 0 1 7.7 3.5a7 7 0 1 0 8.8 8.8z"/>',
    sun:      '<circle cx="10" cy="10" r="3.4"/><path d="M10 2.5v2M10 15.5v2M17.5 10h-2M4.5 10h-2M15.3 4.7l-1.4 1.4M6.1 13.9l-1.4 1.4M15.3 15.3l-1.4-1.4M6.1 6.1 4.7 4.7"/>',
    pencil:   '<path d="M13.5 3.5a1.8 1.8 0 0 1 2.5 2.5L6.5 15.5 3 16.5l1-3.5z"/><line x1="12" y1="5" x2="15" y2="8"/>',
};

const ALL_ICONS = { ...PROTOTYPE_ICONS, ...NEW_ICONS };
</script>

<template>
    <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7"
         stroke-linecap="round" stroke-linejoin="round"
         v-html="ALL_ICONS[props.name] || ''"></svg>
</template>
