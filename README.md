# Flex Accounts 0.4.0

Flex Accounts adds customer registration, login, profiles and password recovery to the public side of a Flex CMS website. Customers are stored separately from CMS administrators. Requires **Flex CMS 0.1.62 or later**. **Flex Commerce is optional**: account features also work on websites without a shop.

## Installation

Place the plugin in `plugins/flex/accounts` inside Flex CMS. Run from the CMS project root:

```sh
php bin/flex plugin:install flex/accounts
php bin/flex plugin:activate flex/accounts
```

The plugin uses the platform database, sessions, password hashing, mailer and extension APIs. It is not a standalone application. Configure the platform mail transport and sender before using recovery or email confirmation. Set `APP_URL` to the public site URL; confirmation links use this trusted configuration rather than a request Host header. Keep the application key stable because recovery secrets depend on it.

Installation, activation, updates and boot ensure the plugin schema exists. Account pages use the active theme page layout with plugin Twig templates. Shipped JavaScript and CSS provide account navigation, authentication dialogs and optional checkout integration; no separate frontend build is required.

## How customer accounts work

1. Customers register with email, first name, last name, password confirmation and an optional phone number. Email addresses are normalized to lowercase and must be unique.
2. Default registration creates an active account and signs the customer in. Administrators can disable registration.
3. Login checks the password, account status and authentication version. Successful authentication regenerates the session ID. Failed logins are throttled by email and IP address.
4. Customers can update their names and phone number. There is no customer email-change workflow.
5. Password change requires the current password and matching new password fields. It atomically invalidates outstanding recovery codes and grants, revokes other customer sessions and refreshes the current session.
6. Logout clears customer authentication keys.

Passwords require at least 10 characters by default. The configurable minimum is 8–72 characters; passwords must also fit within 72 bytes, which matters for multibyte text.

Sessions expire after 120 minutes of inactivity. Account authentication requests refresh activity. The lightweight reader used by theme and Commerce hooks checks expiry without extending it. Missing or malformed activity timestamps are rejected. Expiry clears only the plugin authentication keys, preserving unrelated session data.

After login, both `next` and the configured destination must be local paths starting with a single `/`. External URLs, backslashes, whitespace and control characters are rejected. Invalid configured destinations fall back to `/`.

## Password recovery

Customers request a six-digit code by email, verify it to obtain a reset grant, then submit a new password and confirmation. Codes expire after 15 minutes; grants expire after 5 minutes. Five incorrect code attempts block the request. Successful reset consumes outstanding recovery requests and invalidates existing customer sessions.

Codes and grants are stored as keyed hashes. Recovery requests have email and IP limits and a resend cooldown; verification and reset also have IP limits. Unknown addresses receive a generic response without generating mail. Mail delivery failure invalidates the associated request. A normal password change revokes previously issued recovery credentials in the same transaction as the password update.

## Public pages and JSON endpoints

| Path | Methods | Purpose |
| --- | --- | --- |
| `/account/register` | GET, POST | Registration |
| `/account/login` | GET, POST | Login |
| `/account/logout` | POST | Logout |
| `/account` | GET, POST | View and update profile |
| `/account/password` | GET, POST | Change password |
| `/account/forgot` | GET, POST | Request recovery code |
| `/account/reset` | GET, POST | Verify code and reset password |
| `/account/verify-email` | GET, POST | Show and submit email confirmation |
| `/account/resend-verification` | GET, POST | Request a replacement confirmation link |
| `/account/orders` | GET | Linked orders with active Commerce |
| `/account/orders/{id}` | GET | An owned linked order with active Commerce |

JSON endpoints under `/plugins/flex/accounts` are `GET /session`, `POST /login`, `POST /register` and `POST /logout`. POST routes use platform CSRF middleware. The session endpoint returns public customer information, Commerce availability, registration availability and a CSRF token; password hashes are excluded. Session responses and rendered account pages use no-store caching.

## Administration and configuration

Admin sections **Акаунти** (Accounts) and **Настройки на акаунтите** (Account settings) appear under Shop when Commerce is active, otherwise under Extensions. Administrators can search and filter customers, inspect details and change status. Supported statuses are `active`, `pending` and `disabled`. Disabling an account invalidates its sessions.

| Setting | Default | Purpose |
| --- | --- | --- |
| `registration_enabled` | `true` | Allow registration |
| `email_verification` | `false` | Require email confirmation for new registrations |
| `password_min_length` | `10` | Minimum password character count |
| `redirect_after_login` | `/` | Local destination after authentication |
| `require_login_checkout` | `false` | Require customer login during Commerce checkout |
| `prefill_checkout` | `true` | Prefill supported checkout fields |
| `show_orders_in_account` | `true` | Show customer order history |
| `delete_on_uninstall` | `false` | Allow plugin tables to be dropped on uninstall |

## Optional Flex Commerce integration

