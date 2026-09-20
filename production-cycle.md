# The Production Cycle in Maliyat — from receiving material to selling the product

**Version:** 16 September 2026
**Applies to:** companies with **Production** enabled in their Business Type
**Purpose:** a numeric record of what happens in the books at every step, told through one continuous worked example whose figures can be traced from beginning to end.

> Every figure in this document was run through the application and reconciled against the journal entries it produced. It is not arithmetic done on paper. The trial balance at the end of the example balances at **7,632.00** on both sides.

*(النسخة العربية: [production-cycle.ar.md](production-cycle.ar.md))*

---

## 1. The idea, in three lines

This application is built for a **workshop**, not a factory. A production order therefore carries two cost lines and no more:

> **Batch cost = raw materials consumed + the day's labor**

Anything else — electricity, delivery, a contractor — is **not part of a production order**. It is recorded as an ordinary Expense, on the screen that can name a supplier, a due date, and whether the money has actually left yet. §9 explains why.

---

## 2. Map of the cycle

| # | Step | Screen | What moves in the books | Cash moves? |
|---|---|---|---|---|
| 1 | Receive material | Inventory Purchases | Inventory ↑ · Payables ↑ | Depends on payment mode |
| 2 | Value the material | automatic | No entry — the weighted average updates | No |
| 3 | Production run | Day Production | Material ↓ · Finished goods ↑ · Labor accrued ↑ | **No — never** |
| 4 | Settle the real wages | Expenses + "Production Labor" | Labor accrued → zero · variance → COGS | Depends on payment mode |
| 5 | Sale | Sales | Receivables ↑ · Revenue ↑ · Inventory ↓ · COGS ↑ | Depends on payment mode |
| 6 | Collection | Receive Money | Cash ↑ · Receivables ↓ | Yes |

---

## 3. Accounts involved

| Code | Account | Type | Its role in the cycle |
|---|---|---|---|
| 1000 / 1010 | Cash on Hand / Bank Account | Asset | Collections and payments only |
| 1100 | Accounts Receivable | Asset | Credit sales invoices |
| 1150 | VAT Receivable (Input) | Asset | Purchase VAT — **never enters inventory cost** |
| 1200 | Inventory (Stock) | Asset | Raw material and finished goods share one account |
| 2000 | Accounts Payable | Liability | Purchase bills and unpaid wages |
| 2100 | VAT Payable (Output) | Liability | Sales VAT |
| **2200** | **Production Labor Accrued** | Liability | **A clearing account** — see §7 |
| 4000 | Sales Revenue | Income | Invoice value before VAT |
| 5000 | Cost of Goods Sold | Expense | What was actually sold, plus the labor variance |

---

## 4. The example: a bakery, April 2026

| | |
|---|---|
| Raw material | **Flour** — base unit: kg · bought by the sack = 25 kg |
| Product | **Bread** — base unit: loaf |
| VAT | 14% |

---

## 5. Steps 1 and 2 — receiving and valuing the material

### First purchase — 2 April

10 sacks at 250 per sack.

```
Quantity in base units   =  10 sacks × 25 kg  =  250 kg
Line value (before VAT)  =  10 × 250          =  2,500.00
VAT                      =  2,500 × 14%       =    350.00
Invoice total            =                       2,850.00
```

| Account | Debit | Credit |
|---|---:|---:|
| 1200 Inventory | 2,500.00 | |
| 1150 VAT Receivable (Input) | 350.00 | |
| 2000 Accounts Payable | | 2,850.00 |

> **Accounting point:** VAT is not capitalised into inventory. The cost of a kilo from this delivery is `2,500 ÷ 250` = **10.00**, not 11.40.

### Second purchase — 9 April (the price went up)

5 sacks at 280.

```
Quantity  =  5 × 25   =  125 kg
Value     =  5 × 280  =  1,400.00   ·   VAT 196.00   ·   Total 1,596.00
Cost per kg from this delivery = 1,400 ÷ 125 = 11.20
```

### The moving average — computed automatically

