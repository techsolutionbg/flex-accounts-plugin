# Flex Accounts 0.2.0 verification report

Date: 2026-10-09. The previous three defects are fixed, and optional email confirmation is implemented and verified within the scope below.

## Results

| Check | Result |
| --- | --- |
| Accounts unit and integration suite, SQLite | 36 tests, 311 assertions; passed |
| Accounts route and integration suite, MySQL | 14 tests, 198 assertions; passed |
| Flex Commerce regression suite | 34 tests, 250 assertions; passed |
| Platform regression suite | 180 tests, 984 assertions; no failures, one existing skip |
| SMTP through the platform MailServiceProvider and SettingsMailer | Actual local Mailpit capture, verification and recovery; passed |
| Concurrent MySQL verification | Two independent worker processes: exactly one confirmation succeeds; passed |
| PHP syntax | All Accounts source and test scripts passed |
| JavaScript syntax | admin.js, accounts.js and checkout.js passed |
| Browser smoke checks in the installed site's theme | Login/resend navigation, resend response, invalid-link handling and AJAX login error; passed, no console errors observed |

## Covered behavior

- Verification-enabled registration creates a pending customer, sends mail and denies login until confirmation. Default registration remains active and sends no verification mail.
- The emailed URL uses configured APP_URL. Tokens are hashed, expire after 24 hours and cannot be reused or applied to another customer. Opening a link does not consume it; confirmation requires a CSRF-protected POST.
- Resending observes cooldown and email/IP limits and invalidates older links. Unknown, confirmed and disabled customers do not receive verification mail.
- A simulated SMTP failure invalidates the unsent token, preserves the pending customer and permits a later resend and successful confirmation.
- Disabled and deleted customers cannot be activated by confirmation. Turning the setting off or manually marking a new unconfirmed customer active does not bypass the per-customer requirement.
- Existing active customers retain login/session access when verification is enabled. An upgrade from the previous schema preserves them and allows old pending registrations to resend and confirm. Repeated installation is idempotent.
- Public route/middleware checks cover CSRF, session JSON without password hashes, login, profile updates, password changes, logout, authentication expiry, registration validation and login throttling.
- Admin routes reject unauthenticated and non-super-admin access; settings and status updates require CSRF. Disabling a customer invalidates its session.
- Recovery checks cover the public code/grant/password flow, expiration, wrong-code limits, hashed secrets, replay rejection, mailer failure, unknown email and session revocation. A manual password change invalidates outstanding recovery grants.
- Requested and persisted login destinations reject unsafe redirects; valid local paths remain usable.
- Commerce checks use the plugin's actual boot hooks: checkout prefill, required-login validation, actual order creation, ownership linking and denial of access to another customer's order. Expired sessions cannot pass a checkout login requirement.
- Shipping preference checks cover saving an office after an order, checkout prefill, clearing/saving from the profile and acceptance of saved metadata by the real Econt ShippingService validator.
- Uninstall preserves data by default and drops all six tables only with delete_on_uninstall enabled.

## Isolation and limits

Unit tests use in-memory SQLite and captured fake mail. MySQL tests use uniquely prefixed temporary tables in the local Docker database; those tables are removed after tests. No business customer records or orders are created by these tests. SMTP smoke uses the real platform mail provider with a forced local Mailpit transport and a random example.test recipient; only its own captured messages are deleted. It verifies SMTP transport and received contents, not delivery to Gmail or another external inbox. The Mailpit script uses its documented [API](https://mailpit.axllent.org/docs/api-v1/).

Browser checks used the installed site's actual theme and JavaScript. The resend submission used a synthetic unknown address and sent no mail. These checks are a targeted smoke test, not a complete browser E2E suite for every customer and shop interaction.

Econt office metadata integration is checked locally. External office lookups, live shipping prices and waybill creation were not exercised. No paid or production courier operation was performed. Concurrency testing covers reuse of one verification token; it is not an exhaustive test of every possible database race. Production SMTP authentication, TLS, spam placement and site-specific deployment configuration still need validation in the deployment environment.

## Reproduction

Run in the Flex CMS development checkout with Composer development dependencies:

```sh
docker compose exec -T app vendor/bin/phpunit plugins/flex/accounts/tests --display-warnings
docker compose exec -T -e FLEX_ACCOUNTS_TEST_MYSQL=1 app vendor/bin/phpunit plugins/flex/accounts/tests/AccountsIntegrationTest.php --display-warnings
docker compose exec -T app vendor/bin/phpunit plugins/flex/commerce/tests --display-warnings
docker compose exec -T app vendor/bin/phpunit --display-warnings
docker compose exec -T app php plugins/flex/accounts/tests/smtp-smoke.php
docker compose exec -T app php plugins/flex/accounts/tests/mysql-race.php
```
