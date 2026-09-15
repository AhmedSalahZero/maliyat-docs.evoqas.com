# Maliyat Docs — QA Audit Report

**Prepared for:** Project Owner (non-technical)
**Status:** Re-verified, extended and updated — 15 September 2026
**Method:** Every finding in the previous version of this report was re-checked against the code as it stands today, not taken on trust. Each one was confirmed or ruled out by locating the exact file and, where it mattered, running the application to see the failure happen. Everything confirmed and unambiguous was then fixed, and automated tests were written so it cannot come back unnoticed.

**Current state:** 382 automated tests passing (2,160 assertions). Production build succeeds.

---

## 1. What changed since the last report

All three Critical findings and both unambiguous High findings were confirmed real and are now fixed. One item no longer applies. What remains is in Section 3 — none of it will break the app.

| # | Finding | Outcome |
|---|---|---|
| 2.1 | Opening Balance crashes on submit | **Fixed** — the missing validation class was written; 16 tests now cover the feature |
| 2.2 | Two files both claiming to be the Dashboard | **Fixed** — the stale copy deleted |
| 2.3 | Two files both claiming to be the login-activity service | **Fixed** — the safer version kept, the weaker one removed |
| 3.1 | Broken file-path reference (letter casing) | **Fixed** — and it was far worse than reported; see 2.1 below |
| 3.3 | Secure cookie not on by default | **Fixed** — now defaults to on in production |
| 4.1 | Leftover code from the earlier "InPractice" project | **Fixed** — 53 files removed |
| 4.2 | Slow query pattern in the old Dashboard file | **Fixed** — resolved by deleting that file |
| 4.6 | Export needs two extra packages installed | **No longer applies** — both are installed |

A second pass then went looking specifically at the accounting itself, the limits on what can be typed into a money or date field, and who is allowed to delete things — three areas the previous round had not opened. Four problems came out of it, all four reproduced against a live database before anything was changed, and all four now fixed and covered by tests:

| Finding | Outcome |
|---|---|
| Correcting an old invoice inflated the month it was in | **Fixed** — 7 tests |
| Receipts tagged to a customer/supplier never reached their statement | **Fixed** — 9 tests |
| No upper limit on any amount or date; payments could exceed what was owed | **Fixed** — 21 tests |
| Any employee could permanently delete any financial record | **Fixed** — 14 tests |

A third pass went into the custody (عهدة) feature, the limits around repeated submissions and stock, and the screens a signed-out user can reach. Everything below is fixed and covered by tests:

| Finding | Outcome |
|---|---|
| A custody settlement was always dated today, splitting one event across two months | **Fixed** — 15 tests |
| A float handed to an employee counted as an expense, and the change coming back as income | **Fixed** — same 15 tests |
| The same form submitted twice recorded two invoices and two payments | **Fixed** — 11 tests |
| A sale could take an item below zero stock | **Fixed** — 12 tests |
| The password-confirmation screen still wore Laravel's stock design | **Fixed** — 23 tests across all auth screens |
| Inline edit forms rendered their fields with no styling at all | **Fixed** |

And three things were added or reshaped, not repaired:

| Change | What it is |
|---|---|
| **Activity** (admin) | A new screen showing who opened the app and when — one row per person per day. 19 tests |
| **External Audit** | Trial Balance and Journal merged behind one entry point with a toggle. 13 tests |
| **Renewal contact** | The trial countdown now says who to talk to, not just how many days are left. 9 tests |

A fourth pass covered the date filters on the reports that had none, and added a how-to panel to every entry screen:

| Change | What it is |
|---|---|
| **Date ranges everywhere** | The customer statement, supplier statement and inventory statement had no date filter at all. All three now take one — with a balance brought forward, so narrowing the range cannot misstate the position. 27 tests |
| **How-to panels** | Every entry screen now explains itself in numbered plain-language steps, collapsed after the first visit. 24 tests |
| **Custody posting** (follow-up) | The ledger entry for handing a float out still sat outside its transaction — the same fix already applied to settling one |

Section 2 covers the ones worth understanding.

---

## 2. Things worth knowing about what was found

### 2.1 The application could not be built at all

The previous report described the file-casing problem as a landmine — harmless today, dangerous later. By the time this re-check ran it had already gone off.

Thirty-eight files were asking for folders by the wrong name: `Composables` and `Stores` with a capital letter when the folders on disk had lowercase names, and one file asking for `utils` when the folder is `Utils`. Windows and Mac laptops ignore that difference; Linux servers — which is where this app runs — do not.

The result was that **the production build failed outright**, including on the app's own main entry file. Nothing could have been deployed.

