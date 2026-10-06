## How an order moves {#overview}

An order goes through six statuses. The status tells you **where the order is**, not how much money has come in. Money is tracked separately, in the "Payment" block.

| Status | What it means |
|---|---|
| Placed | The customer has placed the order. You have not confirmed it yet. |
| Confirmed | You have accepted it and are preparing it for shipping. |
| Shipped | The parcel is on its way to the customer. |
| Delivered | The customer has received it. |
| Cancelled | The order ended before the customer received it. |
| Refunded | The customer has returned all the goods after delivery. |

**There is one way forward:** Placed → Confirmed → Shipped → Delivered. There is no way back. If you pressed something by mistake, the order moves on or ends, and the reason stays in the history.

**Two endings.** "Cancelled" and "Refunded" are final.

- "Cancelled" means the order ended before the customer had it. You can cancel while it is Placed, Confirmed or Shipped.
- After delivery an order is not cancelled, it is **returned**, with "Record a return". If all the units come back, the order becomes "Refunded". If only some do, the status does not change and the return is recorded in the history.

**Money is separate from the status.** The header shows one payment state: *Awaiting payment*, *Partly received*, *Over-received*, *Paid* or *Paid, mismatch accepted*. Money moving never changes an order's status by itself.

**What you see on screen.** At the top there is an "Add note" button and the "Actions" menu. The menu has three groups, with dividers:

1. the order's state: Confirm, Ship, Deliver;
2. the payment: Mark as received, Accept the received amount, Correct a receipt;
3. returns and cancellation: Record a return, Refund money only, Cancel.

An action that is not possible for the order right now is not shown (for example "Ship" before "Confirm"). If you do not see an action you expected, check the status, the payment and your permissions. Some actions need a specific permission: refunding money, and accepting a mismatch is for Administrators only.

## What depends on the payment method {#payment-methods}

| Question | Bank transfer | Cash on delivery |
|---|---|---|
| When is the payment "Paid" | When you record a received transfer equal to the order total, or accept the received amount | When the order is marked as delivered: the system records the payment itself |
| Can it be shipped before it is paid | **No.** "Ship" is blocked until the payment is paid | Yes |
| Can the order be edited | Yes, while it is Placed or Confirmed and the payment is not paid | The same |
| Cancelling before it is paid | No money to refund. The pending payment is voided | No money to refund. The pending payment is voided |
| Cancelling or returning once it is paid | A refund is recorded as owed, by bank by default | A refund is recorded as owed, in cash from the till by default |
| Permission needed to refund money | "Refund by bank" | "Refund in cash" |

The system is **not connected to a bank or a till** and does not move money. It records what you enter: received, refunded, accepted. The money itself moves in your banking app or in the till.

## What each action does {#actions}

Each action has its own section below. The "Help: how this works →" link in an action's dialog leads straight here.

### Confirm {#action-confirm}

**Where:** Actions → Confirm. **When:** only for a Placed order.

**What it does:** accepts the order. The status becomes Confirmed. You can add a note to the history entry.

**What it does not do:** it does not move money or record a payment.

**Can it be undone:** no. If the order cannot be fulfilled, cancel it.

**Next:** Ship. For a bank transfer the payment must be paid first.

### Ship {#action-ship}

**Where:** Actions → Ship. **When:** only for a Confirmed order.

**What it does:** marks the order as shipped. The status becomes Shipped.

**For a bank transfer it is blocked until the payment is paid.** Paid means: a transfer equal to the order total has been recorded, or the received amount has been accepted. If a smaller transfer is recorded, either wait for the rest or accept the mismatch first (see "Bank transfer").

**For cash on delivery** nothing is waited for: the money is collected at the door.

**Next:** Deliver.

### Deliver {#action-deliver}

**Where:** Actions → Deliver. **When:** only for a Shipped order.

**What it does:** marks the order as delivered. The status becomes Delivered.

**For cash on delivery** the payment is recorded as received in the same action, because the money is collected on delivery. If the payment cannot be recorded, the delivery is still marked and the page shows the payment's state.

