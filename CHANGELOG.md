# Changelog

## 0.2.1 — 2026-10-10

- Show order dates in the customer profile with the platform time standard: stored UTC values are shown in the visitor's time zone with a relative time (Flex CMS 0.1.59+; older platforms show the stored value).
- Show customer registration dates in the administration with the platform `DateTime` component.

## 0.2.0 — 2026-10-09

- Implement optional email confirmation for new customer registrations: 24-hour links, explicit confirmation, replacement links and delivery-failure recovery.
- Store confirmation tokens as keyed hashes; consume them atomically and reject expired, reused, mismatched, disabled-account and deleted-account requests.
- Apply scoped IP/email limits and a resend cooldown. Confirmation links use the configured site URL.
- Preserve existing active customers through an additive schema upgrade and enforce the requirement per new customer.
- Revoke outstanding password-recovery requests when a password changes.
- Reject unsafe requested and configured redirects.
- Apply a shared 120-minute customer-session timeout to authentication and integration hooks.
- Add route, middleware, admin, Commerce, Econt preference, migration, MySQL, concurrent-confirmation and local SMTP checks.
- Expand the English README and verification report.