This is fixed: the folders were renamed to match the rest of the project's naming, every import was brought in line, and the build now succeeds. There is an automated test that fails if this mismatch ever reappears.

**Why it kept happening:** this exact problem has now occurred twice, in opposite directions. It is not a one-off typo — it is what happens when a project with mixed folder naming is edited on a machine that doesn't enforce the difference. The test now in place is the permanent guard.

### 2.2 Anyone could have set a company's opening balance

While writing the missing Opening Balance validation class, a second problem surfaced that the previous report did not catch.

The Opening Balance screen correctly hides itself from ordinary employees, and the "clear and start again" action correctly refused anyone who wasn't a company admin. But the **save** action had no such check. The rule existed only in the screen, not in the server — so an employee who bypassed the screen could have set, or overwritten, their company's entire opening financial position.

The new validation class enforces company-admin only, matching what the screen and the reset action already assumed. Two tests cover it.

### 2.3 Fixing a typo on an old invoice quietly inflated that month

This one is the most consequential thing in this report, and it left no trace on screen.

Correcting a past record — changing an invoice from 1,000 to 1,200, say — does not overwrite the old figure in the accounts. That is correct practice: the original is cancelled by an opposite entry and the new figure posted, so the books keep the whole story. The problem was **when** the cancellation was dated. It was stamped with today's date, while the correction went back to the invoice's own date.

So a March invoice corrected in September left March holding the original 1,000 *and* the corrected 1,200, with the cancelling entry sitting harmlessly in September where it offset nothing. Running against a live database, March read **2,200**.

Every closed month drifted upward by the size of each correction made after it closed. Nothing flagged it, and the totals still added up internally, so the only way to notice would have been to already know the right answer.

The cancelling entry now carries the date of the entry it cancels, so the pair settles inside the month it belongs to. Correcting, re-dating and deleting old records are each covered by their own test, and the record of *when* a correction was made is untouched — it was never the date field that carried that.

### 2.4 Any employee could delete any financial record

Deleting a sale here also deletes the payments recorded against it — the money that came in disappears along with the invoice. The records are removed outright; there is no recycle bin, and nothing logs who removed them.

That action had **no permission check at all**: not on the server, not in the screen. Every employee saw the Delete button, and it worked, on every sale, expense, purchase, custody and payment in the company. The previous report's Section 3.2 said permissions were correctly enforced everywhere they were checked — which was true, but Delete had not been among the things checked.

Deleting is now limited to a company administrator, in one shared place rather than repeated in each screen, and the button no longer appears for anyone else. **Editing deliberately stays open to employees:** an edit leaves a full trail and never touches payments, so a mistake there is visible and reversible. Deleting is neither. Fourteen tests cover both halves of that line.

### 2.5 A petty-cash float was being reported as a loss, and its change as income

Handing an employee 10,000 to spend on the company's behalf is not spending 10,000 — the company still owns every penny of it until they say what it went on. The Profit & Loss counted the hand-out as an expense the day it left the till, and counted the unspent change as **income** the day it came back.

A 10,000 float, of which 8,000 was spent, therefore read as a 10,000 loss in one month and a 2,000 profit in another. Neither number described anything that happened to the business, and the two months were usually different months.

Which brings up the second half. **The settlement date was fixed to today** — there was no field for it on the form at all. A float handed out in July and squared up in July, but keyed into the app in September, put the hand-out in July and the change in September.

Both are fixed: the form now asks when the float was actually settled (and refuses a date before it was handed out), and the Profit & Loss counts what the holder reports they spent, on the day they reported it. A float still out in the open contributes nothing, which is right — nobody yet knows what it bought. The Cash Flow report still shows the raw movements, because cash genuinely did leave and come back, and that is the question that report answers.

### 2.6 A double-click recorded everything twice

Nothing stopped the same form being submitted twice. A double-click on "Record sale", a phone resending on a patchy connection, or a second impatient tap while the first request was still travelling each produced **two invoices and two payments** — the same money counted twice, in the one system whose whole job is counting money correctly. The owner would see two identical rows and have to work out which was the mistake, and deleting the wrong one takes its payment with it.

The same submission is now recognised for a few seconds and sent back with an explanation instead of being written again. The window is deliberately short: entering the same figure twice on purpose is a real thing people do — two identical cash sales in a row — and after a few seconds that goes through normally.

Alongside it, **a sale can no longer take an item below zero stock.** Selling 500 of something never purchased used to be accepted and left the Inventory Statement reading -500. It also overstated profit: cost of goods sold is priced from an item's average purchase cost, and an item never bought has no average, so those sales recorded revenue with no cost against it.

### 2.7 Three reports could not be asked about a period

