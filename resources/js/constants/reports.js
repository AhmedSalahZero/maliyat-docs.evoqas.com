export const REPORTS = [
    { key: 'ledger',   icon: 'ledger',         route: 'app.reports.ledger',              titleKey: 'report_ledger',              subKey: 'report_ledger_sub',              tabKey: 'tab_ledger' },
    { key: 'pl',       icon: 'pl',             route: 'app.reports.profit-loss',         titleKey: 'report_pl',                  subKey: 'report_pl_sub',                  tabKey: 'tab_pl' },
    { key: 'cust',     icon: 'statement_cust', route: 'app.reports.customer-statement',  titleKey: 'report_customer_statement',  subKey: 'report_customer_statement_sub',  tabKey: 'tab_statement_cust' },
    { key: 'supp',     icon: 'statement_supp', route: 'app.reports.supplier-statement',  titleKey: 'report_supplier_statement',  subKey: 'report_supplier_statement_sub',  tabKey: 'tab_statement_supp' },
    { key: 'inv',      icon: 'inv_statement',  route: 'app.reports.inventory-statement', titleKey: 'report_inventory_statement', subKey: 'report_inventory_statement_sub', tabKey: 'tab_inv_statement', requiresInventory: true },
    { key: 'cashflow', icon: 'cashflow',       route: 'app.reports.cash-flow',           titleKey: 'report_cashflow',            subKey: 'report_cashflow_sub',            tabKey: 'tab_cashflow' },
    { key: 'owner_stmt', icon: 'owner',        route: 'app.reports.owner-statement',     titleKey: 'report_owner_statement',     subKey: 'report_owner_statement_sub',     tabKey: 'tab_owner_statement' },
    // Trial Balance and Journal live behind this one tile — they are
    // the auditor's two screens and are always read together. See
    // ReportController::externalAudit().
    { key: 'audit',    icon: 'audit',          route: 'app.reports.external-audit',      titleKey: 'report_external_audit',      subKey: 'report_external_audit_sub',      tabKey: 'tab_external_audit' },
];