The application does not use the price of the last delivery. It keeps **one pool of quantity and value per item** and re-averages it as stock moves:

```
                  value in the pool       2,500 + 1,400     3,900
moving average =  ───────────────────  =  ─────────────── = ───────  =  10.40 per kg
                  quantity in the pool       250 + 125        375
```

**Three things worth knowing:**

1. It is a **true moving average**, not a period-end one. The pool is rebuilt one calendar day at a time: that day's stock IN (purchases, finished goods from production) is added and the average recalculated **before** that day's stock OUT (sales, raw material consumed) is priced at it.
2. Sold and consumed stock **leaves the pool**. The average describes what the stock still on the shelf is worth, not an average of everything ever bought.
3. **There are no closed periods.** Editing or deleting a past purchase, sale or production run re-runs that item's cost forward from that date to today, and cascades into anything downstream — including the finished product a raw material feeds.

For a finished product the same pool is fed from a second source: **production orders** (§6).

---

## 6. Step 3 — the production run (12 April)

> "Today I made **300 loaves**, used **150 kg of flour**, and the day's labor was **400**."

### The costing

```
Material cost  =  150 kg × 10.40         =  1,560.00
Day's labor                              =    400.00
Other costs                              =      0.00   ← always, by design (§9)
                                            ─────────
Total batch cost                         =  1,960.00

Cost per loaf = 1,960 ÷ 300 = 6.5333
```

### The entry

| Account | Debit | Credit | Why |
|---|---:|---:|---|
| 1200 Inventory | 1,960.00 | | Finished goods in, at full cost |
| 1200 Inventory | | 1,560.00 | Flour consumed, out |
| 2200 Production Labor Accrued | | 400.00 | Labor as a liability, not as cash |

**Net effect on Inventory = `1,960 − 1,560` = +400** — exactly the **value added**, which is the labor. That is the right answer: the flour did not disappear, it changed shape.

### Three points that matter

**a. No cash moves at all.** Neither Cash nor Bank is touched. A production run is not a payment — this is the behaviour that was recently corrected; see the PARKED block in `JournalService::postProductionOrder()`.

**b. The labor is an estimate, not a fact.** That 400 is still a guess. It is parked in **Production Labor Accrued (2200)** until the real wages are entered — §7.

**c. You cannot consume material you do not have.** The application rejects a run that asks for more than the stock on hand, with a message stating what is actually available and in which unit (`GuardsRawMaterialStock`).

### Valuing the finished product

After this run, bread enters the pool at:

```
cost per loaf = 1,960 ÷ 300 = 6.5333   (stored to four decimals)
```

Each sale line records the cost that applied **when that sale was made**, and Cost of Goods Sold is the sum of `qty × that stored cost`. The four-decimal storage is why the figures below carry a one-cent tail — see §8.

---

## 7. Step 4 — settling the real wages (28 April)

At month end the actual payroll is entered: **450**, from the Expenses screen, with **"This is Production Labor"** ticked, unpaid.

### Why this step exists at all

The production order recognised an estimate (400). The real wage bill is something else (450). If the payroll were entered as an ordinary expense, the labor would be counted **twice** — once inside the value of the stock and once as an expense. The checkbox is what prevents that.

### The calculation

```
Applied  =  total labor across production orders in the same calendar month  =  400.00
            (less whatever other production-labor expenses that month already claimed)

Variance =  450 − 400  =  +50.00   ← paid more than estimated
```

| Account | Debit | Credit | Why |
|---|---:|---:|---|
| 2200 Production Labor Accrued | 400.00 | | Clear the estimate in full |
| 5000 Cost of Goods Sold | 50.00 | | The variance is a genuine extra cost |
| 2000 Accounts Payable | | 450.00 | The real amount owed, like any expense |

**Account 2200 is now zero.** That is the health check: if it is not zero at month end, either wages were never entered, or they were entered without the box ticked.

> Had the actual been **less** than the estimate — say 380 — the direction reverses: the 20 variance is **credited** to COGS, reducing the cost, because the estimate had overstated it.

The 450 is then settled like any supplier bill from the Pay Money screen.

