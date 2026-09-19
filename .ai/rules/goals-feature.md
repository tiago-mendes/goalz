---
paths:
  - '{app/Models/Goal.php,resources/views/pages/goals/index.blade.php,tests/Feature/SharedGoalsTest.php}'
  - '{app/Models/Goal.php,resources/views/pages/goals/{index,allocations}.blade.php,tests/Feature/GoalRewardsTest.php}'
---

# Goals Feature

## Classify shared goals from accepted memberships
On the Goals index, My Goals means owned Goals with no accepted non-owner memberships. Shared means owned Goals with an accepted non-owner membership or Goals the user accepted as a member. Keep this classification derived, duplicate-free, and separate from policies; owner-only summary cards continue to use all owned Goals.

## Goal list badges show current supported achievement only
The Goals index shows one green badge for the highest persisted achievement whose milestone is still satisfied by current canonical combined funding. Exclude historical/amber achievements and show no badge when none qualify; never infer an achievement from progress alone. The Goal detail continues to show complete current, historical, and upcoming milestone states.
