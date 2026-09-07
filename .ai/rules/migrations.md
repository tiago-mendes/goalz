---
paths:
  - '{app/{BillingCycle*,PaymentSourceSelection.php,CreditCardBill.php,Actions/*CreditCardBill.php},app/Models/{CreditCard,Expense,FixedExpense}.php,resources/views/pages/{credit-cards,expenses,fixed-expenses}/**,database/migrations/*payment_sources*,database/migrations/*credit_cards*}'
---

# Migrations

## Credit cards classify expenses; bills are calculated
CreditCard is separate from asset Accounts. Expense and FixedExpense may reference an owned Account or CreditCard or neither, never both; inactive current references are preserved but cannot be newly selected. Card bills are not persisted or paid as Expenses: resolve cycles from cycle_start_day/due_day with short-month clamping, interpret bill month as due month, query non-deleted Expenses by expense_date, and total with BigDecimal. Current projections materialize every intersecting calendar month through MaterializeFixedExpensesForMonth.