---

## 8. Steps 5 and 6 — the sale and the collection

### The sale — 20 April: 200 loaves at 12, on credit

```
Invoice value  =  200 × 12  =  2,400.00
VAT            =  14%       =    336.00
Owed by customer            =  2,736.00
```

**First entry — the invoice:**

| Account | Debit | Credit |
|---|---:|---:|
| 1100 Accounts Receivable | 2,736.00 | |
| 4000 Sales Revenue | | 2,400.00 |
| 2100 VAT Payable (Output) | | 336.00 |

**Second entry — cost of goods sold (automatic):**

```
COGS = 200 loaves × 6.5333 (the stored cost) = 1,306.66
```

| Account | Debit | Credit |
|---|---:|---:|
| 5000 Cost of Goods Sold | 1,306.66 | |
| 1200 Inventory | | 1,306.66 |

> **On that last cent.** Carrying full precision to the end would give 1,306.67. The app posts 1,306.66 because it costs each line at the rate it actually stored, and that is deliberate: the ledger agrees with the per-line cost a user can open and inspect, rather than with precision the app never kept. The cent stays in inventory, so nothing is lost.

> Two separate entries, deliberately: the first says **what you sold it for**, the second **what it cost you**. The difference between them is gross profit, and it stays visible to an auditor.

### The collection — 25 April: 1,500 in cash

| Account | Debit | Credit |
|---|---:|---:|
| 1000 Cash on Hand | 1,500.00 | |
| 1100 Accounts Receivable | | 1,500.00 |

The customer still owes **1,236.00**.

---

## 9. Why a production order has no "other costs"

The screen used to carry an "other costs" repeater. It has been **PARKED** — commented out rather than deleted, with the reason written beside it at every place it appears.

**The reason:** every line in that repeater was posted as **cash leaving the till that same day**, and the form never asked. A workshop recording a contractor's invoice it had not yet paid watched Cash fall — possibly into negative — while the money was still in the bank, leaving an abnormal cash balance on the Trial Balance that no report explained.

**What replaced it:** any cost other than material and labor is recorded as an ordinary Expense, where there is a supplier, a category, and a payment mode that can say "not paid yet".

**What follows from this in practice:**

- `other_cost_total` is zero on every new production order. The zero is enforced in the service layer, not only in the form, so even a hand-rolled request is ignored.
- A legacy run entered before the change keeps its figures. But **editing it re-costs it from scratch, which zeroes its other costs** — that is the intended behaviour, not a side effect.

---

## 10. The result: trial balance at 30 April

| Code | Account | Debit | Credit |
|---|---|---:|---:|
| 1000 | Cash on Hand | 1,500.00 | |
| 1100 | Accounts Receivable | 1,236.00 | |
| 1150 | VAT Receivable (Input) | 546.00 | |
| 1200 | Inventory (Stock) | 2,993.34 | |
| 2000 | Accounts Payable | | 4,896.00 |
| 2100 | VAT Payable (Output) | | 336.00 |
| 2200 | Production Labor Accrued | 0.00 | 0.00 |
| 4000 | Sales Revenue | | 2,400.00 |
| 5000 | Cost of Goods Sold | 1,356.66 | |
| | **Total** | **7,632.00** | **7,632.00** |

### Checking the inventory balance independently

```
Flour left  =  375 − 150  =  225 kg  × 10.40      =  2,340.00
Bread left  =  300 − 200  =  100 loaves           =    653.34   (1,960 − 1,306.66 — what is left in the pool)
                                                     ─────────
                                                      2,993.34   ✓ agrees with the ledger
```

### And the Profit & Loss report says

The report is **accrual**. It is read straight off the general ledger — the same rows the Trial Balance is built from — so the two can never disagree:

| | |
|---|---:|
| Revenue | 2,400.00 |
| Cost of goods sold | 1,356.66 |
| **Gross profit** | **1,043.34** |
| Operating expenses | 0.00 |
| **Net profit** | **1,043.34** |