**After delivery** the order can no longer be cancelled. If the customer sends goods back, use "Record a return".

### Mark as received {#action-mark-as-received}

**Where:** Actions → Mark as received. **When:** while there is a pending payment that can be recorded.

**For a bank transfer** the "Record a received transfer" dialog opens. You fill in:

- **Received amount:** what you see in your bank account. The dialog suggests the remainder up to the order total.
- **Received on:** the day the money arrived. It cannot be in the future and cannot be before the day of the order.
- **Bank reference:** the number from the bank statement or the payment reason. Required, up to 64 characters.

The "Record the receipt" button **only records the transfer**. It does not ship or confirm the order. If the amount is exactly right, the payment becomes Paid. If not, it stays unsettled and you see "short by X" or "over by X". Details: the "Bank transfer" section.

**For cash on delivery** it is a simple confirmation. You usually do not need it, because "Deliver" records the payment by itself.

### Accept the received amount {#action-accept-mismatch}

**Where:** Actions → Accept the received amount. **When:** only for a bank transfer where less or more than needed is recorded. **Who:** only a user with the permission to accept a mismatch (an Administrator by default).

**What it does:** you say "for me, this is paid". The payment becomes Paid **for exactly the received amount**. The reason is required (up to 255 characters).

**What changes:** the order's price stays the same. The received amount counts as paid. Any future refund is at most up to that amount.

**Safeguard:** the dialog shows the amount you see right now. If someone records another transfer in the meantime, the system refuses with a message and nothing is written. Open the dialog again.

**Important:** while a transfer with a mismatch is recorded, the order **cannot be cancelled, returned or edited**. Accepting is also the way to unlock them. You can say so in the reason, so the history reads correctly.

### Correct a receipt {#action-correct-receipt}

**Where:** Actions → Correct a receipt. **When:** at least one transfer is recorded.

**What it does:** replaces a recorded transfer with a corrected one. You choose the transfer from the list, change the fields and write a reason (required). The old record stays in the history as replaced.

**While the payment is not yet paid:** you can correct the amount, the day and the reference. If the corrected amount makes the total equal to the order total, the payment becomes Paid.

**Once the payment is paid:** you can correct only the **day and the reference**. The amount cannot be changed. If the amount is wrong, fix it with money: "Refund money only" or another refund.

### Record a return {#action-record-return}

**Where:** Actions → Record a return. **When:** for a Shipped or Delivered order.

**What it does:** records which units are coming back. For each line you choose how many units and whether they go back on the shelf (yes by default). If the returned units are all of them: Shipped becomes Cancelled, Delivered becomes Refunded. If only some: the status does not change.

**If the payment is paid**, the dialog also records the refund of money. See "Returning goods" below for the fields.

**You cannot return more than what is left.** If you type a larger quantity, the field is brought down to the most possible.

### Refund money only {#action-refund-money-only}

**Where:** Actions → Refund money only. **When:** only when the payment is paid.

**What it does:** refunds money without taking goods back. Suitable for a goodwill gesture or a correction. It does not change stock and does not change the status.

Details: "Money-only refund" in the "Returns and refunds" section.

### Cancel {#action-cancel}

**Where:** Actions → Cancel (last in the menu, red). **When:** for a Placed, Confirmed or Shipped order.

**What it does:** ends the order. The status becomes Cancelled. It releases the promo code that was used. The goods go back on the shelf (for a Shipped order you choose for each line).

**With the money:** if the payment is paid, a refund is recorded as owed. If it is not paid, the pending payment is voided and there is nothing to refund.

Details: the "How to cancel an order" section. **Cancel** is not the same as the "Close" button in dialogs: "Close" only closes the window.

### Add note {#action-add-note}

**Where:** the "Add note" button at the top, always visible. **What it does:** records an internal note in the order's history. Everyone who can view the order sees it. The customer does not. It changes nothing else.

### Edit order {#action-edit-order}

