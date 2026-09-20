---
paths:
  - '{app/Http/Middleware/**,bootstrap/app.php}'
---

# Middleware

## Recheck active users on every web request
Append EnsureUserIsActive to the web middleware group so Fortify, application routes, and Livewire's web-protected update route all recheck authenticated users. Query is_active from the database so stale authenticated models cannot bypass deactivation; logout, invalidate the session, and rotate the CSRF token.
