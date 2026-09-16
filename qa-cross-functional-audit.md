# Maliyat Docs — Cross-Functional QA Audit

**Original audit:** 16 September 2026
**Revised:** 16 September 2026 — after the remediation pass
**Build audited:** working tree at `/media/salah/Software/projects/maliyat-docs.evoqas.com`
**Scope:** 317 source files / 40,326 lines — PHP, Vue, JS, CSS, Blade, migrations, language files

---

## 1. What this revision is

The original audit raised 19 findings. Twelve have been fixed and verified, one resolved itself when the deleted test suites were restored to the tree, and four were reviewed with the owner and closed — the environment file is a local development one, and the unscoped line models, the deploy backup and the large-file note were each accepted as they stand.

**This document now lists only what is still open: two findings, both of them decisions rather than defects.** Fixed items are recorded in §4 for traceability, not re-argued.

Every finding below was re-checked against the tree as it stands today rather than carried over on trust.

**Current state:** `php artisan test` → **588 passed, 2,985 assertions, 0 failures.** The production build succeeds.

**One action still outstanding that no code change can complete:** see §2.

---

## 2. Open

Nothing open is a code defect. Both items change figures the owner has already seen, which makes them business decisions rather than corrections — each one should be decided deliberately, not patched quietly.

### 🟠 H-1 · The Profit & Loss disagrees with itself

**Evidence:** `app/Services/Reports/ReportDataService.php::profitAndLoss()`. The headline total and the category breakdown are computed from different sources:

```php
// line 110 — the headline
$expensesPaid = round(
    (float) $this->periodPayments($from, $to, 'out')->sum('amount')
    + $this->custodyExpenses($from, $to)->sum('total'),
    2
);

// line 116 — the breakdown
$expensesByCategory = Expense::query()
    ->join('categories', ...)
    ->concat($this->custodyExpenses($from, $to))
```

The headline sums **every outgoing payment**. The breakdown reads the `expenses` table plus custody settlements. Money paid for **stock and equipment** is in the first and not the second.

Measured against a live database: headline `1,250.00`, breakdown `650.00` — a gap of exactly the `600.00` paid for inventory.

Buying stock is not an expense; it is exchanging cash for goods you still own, which is why the ledger correctly books it to Inventory. Custody was lifted out of this same hole. Inventory and equipment have not been.

The consequence is twofold: the profit figure the owner reads is **overstated as a loss whenever they restock**, and the two halves of one report cannot be reconciled by anyone who compares them.

**Recommendation:** decide the treatment deliberately. Whichever way it goes, it moves every profit figure already reported — so it wants a conversation, not a commit.

---

### 🟠 H-2 · Production "other costs" always credit Cash

**Evidence:** `app/Services/JournalService.php:188-190`:

```php
if ((float) $order->other_cost_total > 0) {
    $lines[] = ['account' => Account::CASH, 'credit' => (float) $order->other_cost_total];
}
```

Every "other cost" on a production run is posted as cash leaving the till that day. There is no due-date or payment-mode option on that repeater, so the assumption is unconditional — the user is never asked and cannot say otherwise.

A company recording costs it has not actually paid yet — a contractor invoice, a utility share — will see **Cash driven down and potentially negative** while the money is still in the bank. The Trial Balance then shows an abnormal cash balance with nothing in the report to explain it.

**Recommendation:** either give the repeater the same payment-mode choice the rest of the app already uses, or post to Accounts Payable and let the existing Pay Money flow settle it. The second is more consistent with how this app treats every other unpaid obligation.

---

## 3. Outstanding operational action

Not a finding against the code — the code is fixed. Listed because it is the part of C-1 that a commit cannot do.

**Rotate the platform super-admin password on every host where the old seeder has run.** The literal `ChangeMe@2026!` is still in this repository's git history, and any account created by the previous seeder still carries it. The seeder no longer resets an existing password, which is what makes rotation stick — but it also means an already-seeded account keeps the published one until somebody signs in and changes it.

**Also still outstanding:** the production `.env` was pasted into a chat transcript earlier in this project's history. The `DB_PASSWORD` and `MAIL_PASSWORD` in it should be treated as disclosed and rotated.

---

## 4. Fixed since the original audit

Recorded so this document can be read against the original. Each is covered by a test in `tests/Feature/AuditFixesTest.php` (39 tests, 115 assertions), named by its original finding ID.

| Original | Finding | Resolution |
|---|---|---|
| C-1 | The regression safety net has been deleted | **Resolved.** All 25 named suites are present again; the suite now reports 588 tests against 165 at the time of the audit. |
| C-2 | Working super-admin credentials committed to the repository | The password now comes from `DEFAULT_PASSWORD` in the gitignored `.env`, with no fallback — an absent or weak value throws rather than seeding an account nobody can use. `updateOrCreate` replaced with `firstOrNew`, so **a re-seed no longer resets a password the owner has changed**, and the console no longer prints any credential. A latent bug surfaced while fixing it: `email_verified_at` is not in `User::$fillable`, so both the old code and the first draft of the new one silently dropped it, leaving the super-admin unverified. It is now assigned directly. |
| H-1 | Arabic validation messages regressed to English | `lang/ar/validation.php` restored — 22 message keys and 27 attribute names; `lang/en/validation.php` added with matching attribute keys. |
| H-2 | Depreciation can post the same month twice | The ledger entry and the `last_depreciated_through` write are now one `DB::transaction()`. A failure between them can no longer leave a posted month unrecorded and repostable. |
| M-1 | The Production feature gate is frontend-only | `EnsureBusinessType` middleware added and registered as `business-type`; production routes gated server-side, answering 404 to a company without the feature. |
| M-2 | Changing your password does not end other sessions | `Auth::logoutOtherDevices()` now runs **after** the password update, followed by `session()->regenerate()`. `AuthenticateSession` registered in `bootstrap/app.php` and applied to both authenticated route groups — without it the call would have been decorative. |
| M-3 | Two orphaned report components | `Pages/App/Reports/Journal.vue` and `TrialBalance.vue` deleted. |
| M-4 | Stale bootstrap copy in the middleware folder | `app/Http/Middleware/app.php` deleted. |
| M-5 | InPractice leftover | `app/Enums/CaseDifficulty.php` deleted. |
| M-7 | Production orders can be deleted but not corrected | `ProductionOrderService::update()` added, sharing a private `cost()` with `create()`. The old entry is reversed rather than edited and the reversal carries the original date; an Edit action and editing banner added to the screen. |
| L-1 | Icon button without an accessible name | `aria-label` and `type="button"` on every icon control in `Register.vue`, `Login.vue` and `IpLoginShell.vue`. |
| L-2 | Inconsistent `authorize()` | `UpdateItemRequest` and `UpdatePaymentRequest` now match every sibling: `(bool) $this->user()?->company_id`. |
| L-4 | `UserController::update()` writes twice without a transaction | The details write and the password write are now one `DB::transaction()`. |

