---
paths:
  - '{app/Models/{Account,AccountBalanceSnapshot}.php,resources/views/pages/accounts/**,database/migrations/*account_balance_snapshots*,tests/Feature/{Accounts*,AccountBalanceSnapshots*}}'
---

# Migrations Feature

## Account balance snapshots are append-only observations
Account.current_balance remains the authoritative manually reported asset balance. Creating an Account and each BigDecimal-distinct balance update atomically append an immutable snapshot using the same balance_updated_at/recorded_at timestamp; equivalent decimal formatting and name/type/status changes do not append. Snapshots never alter Goal allocations, and per-account history is strictly owner-scoped without an admin bypass.