The Ledger, Profit & Loss and Cash Flow all took a date range. The **customer statement, supplier statement and inventory statement did not** — they returned all of history with no way to ask "what happened last quarter".

Adding a filter naively would have been worse than leaving it out. A customer who owed 5,000 coming into March and paid 2,000 during March would, on a March-only view, appear to be **2,000 in credit** — the exact opposite of the truth. So each statement now opens with a *balance brought forward*: everything before the range collapsed into one figure, with the period's movements running on top of it. That is how a statement of account is meant to read, and it is what makes the range safe to use.

Stock had the same trap in a different shape. A range must never hide purchases made before it, or an item bought in January and sold in March would look like it went below zero. Closing stock counts everything up to the **end** of the window; only the "bought" and "sold" columns are bounded by its start.

The exports follow the screen, so a downloaded file always covers the range that was showing when the button was pressed.

### 2.8 Every entry screen now explains itself

This app is for somebody who runs a shop, not somebody who trained in bookkeeping — and several screens ask questions that are obvious only once you know the answer. What goes in "what is inside one unit"? Is handing an employee cash an expense? Which screen does a delivery van belong on?

Each entry screen now carries a short numbered panel at the top: what to do, in the order the fields appear, in plain language. Every panel ends with the one thing most likely to trip somebody up on that particular screen.

It is collapsed after the first visit and remembers that per screen, so a newcomer gets the walkthrough and somebody recording their fortieth sale never sees it again. On a phone the header is a full-width tap target and the steps reflow to one column.

A test refuses any step that uses accounting words — *debit*, *credit*, *ledger*, *journal*, *accrual*, *reconcile*. If a step needs one of those, it is explaining the bookkeeping rather than the screen, which is the failure this whole feature exists to prevent.

---

## 3. Remaining items

None of these will break the application. They are judgement calls and long-term maintainability work.

### 3.1 🟠 Password policy is thin for a financial application — **needs your decision**

Passwords currently need 8 characters, at least one letter and at least one number. For an app holding a business's complete financial records, that is on the weak side.

This was **deliberately not changed**, because tightening it is a trade-off rather than a straightforward fix, and the trade-off is yours to make:

| Option | Benefit | Cost |
|---|---|---|
| Check passwords against known-breached lists | Blocks passwords already leaked publicly — the single highest-value change | Adds a brief external lookup when someone sets a password |
| Raise the minimum to 10–12 characters | Meaningfully harder to guess | Mild friction for users |
| Require capitals and symbols | Traditional | Modern security guidance actively discourages this — it pushes people toward `Password1!` and writing passwords down |

**Recommendation:** the breach-list check plus a longer minimum. Skip the capitals-and-symbols rule. Say the word and it's a small change.

### 3.2 🟡 Permission rules are still written out by hand, screen by screen

The previous version of this report described this as tidiness work and said the rules were correctly enforced everywhere they had been checked. That was too generous, and section 2.4 is why: the checks were correct where they existed, but **whether one existed at all was decided file by file**, and on Delete none did. The weakness was never how the rules are written — it was that nothing makes a missing rule visible.

Delete is now settled, in one shared place. What remains is the rest: each screen still carries its own hand-written checks rather than there being a single definition per record type of who may do what. As more screens are added, "did we remember the check on this one?" stays a question someone has to answer by reading every file — and the answer was already "no" once. Laravel has a built-in feature for this (Policies) that collapses it into one place per record type and makes an omission obvious.

**Worth doing before the next significant batch of screens, not after.**

### 3.3 🟡 A few screens are large single files

| File | Lines |
|---|---|
| `Pages/Auth/Login.vue` | 1,005 |
| `Pages/Auth/Register.vue` | 630 |
| `Pages/App/Sales/Index.vue` | 533 |
| `Pages/App/Expenses/Index.vue` | 510 |
| `Pages/App/Lookups/Index.vue` | 509 |
| `Pages/App/InventoryPurchases/Index.vue` | 505 |

Each combines the page layout, its form, and its calculation logic. Nothing is broken; these are simply the files where a future change is most likely to have an unintended side effect, because there's a lot sharing one space.

The two login/register files are the clearest candidates — most of their bulk is styling that could live alongside the rest of the app's styles instead of inside the page.

### 3.4 🟡 Trial-expiry emails still depend on server setup

This finding originally covered **two** jobs. One of them — monthly depreciation — has since been taken off the scheduler entirely and is now driven by the app itself: the first page a company opens each day posts whatever it owes, so no server configuration is involved at all. A company nobody visits posts nothing, which is correct, and is caught up in full the instant somebody returns.

What remains is the **trial-expiry email**, and it cannot be solved the same way. Its entire purpose is to reach somebody who is *not* logged in, so a trigger that fires when people visit is structurally wrong for it.

