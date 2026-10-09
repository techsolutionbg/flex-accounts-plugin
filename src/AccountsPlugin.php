<?php

declare(strict_types=1);

namespace Flex\Accounts;

use Flex\Extension\V1\{BootablePluginInterface, PluginContext, PluginInterface, UninstallablePluginInterface, UpdatablePluginInterface};

final class AccountsPlugin implements PluginInterface, BootablePluginInterface, UninstallablePluginInterface, UpdatablePluginInterface
{
    public function install(PluginContext $context): void
    {
        (new AccountStore())->install();
    }

    public function activate(PluginContext $context): void
    {
        (new AccountStore())->install();
    }

    public function update(PluginContext $context, string $fromVersion): void
    {
        (new AccountStore())->install();
    }

    public function deactivate(PluginContext $context): void {}

    public function uninstall(PluginContext $context): void
    {
        (new AccountStore())->uninstall();
    }

    public function boot(PluginContext $context): void
    {
        (new AccountStore())->install();
        $detector = new CommerceDetector();
        $commerceActive = $detector->active();

        $context->api->addFilter('accounts.current', static fn(mixed $value): mixed => AccountSessionReader::user() ?? $value);
        $context->api->addFilter('accounts.commerce_active', static fn(mixed $value): mixed => $commerceActive);

        $csrf = [\Flex\Session\CsrfMiddleware::class];
        $context->routes?->publicRoute(['GET', 'POST'], '/account/register', PublicController::class, 'register', $csrf);
        $context->routes?->publicRoute(['GET', 'POST'], '/account/login', PublicController::class, 'login', $csrf);
        $context->routes?->publicRoute('POST', '/account/logout', PublicController::class, 'logout', $csrf);
        $context->routes?->publicRoute(['GET', 'POST'], '/account', PublicController::class, 'account', $csrf);
        $context->routes?->publicRoute(['GET', 'POST'], '/account/password', PublicController::class, 'password', $csrf);
        $context->routes?->publicRoute(['GET', 'POST'], '/account/forgot', PublicController::class, 'forgot', $csrf);
        $context->routes?->publicRoute(['GET', 'POST'], '/account/reset', PublicController::class, 'reset', $csrf);
        $context->routes?->publicRoute(['GET', 'POST'], '/account/verify-email', PublicController::class, 'verify-email', $csrf);
        $context->routes?->publicRoute(['GET', 'POST'], '/account/resend-verification', PublicController::class, 'resend-verification', $csrf);

        $context->routes?->get('/session', PublicController::class, 'session');
        $context->routes?->post('/login', PublicController::class, 'api-login', $csrf);
        $context->routes?->post('/register', PublicController::class, 'api-register', $csrf);
        $context->routes?->post('/logout', PublicController::class, 'api-logout', $csrf);

        $group = $commerceActive ? 'commerce' : 'extensions';
        $navigation = ['group' => $group, 'icon' => 'users'];
        if ($commerceActive) {
            $navigation['section'] = 'customers';
            $navigation['order'] = 1;
        }
        $context->admin?->page('customers', 'Акаунти', 'assets/admin.js', false, $navigation);
        $context->routes?->admin(['GET', 'PUT'], '/customers/{id:number}', AdminController::class, 'customer');
        $context->routes?->admin('GET', '/customers', AdminController::class, 'customers');
        $context->routes?->admin(['GET', 'PUT'], '/settings', AdminController::class, 'settings');

        if (!$commerceActive) {
            return;
        }

        $context->api->addFilter('commerce.checkout.assets', static function (mixed $assets): mixed {
            $list = is_array($assets) ? $assets : [];
            $list[] = ['type' => 'script', 'href' => '/extensions/flex/accounts/assets/assets/checkout.js?v=2'];

            return $list;
        });
        $context->api->addFilter('commerce.checkout.data', static function (mixed $data): mixed {
            if (!is_array($data)) {
                return $data;
            }
            $store = new AccountStore();
            $settings = $store->settings();
            $user = AccountSessionReader::user($store);
            $data['accounts'] = [
                'logged_in' => $user !== null,
                'require_login' => (bool) ($settings['require_login_checkout'] ?? false),
                'login_url' => '/account/login?next=' . rawurlencode('/checkout'),
            ];
            if ($user !== null && ($settings['prefill_checkout'] ?? true)) {
                $data['customer_prefill'] = [
                    'first_name' => $user['first_name'],
                    'last_name' => $user['last_name'],
                    'email' => $user['email'],
                    'phone' => $user['phone'] ?? '',
                ];
                $prefs = is_array($user['shipping_preferences'] ?? null) ? $user['shipping_preferences'] : null;
                if ($prefs !== null && ($prefs['office_code'] ?? '') !== '') {
                    $data['shipping_prefill'] = [
                        'shipping' => (string) ($prefs['shipping'] ?? 'econt_office'),
                        'shipping_meta' => [
                            'office_code' => (string) $prefs['office_code'],
                            'office_name' => (string) ($prefs['office_name'] ?? ''),
                            'office_address' => (string) ($prefs['office_address'] ?? ''),
                            'city' => (string) ($prefs['city'] ?? ''),
                            'postcode' => (string) ($prefs['postcode'] ?? ''),
                        ],
                    ];
                }
            }

            return $data;
        });
        $context->api->addFilter('commerce.order.validate', static function (mixed $customer): mixed {
            $store = new AccountStore();
            $settings = $store->settings();
            $user = AccountSessionReader::user($store);
            if (($settings['require_login_checkout'] ?? false) && $user === null) {
                $message = 'За да завършите поръчката, влезте в профила си.';
                if (class_exists(\Flex\Commerce\CommerceException::class)) {
                    throw new \Flex\Commerce\CommerceException($message, 401);
                }
                throw new \InvalidArgumentException($message, 401);
            }
            if (!is_array($customer)) {
                return $customer;
            }
            if ($user !== null) {
                $customer['account_id'] = $user['id'];
            }

            return $customer;
        });
        $context->api->addAction('commerce.order.placed', static function (array $payload): void {
            $user = AccountSessionReader::user();
            $order = is_array($payload['order'] ?? null) ? $payload['order'] : null;
            if ($user === null || $order === null || !isset($order['id'])) {
                return;
            }
            $store = new AccountStore();
            $store->linkOrder((int) $user['id'], (int) $order['id']);
            $customer = is_array($order['customer'] ?? null) ? $order['customer'] : [];
            $shipping = is_string($customer['shipping'] ?? null) ? $customer['shipping'] : '';
            $meta = is_array($customer['shipping_meta'] ?? null) ? $customer['shipping_meta'] : [];
            if ($shipping === 'econt_office' && is_string($meta['office_code'] ?? null) && trim((string) $meta['office_code']) !== '') {
                try {
                    $store->updateShippingPreferences((int) $user['id'], [
                        'shipping' => 'econt_office',
                        'office_code' => $meta['office_code'],
                        'office_name' => $meta['office_name'] ?? '',
                        'office_address' => $meta['office_address'] ?? ($customer['address'] ?? ''),
                        'city' => $meta['city'] ?? ($customer['city'] ?? ''),
                        'postcode' => $meta['postcode'] ?? ($customer['postcode'] ?? ''),
                    ]);
                } catch (\Throwable) {
                    // Order must remain placed even if preference save fails.
                }
            }
        });
        $context->routes?->publicRoute('GET', '/account/orders', PublicController::class, 'orders');
        $context->routes?->publicRoute('GET', '/account/orders/{id:number}', PublicController::class, 'order');
    }
}