**Where:** the "Edit order" button in the "Items" section (it is not in the "Actions" menu). **When:** only while the order is Placed or Confirmed **and the payment is not paid**.

**What you can do:** reduce or remove items, add a product, change the shipping or the promo code. The order total and the pending payment update automatically.

**What you cannot do:** edit a paid order, or an order with an unreconciled bank transfer. A paid order is changed through a return.

### Remove {#action-remove-line}

**Where:** the bin icon on an item row in the edit dialog. **What it does:** sets the row's quantity to 0. Nothing is deleted until you save the edit. You cannot remove the last remaining item: to end the order, cancel it.

### Restore {#action-restore-line}

**Where:** on a removed row in the edit dialog. **What it does:** puts the row's original quantity back, before you save the edit.

### Mark paid out {#action-mark-refund-paid-out}

**Where:** in the "Refunds" block, on an owed refund. **When:** after you have actually returned the money to the customer.

**What it does:** changes the refund from "Owed" to "Paid out". You enter the payout date, a bank reference (for the bank) and optionally a note. The amount is not changed here.

The system **does not pay** the money out. First you return it from the bank or the till, then you mark it here. Permission: "refund in cash" or "refund by bank", depending on the channel.

### Cancel refund {#action-cancel-refund}

**Where:** in the "Refunds" block, on an owed refund. **When:** it was recorded by mistake and you do not want money to go back.

**What it does:** cancels only the money record. **The goods stay returned and back in stock**, and nothing is refunded for them. The reason is required. Permission: the same as for paying out.

## Bank transfer: recording the receipt {#bank-transfer}

A bank transfer happens in the customer's bank. You see it in your account and **record it in the order**. The system is not connected to the bank: it records only what you enter.

**What you record for each transfer:** the received amount, the day it arrived and the bank reference.

**Rules:**

- The payment becomes Paid only when the sum of the recorded transfers is exactly the order total.
- You can record several transfers for one order (up to 20 active records).
- While the payment is not paid, **you cannot ship the order**. While a recorded transfer is not exactly right, you also cannot cancel, return or edit the order. Either the rest arrives, or you accept the received amount.

The "Bank transfers received" block on the order page shows the expected amount, the received so far, the difference, and a table with every record: when, how much, the reference and who recorded it.

### Exact amount {#bank-exact}

An order for 78.00 EUR. The customer transfers 78.00. You record 78.00, today's day and the reference. The payment becomes **Paid** and you can ship the order.

### A smaller amount {#bank-partial}

An order for 78.00 EUR, and 70.00 has come into your account. You record 70.00. The payment stays unsettled, the header says "Partly received (70.00 of 78.00 EUR)", and the block says "short by 8.00 EUR".

You have two choices:

1. **Wait for the rest.** When the customer pays the missing 8.00, you record a second transfer of 8.00. The total becomes 78.00 and the payment becomes Paid by itself.
2. **Accept 70.00.** An Administrator uses "Accept the received amount". The payment becomes Paid for 70.00, the order's price stays 78.00, and any later refund is at most 70.00.

Until you choose, the order cannot be shipped, cancelled, returned or edited.

### A larger amount {#bank-over}

An order for 100.00 EUR, and the customer has transferred 110.00. You record 110.00. The payment stays unsettled and the header says "Over-received".

An Administrator accepts the received amount. The payment becomes Paid for 110.00. **The extra 10.00 is returned with "Refund money only"** (up to 10.00) or, if you cancel the order, everything paid is refunded.

### Accepting the received amount {#bank-accept}

Accepting is a decision about money, so it is for Administrators only and requires a reason. The dialog shows the expected amount, the received amount and the difference, and warns: "The payment will be settled for X; refunds are limited to X."

If someone records another transfer while the dialog is open, the system refuses ("the received amount changed"). Open the dialog again to see the new amount.

Accepting is also **the way to cancel or return an order with an unreconciled transfer**. Those actions are refused until the payment is paid. Write in the reason what you are doing it for, so the history reads correctly.

