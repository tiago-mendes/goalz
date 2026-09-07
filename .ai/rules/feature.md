---
paths:
  - '{app/Actions/SaveGoalAccountAllocation.php,app/Models/{Account,Goal,GoalAccountAllocation}.php,resources/views/pages/{accounts,goals}/**,tests/Feature/GoalAccountAllocations*}'
---

# Feature

## Goal allocations designate account funds
GoalAccountAllocation persists only an exact DECIMAL amount; percentages are derived UI state. Allocations never mutate Account.current_balance or total assets. Creates/increases must owner-scope and lock Account then Goal inside a transaction before rechecking both capacities; later overallocated/overfunded/inactive states preserve rows and allow reductions/removal.