Cost of goods sold is the 1,306.66 from the sale **plus the 50 labor variance** from §7 — the variance is a genuine cost of making the goods, so it belongs there and not among operating expenses. Operating expenses are zero only because this example carries no rent, utilities or non-production salaries; a real month would.

**Revenue is 2,400, not the 1,500 collected.** Revenue is recognised when the invoice is raised, not when the customer pays. The 1,236 still outstanding is a receivable, not a reduction in revenue — just as the 4,896 owed to suppliers is a payable, not a saving.

**What actually moved in and out of the till is a different question, and it has its own report:** Cash Flow. Answering it from the Profit & Loss is what this report used to do, and it is why its figures could not be reconciled against the Trial Balance.

---

## 11. Rules that govern the whole cycle

**1. Corrections reverse, they do not edit.** Editing a production order or an invoice does not rewrite the old entry — it **reverses** it and posts a new one. The reversal carries the **original entry's date**, not today's, so a closed month's totals never change because someone fixed a typo later.

**2. Re-costing is from scratch.** Editing a production order recomputes the material cost entirely, because the mix of materials may itself be what changed.

**3. Rounding.** Unit cost is stored to four decimals; money to two. Cost of goods sold is `qty × the stored rate`, rounded once at posting time — 200 × 6.5333 = **1,306.66** in this example. See the note in §8 on why that is a cent under full precision, and why that is the right answer here.

**4. Correcting the past re-costs everything after it.** There are no closed periods. Editing or deleting a past purchase, sale or production run re-runs that item's cost pool forward from that date to today, corrects the stored cost on every affected line, and reverses and reposts any ledger entry whose figure actually changed. It cascades: correcting a raw material re-costs the runs that consumed it and the finished goods those runs made.

**5. Editing versus deleting.** Any employee can edit (it leaves a full trail). Only a company admin can delete.

---

## 12. The Stock report and the ledger agree

**This section used to document an open defect. It has since been fixed, and the record of it is kept here because the failure is worth understanding.**

The Inventory Statement used to read purchases and sales only. It could not see raw material consumed by a production run, nor finished goods produced by one — so for a production company it reported:

| | Correct | What it used to show |
|---|---:|---:|
| Flour | 225 kg | 375 kg (consumption ignored) |
| Bread | 100 loaves | −200 loaves (production ignored) |
| Cost per loaf | 6.5333 | unavailable |
| **Total stock value** | **2,993.34** | **3,900.00** |

Two things made it worse than a wrong number. Bread carried a red badge reading *"Negative stock — check entries"*, so the app accused the user of a mistake they had not made. And the report contradicted the production form, which refused a run for lack of material the report said was there.

**What changed:** stock quantity and cost now come from the same moving-average ledger everything else uses, so the report counts production the way the rest of the app does. It is asserted against the general ledger in `AccrualAccountingCycleTest`: the Inventory Statement's total stock value and the Inventory (1200) account must be the same figure to the cent, on a scenario that contains purchases, production and sales.

```
Stock report total stock value   2,993.34
Inventory (1200) on the ledger   2,993.34   ✓
```

---

## 13. Quick reference

| Question | Answer |
|---|---|
| What goes into a batch's cost? | Material consumed at weighted-average cost, plus the day's labor |
| Does purchase VAT enter the cost? | No — it goes to VAT Receivable |
| Does a production run touch cash? | No, never |
| Where do electricity and delivery go? | An ordinary Expense, on the Expenses screen |
| When does labor really enter the cost? | Immediately as an estimate, settled against real wages at month end |
| How do I know labor was settled? | Account 2200 reads zero at month end |
| How is a finished product valued? | Total batch cost ÷ quantity produced |
| When is COGS recognised? | The moment the sales invoice is recorded, as its own entry |
| Is the Profit & Loss cash or accrual? | Accrual, read off the ledger. Cash is its own report — §10 |
| Can I trust the Stock report? | Yes — it ties to the ledger to the cent (§12) |
| Why the odd last cent? | Each line is costed at its stored 4-decimal rate — §8 |
| What happens if I fix an old entry? | Everything after it is re-costed automatically — §11 |