Details of the action itself: "Accept the received amount" in the "What each action does" section.

### Correcting a record {#bank-correct}

Got the amount, the day or the reference wrong? Nothing is deleted: you make a **correction** that replaces the record. The old one stays in the history as replaced, with the reason, who changed it and when.

- **Before the payment is paid:** everything can be corrected. If the corrected amount makes the total exact, the payment becomes Paid.
- **Once it is paid:** only the day and the reference can be corrected. The amount does not change; if it is wrong, it is fixed with a refund.

The system keeps up to 50 rows per payment (replaced ones included).

## Cash on delivery {#cash-on-delivery}

With cash on delivery the customer pays at the door, to the courier or in cash.

- **Shipping does not wait for payment.** "Ship" is possible after "Confirm".
- **The payment is recorded on delivery.** When you press "Deliver", the system records the payment as received in the same action. You can also do it by hand with "Mark as received", but you usually do not need to.
- **Editing:** like any unpaid order, while it is Placed or Confirmed.
- **Cancelling before it is delivered:** no money to refund. The pending payment is voided.
- **Returning after delivery:** the payment is already paid, so the refund is recorded as owed. The channel defaults to "in cash from the till", but if you have the permission you can choose the bank.

The money the courier transfers to you later is **not tracked in the system yet**.

## How to cancel an order {#cancel-order}

Cancelling is "Actions → Cancel". What is possible depends on the status:

| The order is | Can it be cancelled | The goods | The money |
|---|---|---|---|
| Placed or Confirmed | Yes | All units go back on the shelf automatically | Paid: a refund is recorded as owed. Unpaid: the pending payment is voided |
| Shipped | Yes | For each line you choose whether it goes back on the shelf (for a lost or damaged parcel, untick it) | The same |
| Delivered | **No.** Use "Record a return" | | |
| Cancelled or Refunded | No: they are final | | |

**When the order is paid**, the dialog asks about the money:

- **Goods amount for each line.** It is filled with the calculated share and you can change it. It shows how much can still be refunded for that line.
- **Shipping refund.** It is 0 by default. If the order has shipping, you see "Shipping (X) is not included". The system never adds it by itself.
- **Deduction.** Money the shop keeps. It requires a reason.
- **Pay out via:** in cash from the till or by bank, depending on your permissions. If you have only one channel, the field is not shown.
- **Total to refund** and how much can still be refunded for the order. If the amount is above what is allowed, you see a warning and the system refuses.

**An unpaid order:** there is no money to refund, so the dialog does not ask for amounts.

**You cannot cancel** an order that has a recorded bank transfer which is not exactly right. First the received amount is accepted, or the rest arrives.

## Returns and refunds {#returns-and-refunds}

Two different things: the **goods** and the **money**. Recording a return of goods can also refund money. "Refund money only" refunds money without goods. The order's status is changed only by the goods, never by the money.

### Returning goods {#returns-goods}

The "Record a return" dialog shows, for each line, how many units are returned (up to what is left) and whether they go back on the shelf.

**Money fields** (if the payment is paid): the goods amount (filled with the calculated share, you can change it), shipping refund (0 by default), deduction with a reason, and the payout channel. The live "Total to refund" and "Can still be refunded" show how far you are.

**The date the customer announced the return:** optional. The day the customer said they would send the goods back. Only a date is needed. It cannot be in the future or before the day of the order.

**Facts about the order:** the dialog shows when the order was delivered and how many days have passed. **The system enforces no deadline.** You decide whether to accept the return.

**The status:** if the returned units are all of them: Shipped becomes Cancelled, Delivered becomes Refunded. If only some: the status stays.

### Money-only refund {#returns-money-only}

For the cases where you refund money without goods:

- a goodwill gesture (a discount after the purchase);
- a price correction;
- the surplus of an overpaid bank transfer;
- a damaged item the customer keeps.

Condition: the payment must be paid. You fill in a shipping refund and/or an adjustment, a required reason and the payout channel. It shows how much can still be refunded. It does not change stock or the status.

