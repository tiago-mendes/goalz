---
paths:
  - '{app/{BillingCycle*,CreditCardBill*,Actions/*CreditCardBill.php},app/Models/{CreditCard,CreditCardBillPeriod,Expense}.php,resources/views/pages/credit-cards/**,database/migrations/*credit_card_bill_periods*,tests/Feature/CreditCardBill*}'
---

# Credit Cards Migrations Feature

## Bill-period overrides are due-month specific
Keep cycle_start_day + due_day as the calculated fallback and as the projection model. A CreditCardBillPeriod may replace only the inclusive start/end dates for one card + due month; it never changes the due date. Bill rows and exact total must both come from CalculateCreditCardBill using the effective cycle.
