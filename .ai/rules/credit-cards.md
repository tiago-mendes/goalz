---
paths:
  - resources/views/pages/credit-cards/show.blade.php
---

# Credit Cards

## Bind bill due month to Livewire URL state
Treat `month` as the bill due month and bind it with Livewire's `#[Url]` state. Do not initialize bill selection from the raw request, because SPA navigation can leave the visible URL and component state inconsistent.
