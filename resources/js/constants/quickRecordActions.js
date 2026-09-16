export const QUICK_RECORD_ACTIONS = [
    // titleKey/subKey → Home cards & the "New record" sheet (mobile).
    // tabKey → the short step-nav tab label (desktop, see
    // ledger-prototype-v8.html's nav_* strings) — same action, same
    // route, just a shorter label since tabs have limited width.
    //
    // `requires` (optional): a business-type gate — see
    // composables/useBusinessType.js's `hasType()`. Actions with no
    // `requires` always show, which is every action that existed
    // before Production did.
    { key: 'sale',      icon: 'sale',      route: 'app.sales.index',               titleKey: 'action_sale_title',      subKey: 'action_sale_sub',      tabKey: 'tab_sale' },
    { key: 'expense',   icon: 'expense',   route: 'app.expenses.index',            titleKey: 'action_expense_title',   subKey: 'action_expense_sub',   tabKey: 'tab_expense' },
    { key: 'inventory', icon: 'inventory', route: 'app.inventory-purchases.index', titleKey: 'action_inventory_title', subKey: 'action_inventory_sub', tabKey: 'tab_inventory', requiresInventory: true },
    { key: 'production',icon: 'production',route: 'app.production-orders.index',  titleKey: 'action_production_title',subKey: 'action_production_sub',tabKey: 'tab_production', requires: 'production' },
    { key: 'equipment', icon: 'equipment', route: 'app.equipment-purchases.index', titleKey: 'action_equipment_title', subKey: 'action_equipment_sub', tabKey: 'tab_equipment' },
    { key: 'custody',   icon: 'custody',   route: 'app.custodies.index',           titleKey: 'action_custody_title',   subKey: 'action_custody_sub',   tabKey: 'tab_custody' },
    { key: 'payment',   icon: 'cashflow',  route: 'app.payments.index',            titleKey: 'action_payment_title',   subKey: 'action_payment_sub',   tabKey: 'tab_payment' },
];