With active Commerce, Accounts can prefill checkout names, email and phone, require login at checkout and link newly placed orders to the signed-in customer. Order pages check customer-to-order ownership links. Existing guest orders are not automatically imported by matching email addresses.

Supported shipping preferences can be saved for future checkout. Econt office integration additionally requires the corresponding shipping service. Shop behavior is inactive without Commerce.

Integration uses `accounts.current`, `accounts.commerce_active`, `commerce.checkout.assets`, `commerce.checkout.data`, `commerce.order.validate` and `commerce.order.placed`. Authentication emits `accounts.logged_in` and `accounts.logged_out` actions.

## Data and removal

The plugin maintains `flex_accounts_customers`, `flex_accounts_settings`, `flex_accounts_password_resets`, `flex_accounts_reset_limits`, `flex_accounts_email_verifications` and `flex_accounts_order_links`: customer records, configuration, recovery state, scoped rate limits, email-confirmation tokens and order ownership. Login throttle files live under the platform storage cache directory.

Deactivation preserves data. Uninstall preserves tables by default. Enabling `delete_on_uninstall` permits uninstall to drop all six tables; back up customer data before using that option.

## Email confirmation

Email confirmation is optional and disabled by default. When enabled, **new registrations** create pending accounts and send a unique confirmation link valid for 24 hours. Opening the link displays a confirmation form; pressing its button confirms the address and activates the account. GET requests do not consume tokens, so email scanners do not accidentally activate accounts. Customers then sign in with their password; confirmation never signs them in automatically.

Tokens contain 32 random bytes, are stored as keyed hashes and can be used only once. Confirmation locks the customer and consumes the token atomically. Expired, reused, malformed or mismatched tokens are rejected. Disabled and deleted customers cannot be activated through a link. Confirmation pages use no-store caching, no-referrer and noindex headers.

Customers can request a replacement at `/account/resend-verification`, linked from the login page. Resending invalidates older links. Limits are five requests per email and twenty per IP in fifteen minutes, with a sixty-second email cooldown; confirmation has a thirty-attempt IP limit per fifteen minutes. Unknown, already confirmed and disabled accounts receive the same generic resend response without mail.

A mail delivery failure preserves the pending account, invalidates the unsent token and displays a retry message. After the cooldown, the customer can request a new link instead of registering again. SMTP acceptance does not guarantee delivery to an external inbox; administrators should test their production mail transport and spam placement.

Enabling confirmation does not remove access from existing active customers. A per-customer `email_verification_required` flag records the requirement at registration. Turning the setting off or manually marking a new unconfirmed account active does not bypass its requirement. Existing pending registrations from the older version can use the resend page after upgrade.

For legacy accounts and registrations made with confirmation disabled, the existing internal timestamp may be filled automatically; it is not proof of email ownership. Only the new confirmation flow demonstrates ownership through its token. Password recovery is a separate process.

## Updating and language

Version 0.2.0 adds the confirmation-token table and the per-customer requirement column automatically during installation, activation, update or boot. Schema updates are additive and preserve customer data. Existing active customers are retained; old pending accounts still require confirmation. Back up the database before updating an existing installation.

Customer-facing messages, confirmation emails and some administration labels are currently in Bulgarian. This README is in English.

## Tests

Run from the Flex CMS project root with its dependencies installed:

```sh
php vendor/bin/phpunit plugins/flex/accounts/tests --display-warnings
```

For this project Docker setup:

```sh
docker compose exec -T app vendor/bin/phpunit plugins/flex/accounts/tests --display-warnings
```

On 2026-10-09, all **36 plugin tests passed with 311 assertions**. Additional checks passed against MySQL, the platform mail provider with actual local SMTP capture, and concurrent MySQL confirmations. The platform and Commerce regression suites also passed. Browser smoke checks covered the active theme, resend form, invalid-link page and AJAX login failure.

Optional checks for this project's local Docker services:

```sh
docker compose exec -T -e FLEX_ACCOUNTS_TEST_MYSQL=1 app vendor/bin/phpunit plugins/flex/accounts/tests/AccountsIntegrationTest.php --display-warnings
docker compose exec -T app php plugins/flex/accounts/tests/smtp-smoke.php
docker compose exec -T app php plugins/flex/accounts/tests/mysql-race.php
```

The MySQL checks use randomly prefixed temporary tables and clean them up. The SMTP check uses isolated SQLite, sends only to local Mailpit and deletes only its own captured messages. Do not treat these scripts as production deliverability or courier API tests. See [TEST_REPORT.md](TEST_REPORT.md) for exact scope and [CHANGELOG.md](CHANGELOG.md) for changes.

## License

This plugin is licensed under the GNU General Public License, version 2 or (at your option) any later version (`GPL-2.0-or-later`). The full text of version 2 is in [`LICENSE`](LICENSE).
