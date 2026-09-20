---
paths:
  - app/Actions/SaveGoalAccountAllocation.php
  - 'app/Actions/*Budget.php'
---

# Actions

## Shared allocation access supersedes owner-only Goal scoping
For Shared Goals, the historical phrase “owner-scope Account then Goal” means the Account remains actor-owned while the Goal must be accessible through ownership or an accepted membership. Account-then-Goal lock ordering remains mandatory.

## Serialize budget writes by category
Budget create/edit/stop operations for a category must lock the owned expense_categories row before locking or changing budget_rules. This parent-row lock serializes even the first rule (when no budget row exists) and keeps a consistent category-then-rule lock order.
