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

### The weighted average — computed automatically

The application does not use the price of the last delivery. It uses the **weighted average of everything that has ever entered stock**:

```
                    total cost of everything in     2,500 + 1,400     3,900
weighted average =  ─────────────────────────── =  ─────────────── = ───────  =  10.40 per kg
                    total quantity of everything       250 + 125        375
```

**Two things worth knowing:**

1. The average is taken over **everything that has ever entered stock since day one**, and it does not fall as stock is sold or consumed. This is the conventional periodic weighted average.
2. For a finished product, the same formula is fed from a second source: **production orders** (§6).

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

After this run, bread has an average cost of:

```
average cost per loaf = 1,960 ÷ 300 = 6.533333…
```

and that is what Cost of Goods Sold will be computed from when it sells.

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
COGS = 200 loaves × 6.533333… = 1,306.67
```

| Account | Debit | Credit |
|---|---:|---:|
| 5000 Cost of Goods Sold | 1,306.67 | |
| 1200 Inventory | | 1,306.67 |

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
| 1200 | Inventory (Stock) | 2,993.33 | |
| 2000 | Accounts Payable | | 4,896.00 |
| 2100 | VAT Payable (Output) | | 336.00 |
| 2200 | Production Labor Accrued | 0.00 | 0.00 |
| 4000 | Sales Revenue | | 2,400.00 |
| 5000 | Cost of Goods Sold | 1,356.67 | |
| | **Total** | **7,632.00** | **7,632.00** |

### Checking the inventory balance independently

```
Flour left  =  375 − 150  =  225 kg     × 10.40   =  2,340.00
Bread left  =  300 − 200  =  100 loaves × 6.5333  =    653.33
                                                     ─────────
                                                      2,993.33   ✓ agrees with the ledger
