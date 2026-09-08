---
paths:
  - '{app/{Actions,Models,Policies,Reports}/**,resources/views/pages/{goals,reports,dashboard.blade.php}/**,tests/Feature/{SharedGoals*,GoalAccountAllocations*,GoalsReports*,Dashboard*,AssetsReports*}}'
---

# Pages Feature

## Shared goals preserve account ownership
goals.user_id remains the owner; only accepted goal_memberships grant shared access. Goal progress/capacity sums every allocation, while allocation writes and Account details remain scoped to the authenticated user's Accounts. Other participants are exposed only as identity plus exact contribution totals; leaving/removal deletes that member's Goal allocations transactionally using Account-then-Goal lock ordering.
