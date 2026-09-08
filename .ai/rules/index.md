# Project Rules Index

Before planning or editing, find the row whose globs match the file's path and read that rule file.

| Applies to | Rule file |
| --- | --- |
| app/Actions/SaveGoalAccountAllocation.php | .ai/rules/actions.md |
| {app/Actions/SaveGoalAccountAllocation.php,app/Models/{Account,Goal,GoalAccountAllocation}.php,resources/views/pages/{accounts,goals}/**,tests/Feature/GoalAccountAllocations*} | .ai/rules/feature.md |
| {app/Models/Goal.php,resources/views/pages/goals/index.blade.php,tests/Feature/SharedGoalsTest.php} | .ai/rules/goals-feature.md |
| {app/Models/{Account,AccountBalanceSnapshot}.php,resources/views/pages/accounts/**,database/migrations/*account_balance_snapshots*,tests/Feature/{Accounts*,AccountBalanceSnapshots*}} | .ai/rules/migrations-feature.md |
| {app/{BillingCycle*,PaymentSourceSelection.php,CreditCardBill.php,Actions/*CreditCardBill.php},app/Models/{CreditCard,Expense,FixedExpense}.php,resources/views/pages/{credit-cards,expenses,fixed-expenses}/**,database/migrations/*payment_sources*,database/migrations/*credit_cards*} | .ai/rules/migrations.md |
| {app/{Actions,Models,Policies,Reports}/**,resources/views/pages/{goals,reports,dashboard.blade.php}/**,tests/Feature/{SharedGoals*,GoalAccountAllocations*,GoalsReports*,Dashboard*,AssetsReports*}} | .ai/rules/pages-feature.md |
