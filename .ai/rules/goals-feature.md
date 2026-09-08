---
paths:
  - '{app/Models/Goal.php,resources/views/pages/goals/index.blade.php,tests/Feature/SharedGoalsTest.php}'
---

# Goals Feature

## Classify shared goals from accepted memberships
On the Goals index, My Goals means owned Goals with no accepted non-owner memberships. Shared means owned Goals with an accepted non-owner membership or Goals the user accepted as a member. Keep this classification derived, duplicate-free, and separate from policies; owner-only summary cards continue to use all owned Goals.