It still does nothing unless the hosting server has a single line of configuration (a "cron" entry) pointing at the app's scheduler — and if that line is missing there is **no error and no warning**.

There is also a second, separate dependency worth knowing about: the email is queued, so it additionally needs a background worker (`queue:work`) running. That one is in better shape than this report previously suggested — the deploy script restarts a supervisor-managed worker on every deploy, so it is provisioned and restarts itself if it falls over. The cron line is the part with nothing watching it.

**Recommendation:** an external uptime service (cron-job.org, UptimeRobot — both free) pinging a signed URL on a schedule is more dependable here than the server's own cron, because it is independent of the hosting configuration *and* it alerts you when it stops working, which gives you the monitoring for free. The same endpoint can drain the queue, covering the password-reset problem at the same time.

### 3.5 ⚪ Twelve unused columns remain on the user table

The `users` table still carries columns from the earlier project: `nickname`, `profession`, `experience_level`, `sector`, `bio`, `avatar`, `show_real_name`, and five `notify_*` flags. Nothing in the app writes to them meaningfully.

They were **left in place on purpose.** Dropping database columns cannot be undone, and the benefit is tidiness rather than function. Worth doing eventually, as its own carefully-taken step with a database backup — not bundled into other work.

---

## 4. What was checked and found sound

These were examined closely and need no action. Listed so you know they were covered, not skipped.

- **Multi-company data isolation.** One company's staff cannot see another company's books. Enforced automatically at the database level, and now covered by tests that deliberately try to cross the boundary and confirm they're refused.
- **The double-entry bookkeeping engine.** Rejects any transaction where debits and credits don't match, and corrects mistakes by reversing and re-posting rather than editing history — the correct accounting practice. Worth noting that this structure was never the problem in 2.3: the mechanism was right and only the date on one half of it was wrong, which is exactly why the symptom was invisible while the books still balanced.
- **Rate limiting on login, registration and password reset.** In place and working.
- **No hardcoded passwords, keys or secrets** anywhere in the source, and no use of dangerous PHP functions.
- **All eight reports are built and working** — Ledger, Profit & Loss, Customer Statement, Supplier Statement, Inventory Statement, Cash Flow, and the Trial Balance and Journal (now reached together under **External Audit**). Each renders real data and exports to PDF and Excel. Two gaps were closed this round: money received or paid without an invoice behind it, tagged to a customer or supplier, never reached their statement even though tagging it exists for no other purpose; and the Profit & Loss was mis-handling petty-cash floats (see 2.5). Old links to the Trial Balance and Journal still work — they forward to the right half of the new screen, so an auditor's bookmark is not broken.
- **Every report takes a date range**, and every entry screen explains how to use itself — see 2.7 and 2.8.
- **Every screen a signed-out user can reach is the app's own.** The password-confirmation page was still wearing Laravel's stock design; it now matches the rest, and the five unused framework components it was keeping alive have been removed so nothing else reaches for them by accident. The forgot-password, reset-password and email-verification flows were each run end to end and work, and their emails are branded rather than default.

---

## 5. Deliberate decisions — not gaps

These come up in any review of this codebase, so they are written down here to stop them being re-raised as findings. Each is a decision, not an oversight.

| Not built | Why |
|---|---|
| **VAT on expenses and equipment purchases** | Only sales and inventory purchases carry VAT. This is intentional for how the product is used today, and changing it is a business decision rather than a fix. |
| **A self-service way to renew a subscription** | Renewal is arranged with a person. The app's part of that job is to warn the customer in good time and tell them who to contact — which it now does (see the countdown banner). |
| **A balance sheet** | The Trial Balance gives the auditor what they need for now. A full balance sheet is a feature, not a missing piece. |

---

## 6. Suggested order

1. **Decide on the password policy** (3.1) — the only remaining item with a real security dimension, and it needs your call rather than a developer's.
2. **Set the renewal contact** — one line of configuration (`SUPPORT_EMAIL` / `SUPPORT_WHATSAPP`). Until it is set, the countdown banner warns customers without telling them who to call.
3. **Sort out the trial email's cron line** (3.4) — cheap, and nothing is watching that one line today.
4. **Centralise the permission rules** (3.2). Section 2.4 showed this is not tidiness: a screen shipped with no permission check at all and nothing made that visible.
5. Split the largest screens (3.3) when one of them next needs substantial work — not as a standalone exercise.
6. Drop the unused columns (3.5) whenever there's an unhurried moment and a fresh backup.

---

*Scope: source code review plus running the application locally against a live database — the build, the full automated test suite, and the specific failing paths were each executed rather than reasoned about. Not included: load testing and penetration testing.*