```

### Profitability

```
Gross profit        =  2,400.00 − 1,306.67   =  1,093.33
less labor variance                           −     50.00
Operating profit                             =  1,043.33
```

### And the Profit & Loss report in the app says

| | |
|---|---:|
| Income received | 1,500.00 |
| Expenses paid | 0.00 |
| **Net profit** | **1,500.00** |

**These do not contradict each other — they answer different questions.** The Profit & Loss report is **cash basis**: it answers "how much came into the till, and how much left it". In April, 1,500 came in and nothing went out, because every purchase and the payroll were all on credit.

The **accounting profit (1,043.33)** is what the Trial Balance and the general ledger show: revenue 2,400 less COGS 1,356.67.

The gap between the two figures is not an error. It is the sum of everything that has not moved as cash yet: 1,236 not yet collected, and 4,896 not yet paid.

---

## 11. Rules that govern the whole cycle

**1. Corrections reverse, they do not edit.** Editing a production order or an invoice does not rewrite the old entry — it **reverses** it and posts a new one. The reversal carries the **original entry's date**, not today's, so a closed month's totals never change because someone fixed a typo later.

**2. Re-costing is from scratch.** Editing a production order recomputes the material cost entirely, because the mix of materials may itself be what changed.

**3. Rounding.** Unit cost is stored to four decimals; money to two. In this example: 6.5333 per loaf, and COGS rounded once at posting time (1,306.67).

**4. Average cost is read at posting time.** Editing an old sales invoice recomputes COGS at the **current** average, not the average as it stood on the original sale date. Acceptable under a periodic weighted average, but worth knowing when correcting an old invoice after new deliveries at different prices.

**5. Editing versus deleting.** Any employee can edit (it leaves a full trail). Only a company admin can delete.

---

## 12. ⚠ Known gap — the Stock report does not know about production

**This is an open defect, unrelated to everything above. It is documented here because it falls squarely inside this cycle.** What follows is written from the screen, not from the code: this is what you actually see, and what it will cost you.

The **Inventory Statement** report reads purchases and sales only. It does not see raw material consumed by a production run, and it does not see finished goods produced by one.

---

### 12.1 What you see on screen

Open **Reports → Inventory statement** at the end of the April example. In **Quantity** mode:

| Item | Total purchased | Total sold | Current stock |
|---|---:|---:|---:|
| Bread  🔴 *Negative stock — check entries* | 0 loaf | 200 loaf | **−200 loaf** |
| Flour | 375 kg | 0 kg | **375 kg** |

Switch to **Value** mode:

| Item | Avg. cost / unit | Current stock | Stock value |
|---|---:|---:|---:|
| Bread  🔴 *Negative stock — check entries* | **—** | −200 loaf | **0.00** |
| Flour | 10.40 | 375 kg | **3,900.00** |
| | | **Total stock value** | **3,900.00** |

The truth, from §10, is: Flour **225 kg** worth 2,340.00, Bread **100 loaves** worth 653.33, total **2,993.33**.

### 12.2 What the drill-down shows

Click **Bread** to open its transaction history:

| Date | Type | Qty | Unit price | Stock after |
|---|---|---:|---:|---:|
| 20 Apr | Sale | −200 | 12.00 | **−200** |

The production run of 12 April that made those 300 loaves is simply **not there**. As far as this screen is concerned, you sold 200 loaves you never had.

Click **Flour**:

| Date | Type | Qty | Unit price | Stock after |
|---|---|---:|---:|---:|
| 2 Apr | Purchase | +250 | 10.00 | 250 |
| 9 Apr | Purchase | +125 | 11.20 | **375** |

The 150 kg you consumed on 12 April is **not there** either.

---

### 12.3 What this actually does to you

**1 · The app accuses you of a mistake you did not make.**
Bread carries a red badge reading *"Negative stock — check entries"*. There is nothing to check. Every entry is correct. You will spend an evening hunting for a sale you never recorded, and you will not find it, because it does not exist.

**2 · Two screens of the same app give you two different answers.**
The report says you have 375 kg of flour. You start a production run for 600 loaves, which needs 300 kg. The app refuses to save it:

> *Only 225 kg of "Flour" are in stock. Record the purchase first, or reduce the quantity.*

So the report says 375, the production form says 225, and the form tells you to record a purchase you recorded twice already. (The form is the one telling the truth.)

**3 · Your physical count will not match, and you will suspect theft.**
You count the sacks on the shelf: 225 kg. The report says 375 kg. A 150 kg difference — six full sacks — with no explanation. That is exactly what a theft looks like on paper, and nothing on the screen suggests otherwise.

**4 · You cannot find out what a loaf costs you.**
Bread's *Avg. cost / unit* shows **—**. The number exists — it is 6.5333, the app used it to post cost of goods sold on your own sales invoice — but this screen will not show it to you. So the one screen you would open to ask *"am I pricing my bread above cost?"* cannot answer.

**5 · Your stock is overstated by 30%.**
3,900.00 instead of 2,993.33 — **906.67 too high**. If you hand that figure to a bank for financing, to an insurer for a policy, or into a year-end stocktake, you are reporting stock you do not own.

**6 · Your accountant will find two different inventory figures.**
The Trial Balance says Inventory = **2,993.33**. The Stock report says **3,900.00**. Same date, same company, same app. Whoever reconciles the two will stop and ask, and the answer is not a reassuring one.

---

### 12.4 What is still correct — do not over-worry

The defect is **in this one report**. It has not corrupted anything:

- **The general ledger is right.** Inventory 2,993.33 is the correct figure and the Trial Balance balances.
- **Cost of goods sold is right.** The 1,306.67 posted on the sale used the true cost of 6.5333.
- **You cannot over-consume or oversell.** Both the production form and the sales form check the *real* stock and refuse to go negative. Nothing wrong can be entered because of this.
- **Trading-only companies are entirely unaffected.** With no production orders, purchases and sales are the whole story, and the report is correct.

### 12.5 Working around it until it is fixed

- **For total stock value:** read the **Inventory (1200)** balance off the Trial Balance. That figure is correct.
- **For the quantity of one item:** there is **no screen that will tell you**. This report is the only place in the app that displays stock quantity at all. Until it is fixed, you either track raw-material quantities outside the app, or use this trick: open a production order, ask for an absurd quantity of that material, and read the true figure off the error message — *"Only 225 kg of "Flour" are in stock"* — then cancel without saving.

### 12.6 Where the fix goes

The subqueries in `ReportDataService::purchasedBase()`, `purchaseCost()` and `soldBase()` need to add production-order quantities and costs, and subtract consumed material lines — that is, do what `Item::currentStock()` and `Item::averagePurchaseCost()` already do correctly. The per-item history in `inventoryItemHistory()` needs two more row types alongside *purchase* and *sale*: **produced** and **consumed**.

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
| Why is net profit different from gross profit? | The report is cash basis, the ledger is accrual — §10 |
| Can I trust the Stock report? | **No, if you use Production** — §12 |
| Then where do I read stock value? | Inventory (1200) on the Trial Balance — §12.5 |
