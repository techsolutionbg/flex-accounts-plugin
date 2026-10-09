<?php

declare(strict_types=1);

namespace Flex\Accounts;

use Flex\Extension\V1\{ExtensionApiInterface, PluginContext};

final class CommerceBridge
{
    public function __construct(
        private readonly AccountStore $store,
        private readonly AccountAuthService $auth,
        private readonly CommerceDetector $detector,
    ) {}

    public function register(PluginContext $context): void
    {
        if (!$this->detector->active()) {
            return;
        }

        $api = $context->api;
        $store = $this->store;
        $auth = $this->auth;

        $api->addFilter('commerce.checkout.assets', static function (mixed $assets): mixed {
            $list = is_array($assets) ? $assets : [];
            $list[] = ['type' => 'script', 'href' => '/extensions/flex/accounts/assets/assets/checkout.js?v=2'];

            return $list;
        });

        $api->addFilter('commerce.checkout.data', static function (mixed $data) use ($store, $auth): mixed {
            if (!is_array($data)) {
                return $data;
            }
            $settings = $store->settings();
            $user = $auth->user();
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

        $api->addFilter('commerce.order.validate', static function (mixed $customer, array $context) use ($store, $auth): mixed {
            $settings = $store->settings();
            if (($settings['require_login_checkout'] ?? false) && $auth->user() === null) {
                throw new \InvalidArgumentException('За да завършите поръчката, влезте в профила си.', 401);
            }
            if (!is_array($customer)) {
                return $customer;
            }
            $user = $auth->user();
            if ($user !== null) {
                $customer['account_id'] = $user['id'];
            }

            return $customer;
        });

        $api->addAction('commerce.order.placed', static function (array $context) use ($store, $auth): void {
            $user = $auth->user();
            $order = is_array($context['order'] ?? null) ? $context['order'] : null;
            if ($user === null || $order === null || !isset($order['id'])) {
                return;
            }
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

    /**
     * @return list<array<string, mixed>>
     */
    public function ordersForCurrent(): array
    {
        $user = $this->auth->user();
        if ($user === null || !$this->detector->active()) {
            return [];
        }
        if (!($this->store->settings()['show_orders_in_account'] ?? true)) {
            return [];
        }
        if (!class_exists(\Flex\Commerce\OrderStore::class)) {
            return [];
        }
        $ids = $this->store->orderIdsFor((int) $user['id']);
        if ($ids === []) {
            return [];
        }
        $orderStore = new \Flex\Commerce\OrderStore();
        $orders = [];
        foreach ($ids as $id) {
            try {
                $order = $orderStore->get($id);
                $orders[] = [
                    'id' => $order['id'],
                    'number' => $order['number'],
                    'status' => $order['status'],
                    'payment_status' => $order['payment_status'],
                    'total_minor' => $order['total_minor'],
                    'currency' => $order['currency'],
                    'created_at' => $order['created_at'] ?? null,
                ];
            } catch (\Throwable) {
                // Skip missing or inaccessible orders.
            }
        }

        return $orders;
    }

    /** @return array<string, mixed>|null */
    public function orderForCurrent(int $orderId): ?array
    {
        $user = $this->auth->user();
        if ($user === null || !$this->detector->active() || !$this->store->ownsOrder((int) $user['id'], $orderId)) {
            return null;
        }
        if (!class_exists(\Flex\Commerce\OrderStore::class)) {
            return null;
        }
        try {
            return (new \Flex\Commerce\OrderStore())->get($orderId);
        } catch (\Throwable) {
            return null;
        }
    }
}