**Also repaired during this pass** — drift found by running the restored suite, not part of the original findings:

- Seven test suites posted registrations without `business_types`, which the request has required since the business-type feature shipped. The live `Register.vue` **does** send it, so this was stale test data rather than a product defect — but 16 tests were failing on it.
- `resources/js/Composables/` had been renamed to `composables/`; two tests and three source comments still pointed at the old capitalised path, which fails on a case-sensitive filesystem.
- `.env.example` had lost the comments explaining that `MAIL_MAILER=log` *sends nothing* and that Laravel 11 reads `MAIL_SCHEME` rather than `MAIL_ENCRYPTION` — the exact pair of facts behind the mail outage earlier in this project. It now also documents `SUPER_ADMIN_EMAIL` and a deliberately blank `DEFAULT_PASSWORD`.
- One form instruction used the word "reconciles"; reworded in both languages to say what it means.

---

## 5. Verified sound

Unchanged from the original audit and re-confirmed. These need no action; several are better than typical.

**Multi-tenancy.** `BelongsToCompany` applies a global scope and auto-fills `company_id` on 19 of 24 models. Every form request validating a foreign key scopes `Rule::exists()` by `company_id`. Cross-company route-model binding resolves as 404, not 403 — correct, because it does not confirm the record exists.

**SQL injection.** Zero unsafe interpolation. The one raw query taking a table name (`DashboardController::unsettledCondition()`) receives it from a hardcoded map and PDO-quotes the model class. Every other `whereRaw` uses bound parameters.

**Dangerous functions.** No `eval`, `exec`, `shell_exec`, `system`, `passthru`, `popen`, `unserialize`, `extract` or `assert` anywhere in `app/` or `routes/`.

**XSS.** Eleven `v-html` uses, all rendering Laravel's own paginator labels or the internal icon map. No user-supplied content reaches an HTML sink.

**Secrets.** With the super-admin password removed, no credential remains hardcoded anywhere in the tree. `.env`, `.env.backup` and `.env.production` are all gitignored.

**Indexing.** Every financial table carries `['company_id', 'date']` or better. `accounts` has `unique(['company_id','code'])`. `payments` uses `$table->morphs('payable')`, which auto-indexes `(payable_type, payable_id)` — the exact composite the open-invoice worklist's correlated subquery needs. Production tables carry two composite indexes each.

**Right-to-left.** 57 logical-property rules (`margin-inline`, `padding-inline`, `text-align: start/end`, `[dir="rtl"]`) and **zero** hardcoded `left:`/`right:`/`margin-left`/`padding-right` in 2,074 lines of CSS. Unusually disciplined.

**Bilingual coverage.** `appTranslations.js` — 497 English keys, 497 Arabic, none missing either way. `authTranslations.js` — 28/28. `lang/en` and `lang/ar` have identical key counts across all seven shared files.

**Accessibility basics.** Zero `<img>` without `alt` across 47 components, and every icon control is labelled.

**Double-entry engine.** `JournalService::post()` is the only writer of journal rows and rejects any unbalanced set. Corrections reverse rather than edit. Reversals carry the original entry's date, so a correction settles inside the period it belongs to.

**Transaction discipline.** Every multi-write operation in the application is wrapped. There are no longer any exceptions.

**Debug hygiene.** No `console.log`, `debugger`, `dd()`, `dump()` or `var_dump()` anywhere in the shipped source.

---

## 6. What this audit did not cover

Stated so the clean sections are not read as a guarantee they do not carry:

- **Load and performance under real volume.** Indexing was reviewed statically; no query was profiled against a large dataset.
- **Penetration testing.** Authorization was read, not attacked.
- **Browser and device matrix.** RTL and responsive rules were reviewed in source, not rendered across real browsers.
- **The deployed server.** Everything here is the source tree. The running configuration — cached config, file permissions, cron, queue workers, DNS — can differ, and previously did. `php artisan mail:diagnose` reports what the application actually resolved at runtime and is the right starting point there.
- **Every one of the 40,326 lines individually.** Roughly 60 files — the correctness core — were read in full. The rest were covered by automated checks across 100% of files for the defect classes named in the original audit.
- **Business-rule correctness beyond accounting mechanics.** Whether the VAT treatment, depreciation schedule and costing method match Egyptian practice is a question for an accountant, not a QA engineer.

---

*Prepared from static analysis across all 317 source files, full reading of the correctness-critical subset, and live execution against a MySQL database, the production build, and the application's own test suite.*
