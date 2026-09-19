---
paths:
  - '{app/{Actions,Models}/**/*Goal*,resources/views/{components/goal-achievement-celebration.blade.php,pages/goals/**},database/migrations/*goal_milestone*,tests/Feature/GoalRewards*}'
---

# Goals Migrations Feature

## Goal milestones are permanent combined-funding history
Goal milestone achievements are fixed at 25/50/75/100, derive from Goal::allocatedAmount() combined funding, and are never revoked. Evaluate under the existing Goal lock; database uniqueness is the final concurrency guard. Persist every newly crossed achievement, but notify the owner and currently accepted members only for the highest newly crossed milestone. Rewards are optional, owner-managed, and immutable once their milestone is achieved.
