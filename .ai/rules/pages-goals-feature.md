---
paths:
  - '{resources/views/pages/goals/allocations.blade.php,tests/Feature/GoalRewardsTest.php}'
---

# Pages Goals Feature

## Milestone cards distinguish current and historical achievement
An achievement remains permanent, but its card state is derived at render time. Show achieved milestones green when Goal::hasReachedMilestone() is currently true and amber with the current progress when it is false; reserve Upcoming for milestones with no achievement. Historical cards retain achieved_at, unlocked Reward copy, and read-only controls.
