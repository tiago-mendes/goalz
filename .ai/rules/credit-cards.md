---
paths:
  - resources/views/pages/credit-cards/show.blade.php
---

# Credit Cards

## Bind bill due month to Livewire URL state
Treat `month` as the bill due month and bind it with Livewire's `#[Url]` state. Do not initialize bill selection from the raw request, because SPA navigation can leave the visible URL and component state inconsistent.

## Serialize bill-period overrides by card
Saving or resetting a CreditCardBillPeriod must run in a transaction and lock the owner-scoped CreditCard row first. The parent lock serializes writes for a due month while the database unique constraint remains authoritative.