### Owed and paid-out refunds {#refund-owed-paid-out}

Every refund of money is a record in two steps:

1. **Owed:** it is recorded that you owe money to the customer. The money has not moved yet.
2. **Paid out:** you have returned the money (by bank or in cash) and marked it with "Mark paid out", with a date and a reference.

The "Refunds" block shows: **Paid**, **Refunded and paid out**, **Refunded, still owed** and **Can still be refunded**.

If a record is wrong, "Cancel refund" ends it. Only the money is cancelled, while the goods stay returned and back in stock.

The permission to pay out depends on the channel: "refund in cash" or "refund by bank".

## Worked examples {#scenarios}

**1. Bank transfer, exact amount.** Order 78.00. The customer transfers 78.00. You record received 78.00 → Paid → Ship.

**2. Bank transfer in two parts.** Order 78.00. First 50.00, then 28.00. After the first transfer the header says "Partly received (50.00 of 78.00 EUR)". After the second the payment becomes Paid.

**3. A smaller transfer, accepted.** Order 57.00, transferred 50.00. An Administrator accepts 50.00 with a reason. The payment is Paid for 50.00. If you cancel later, you refund at most 50.00: the goods amount in the dialog has to be up to 50.00.

**4. A larger transfer.** Order 100.00, transferred 110.00. You accept 110.00. You return the extra 10.00 with "Refund money only" (adjustment 10.00).

**5. A wrong reference.** You recorded reference 11155 instead of 111555. Before or after the payment is paid: "Correct a receipt" → the new reference and a reason. The old record stays as replaced.

**6. A wrong amount once the payment is paid.** The amount cannot be corrected. You fix it with money: "Refund money only" for the difference.

**7. Cancelling a paid order (Placed).** Order 78.00, paid. You cancel → the goods go back on the shelf, a refund of 78.00 is recorded as owed. You return the money in the bank, then "Mark paid out" with a date and a reference.

**8. A lost parcel.** A Shipped order that never arrived. Cancel → for the line, untick the shelf box (the goods do not come back) → a refund of what was paid is recorded as owed.

**9. Returning one of two items after delivery.** An order with two lines, Delivered. "Record a return" → one line, one unit. The money: the filled amount (the calculated share). The status stays Delivered and the return is recorded in the history.

**10. A goodwill gesture.** A paid order. The garment has a defect, but the customer keeps it. "Refund money only" → adjustment 5.00, reason "defect". Stock does not change.

**11. Cash on delivery and a return.** A cash-on-delivery order, Delivered. The customer returns an item. "Record a return" → an owed refund, channel "in cash from the till" by default. You pay → "Mark paid out".

## Glossary {#glossary}

| Term | Meaning |
|---|---|
| Paid | The money is recognised as received. For a bank transfer: the sum of the recorded transfers is exactly the amount, or the received amount is accepted. In dialogs it is also called "settled". |
| Expected amount | The amount the customer has to pay for the order. |
| Received amount | The sum of the recorded bank transfers for the order. |
| Mismatch | The received amount differs from the expected amount. The payment stays unsettled until the rest arrives or the amount is accepted. |
| Accepted amount | The received amount an Administrator has accepted as paid. It is the basis for every refund of the order. |
| Transfer record | One recorded bank transfer: amount, day, reference and who recorded it. |
| Correction | A new record that replaces an old one. The old one stays in the history. |
| Owed refund | It is recorded that you owe money to the customer. The money has not been returned yet. |
| Paid-out refund | The money has been returned and the record is marked with a date and a reference. |
| Channel | Where a refund is paid from: "in cash from the till" or "by bank". It depends on your permissions. |
| Shelf (on a return) | Whether the returned goods go back into stock. Yes by default; not for a lost or damaged parcel. |
| Refund money only | A refund of money without goods. It does not change stock or the status. |
| Deduction | Money the shop keeps from a refund. It requires a reason. |
| Reference | The number from the bank statement or the payment reason of a transfer. |
