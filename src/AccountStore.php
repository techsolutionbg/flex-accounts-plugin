<?php

declare(strict_types=1);

namespace Flex\Accounts;

use Illuminate\Database\{Connection, Eloquent\Model, Schema\Blueprint};

final class AccountStore
{
    private Connection $db;

    public const DEFAULTS = [
        'registration_enabled' => true,
        'email_verification' => false,
        'password_min_length' => 10,
        'redirect_after_login' => '/',
        'require_login_checkout' => false,
        'prefill_checkout' => true,
        'show_orders_in_account' => true,
        'delete_on_uninstall' => false,
    ];

    public function __construct(?Connection $db = null)
    {
        $this->db = $db ?? Model::resolveConnection();
    }

    public function install(): void
    {
        $schema = $this->db->getSchemaBuilder();
        if (!$schema->hasTable('flex_accounts_customers')) {
            $schema->create('flex_accounts_customers', static function (Blueprint $t): void {
                $t->increments('id');
                $t->string('email', 190)->unique();
                $t->string('password_hash', 255);
                $t->string('first_name', 80);
                $t->string('last_name', 80);
                $t->string('phone', 30)->nullable();
                $t->string('status', 16)->default('active');
                $t->unsignedInteger('auth_version')->default(1);
                $t->timestamp('email_verified_at')->nullable();
                $t->boolean('email_verification_required')->default(false);
                $t->timestamp('last_login_at')->nullable();
                $t->text('shipping_preferences')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index(['status', 'created_at']);
            });
        }
        if ($schema->hasTable('flex_accounts_customers') && !$schema->hasColumn('flex_accounts_customers', 'shipping_preferences')) {
            $schema->table('flex_accounts_customers', static function (Blueprint $t): void {
                $t->text('shipping_preferences')->nullable();
            });
        }
        if (!$schema->hasTable('flex_accounts_password_resets')) {
            $schema->create('flex_accounts_password_resets', static function (Blueprint $t): void {
                $t->increments('id');
                $t->unsignedInteger('customer_id');
                $t->string('code_hash', 64);
                $t->string('grant_hash', 64)->nullable();
                $t->unsignedTinyInteger('attempts')->default(0);
                $t->timestamp('expires_at');
                $t->timestamp('grant_expires_at')->nullable();
                $t->timestamp('used_at')->nullable();
                $t->timestamps();
                $t->index(['customer_id', 'used_at']);
            });
        }
        if (!$schema->hasTable('flex_accounts_email_verifications')) {
            $schema->create('flex_accounts_email_verifications', static function (Blueprint $t): void {
                $t->increments('id');
                $t->unsignedInteger('customer_id');
                $t->string('token_hash', 64)->unique();
                $t->timestamp('expires_at');
                $t->timestamp('used_at')->nullable();
                $t->timestamps();
                $t->index(['customer_id', 'used_at'], 'accounts_verification_customer_used_index');
            });
        }
        if (!$schema->hasColumn('flex_accounts_customers', 'email_verification_required')) {
            $schema->table('flex_accounts_customers', static function (Blueprint $t): void {
                $t->boolean('email_verification_required')->default(false);
            });
            // Preserve existing active customers; old pending registrations still need confirmation.
            $this->db->table('flex_accounts_customers')->where('status', 'pending')->whereNull('email_verified_at')
                ->update(['email_verification_required' => true]);
        }
        if (!$schema->hasTable('flex_accounts_reset_limits')) {
            $schema->create('flex_accounts_reset_limits', static function (Blueprint $t): void {
                $t->string('key', 64)->primary();
                $t->unsignedInteger('attempts')->default(0);
                $t->unsignedInteger('window_started_at')->default(0);
                $t->unsignedInteger('last_attempt_at')->default(0);
            });
        }
        if (!$schema->hasTable('flex_accounts_order_links')) {
            $schema->create('flex_accounts_order_links', static function (Blueprint $t): void {
                $t->increments('id');
                $t->unsignedInteger('customer_id');
                $t->unsignedInteger('order_id');
                $t->timestamps();
                $t->unique(['customer_id', 'order_id']);
                $t->index(['order_id']);
            });
        }
        if (!$schema->hasTable('flex_accounts_settings')) {
            $schema->create('flex_accounts_settings', static function (Blueprint $t): void {
                $t->integer('id')->primary();
                $t->text('values');
            });
            $this->db->table('flex_accounts_settings')->insert(['id' => 1, 'values' => '{}']);
        }
    }

    public function uninstall(): void
    {
        $settings = $this->settings();
        if (!($settings['delete_on_uninstall'] ?? false)) {
            return;
        }
        $schema = $this->db->getSchemaBuilder();
        foreach (['flex_accounts_order_links', 'flex_accounts_email_verifications', 'flex_accounts_password_resets', 'flex_accounts_reset_limits', 'flex_accounts_customers', 'flex_accounts_settings'] as $table) {
            $schema->dropIfExists($table);
        }
    }

    /** @return array<string, mixed> */
    public function settings(): array
    {
        return array_replace(self::DEFAULTS, json_decode($this->db->table('flex_accounts_settings')->where('id', 1)->value('values') ?? '{}', true) ?: []);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function saveSettings(array $input): array
    {
        $values = $this->settings();
        foreach (self::DEFAULTS as $key => $default) {
            if (!array_key_exists($key, $input)) {
                continue;
            }
            $value = $input[$key];
            if (is_bool($default) && !is_bool($value)) {
                throw new \InvalidArgumentException('Невалидна настройка: ' . $key, 422);
            }
            if (is_int($default) && (!is_int($value) || $value < 0)) {
                throw new \InvalidArgumentException('Невалидна числова настройка.', 422);
            }
            if ($key === 'password_min_length' && ($value < 8 || $value > 72)) {
                throw new \InvalidArgumentException('Минималната дължина на паролата трябва да е между 8 и 72.', 422);
            }
            if ($key === 'redirect_after_login') {
                if (!is_string($value) || !$this->safeRedirectPath($value)) {
                    throw new \InvalidArgumentException('Невалиден път за пренасочване след вход.', 422);
                }
                $value = $value === '' ? '/' : $value;
            }
            $values[$key] = $value;
        }
        $this->db->table('flex_accounts_settings')->where('id', 1)->update(['values' => json_encode($values, JSON_THROW_ON_ERROR)]);

        return $values;
    }

    public function safeRedirectPath(string $path): bool
    {
        if ($path === '') {
            return true;
        }
        if (!str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '://')
            || str_contains($path, '\\') || preg_match('/[\x00-\x20\x7F]/', $path)
            || preg_match('/%(?:0[0-9a-f]|1[0-9a-f]|20|5c|7f)/i', $path)) {
            return false;
        }

        return true;
    }

    /**
     * @return array{id: int, email: string, first_name: string, last_name: string, phone: ?string, status: string, auth_version: int, email_verified_at: ?string, email_verification_required: bool, last_login_at: ?string, created_at: ?string, shipping_preferences: ?array<string, string>}|null
     */
    public function find(int $id): ?array
    {
        $row = $this->db->table('flex_accounts_customers')->where('id', $id)->whereNull('deleted_at')->first();

        return $row ? $this->public($row) : null;
    }

    /**
     * @return array{id: int, email: string, first_name: string, last_name: string, phone: ?string, status: string, auth_version: int, email_verified_at: ?string, email_verification_required: bool, last_login_at: ?string, created_at: ?string, shipping_preferences: ?array<string, string>, password_hash: string}|null
     */
    public function findByEmail(string $email): ?array
    {
        $email = mb_strtolower(trim($email));
        $row = $this->db->table('flex_accounts_customers')->where('email', $email)->whereNull('deleted_at')->first();
        if ($row === null) {
            return null;
        }
        $public = $this->public($row);
        $public['password_hash'] = (string) $row->password_hash;

        return $public;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{id: int, email: string, first_name: string, last_name: string, phone: ?string, status: string, auth_version: int, email_verified_at: ?string, email_verification_required: bool, last_login_at: ?string, created_at: ?string}
     */
    public function create(array $input, string $passwordHash, string $status = 'active'): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $id = $this->db->table('flex_accounts_customers')->insertGetId([
            'email' => $input['email'],
            'password_hash' => $passwordHash,
            'first_name' => $input['first_name'],
            'last_name' => $input['last_name'],
            'phone' => $input['phone'] ?? null,
            'status' => $status,
            'auth_version' => 1,
            'email_verified_at' => $status === 'active' && !($this->settings()['email_verification'] ?? false) ? $now : null,
            'email_verification_required' => $status === 'pending' && (bool) ($this->settings()['email_verification'] ?? false),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $customer = $this->find($id);
        if ($customer === null) {
            throw new \RuntimeException('Клиентът не може да бъде създаден.');
        }

        return $customer;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{id: int, email: string, first_name: string, last_name: string, phone: ?string, status: string, auth_version: int, email_verified_at: ?string, email_verification_required: bool, last_login_at: ?string, created_at: ?string, shipping_preferences: ?array<string, string>}
     */
    public function updateProfile(int $id, array $input): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->db->table('flex_accounts_customers')->where('id', $id)->whereNull('deleted_at')->update([
            'first_name' => $input['first_name'],
            'last_name' => $input['last_name'],
            'phone' => $input['phone'] ?? null,
            'updated_at' => $now,
        ]);
        $customer = $this->find($id);
        if ($customer === null) {
            throw new \InvalidArgumentException('Профилът не е намерен.', 404);
        }

        return $customer;
    }

    /**
     * @param array<string, mixed>|null $preferences
     * @return array{id: int, email: string, first_name: string, last_name: string, phone: ?string, status: string, auth_version: int, email_verified_at: ?string, email_verification_required: bool, last_login_at: ?string, created_at: ?string, shipping_preferences: ?array<string, string>}
     */
    public function updateShippingPreferences(int $id, ?array $preferences): array
    {
        $encoded = null;
        if ($preferences !== null) {
            $normalized = $this->normalizeShippingPreferences($preferences);
            if ($normalized === null) {
                throw new \InvalidArgumentException('Изберете валиден офис за доставка.', 422);
            }
            $encoded = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        $updated = $this->db->table('flex_accounts_customers')->where('id', $id)->whereNull('deleted_at')->update([
            'shipping_preferences' => $encoded,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        if ($updated < 1 && $this->find($id) === null) {
            throw new \InvalidArgumentException('Профилът не е намерен.', 404);
        }
        $customer = $this->find($id);
        if ($customer === null) {
            throw new \InvalidArgumentException('Профилът не е намерен.', 404);
        }

        return $customer;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{shipping: string, office_code: string, office_name: string, office_address: string, city: string, postcode: string}|null
     */
    public function normalizeShippingPreferences(array $input): ?array
    {
        $shipping = is_string($input['shipping'] ?? null) ? trim($input['shipping']) : 'econt_office';
        if ($shipping !== 'econt_office') {
            return null;
        }
        $code = is_string($input['office_code'] ?? null) ? trim($input['office_code']) : '';
        if ($code === '' || mb_strlen($code) > 40 || !preg_match('/^[A-Za-z0-9._-]+$/D', $code)) {
            return null;
        }
        $name = is_string($input['office_name'] ?? null) ? trim($input['office_name']) : '';
        $address = is_string($input['office_address'] ?? null) ? trim($input['office_address']) : '';
        $city = is_string($input['city'] ?? null) ? trim($input['city']) : '';
        $postcode = is_string($input['postcode'] ?? null) ? trim($input['postcode']) : '';
        foreach (['name' => $name, 'address' => $address, 'city' => $city] as $label => $value) {
            if (mb_strlen($value) > 200 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) {
                return null;
            }
        }
        if (mb_strlen($postcode) > 20 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $postcode)) {
            return null;
        }

        return [
            'shipping' => 'econt_office',
            'office_code' => $code,
            'office_name' => mb_substr($name, 0, 200),
            'office_address' => mb_substr($address, 0, 200),
            'city' => mb_substr($city, 0, 100),
            'postcode' => mb_substr($postcode, 0, 20),
        ];
    }

    public function updatePassword(int $id, string $passwordHash): void
    {
        $this->db->transaction(function () use ($id, $passwordHash): void {
            $row = $this->db->table('flex_accounts_customers')->where('id', $id)->whereNull('deleted_at')->lockForUpdate()->first();
            if ($row === null) {
                throw new \InvalidArgumentException('Профилът не е намерен.', 404);
            }
            $now = gmdate('Y-m-d H:i:s');
            $this->db->table('flex_accounts_customers')->where('id', $id)->update([
                'password_hash' => $passwordHash,
                'auth_version' => (int) $row->auth_version + 1,
                'updated_at' => $now,
            ]);
            $this->db->table('flex_accounts_password_resets')->where('customer_id', $id)
                ->whereNull('used_at')->update(['used_at' => $now]);
        });
    }

    public function touchLogin(int $id): void
    {
        $this->db->table('flex_accounts_customers')->where('id', $id)->update([
            'last_login_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function setStatus(int $id, string $status): array
    {
        if (!in_array($status, ['active', 'pending', 'disabled'], true)) {
            throw new \InvalidArgumentException('Невалиден статус.', 422);
        }
        $row = $this->db->table('flex_accounts_customers')->where('id', $id)->whereNull('deleted_at')->first();
        if ($row === null) {
            throw new \InvalidArgumentException('Клиентът не е намерен.', 404);
        }
        $update = ['status' => $status, 'updated_at' => gmdate('Y-m-d H:i:s')];
        if ($status === 'disabled') {
            $update['auth_version'] = (int) $row->auth_version + 1;
        }
        $this->db->table('flex_accounts_customers')->where('id', $id)->update($update);
        $customer = $this->find($id);
        if ($customer === null) {
            throw new \InvalidArgumentException('Клиентът не е намерен.', 404);
        }

        return $customer;
    }

    /**
     * @return array{records: list<array<string, mixed>>, page: int, pages: int, count: int}
     */
    /**
     * @param array{verified?: string, login?: string, phone?: string} $filters
     * @return array{records: list<array<string, mixed>>, page: int, pages: int, count: int}
     */
    public function adminList(string $status = 'all', string $search = '', int $page = 1, array $filters = []): array
    {
        if (!in_array($status, ['all', 'active', 'pending', 'disabled'], true)) {
            throw new \InvalidArgumentException('Невалиден статус.', 422);
        }
        $verified = (string) ($filters['verified'] ?? 'all');
        $login = (string) ($filters['login'] ?? 'all');
        $phone = (string) ($filters['phone'] ?? 'all');
        if (!in_array($verified, ['all', 'yes', 'no'], true)
            || !in_array($login, ['all', 'yes', 'never'], true)
            || !in_array($phone, ['all', 'yes', 'no'], true)) {
            throw new \InvalidArgumentException('Невалиден филтър.', 422);
        }
        $q = $this->db->table('flex_accounts_customers')->whereNull('deleted_at');
        if ($status !== 'all') {
            $q->where('status', $status);
        }
        if ($verified === 'yes') {
            $q->whereNotNull('email_verified_at');
        } elseif ($verified === 'no') {
            $q->whereNull('email_verified_at');
        }
        if ($login === 'yes') {
            $q->whereNotNull('last_login_at');
        } elseif ($login === 'never') {
            $q->whereNull('last_login_at');
        }
        if ($phone === 'yes') {
            $q->whereNotNull('phone')->where('phone', '!=', '');
        } elseif ($phone === 'no') {
            $q->where(static function ($query): void {
                $query->whereNull('phone')->orWhere('phone', '');
            });
        }
        $search = trim($search);
        if (mb_strlen($search) > 200) {
            throw new \InvalidArgumentException('Търсенето е ограничено до 200 символа.', 422);
        }
        if ($search !== '') {
            $pattern = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search)) . '%';
            $fullName = $this->db->getDriverName() === 'sqlite'
                ? "LOWER(first_name || ' ' || last_name)"
                : "LOWER(CONCAT(first_name, ' ', last_name))";
            $q->where(static function ($query) use ($pattern, $fullName): void {
                $query->whereRaw("LOWER(email) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(first_name) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(last_name) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw($fullName . " LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(COALESCE(phone, '')) LIKE ? ESCAPE '!'", [$pattern]);
            });
        }
        $count = $q->count();
        $pages = max(1, (int) ceil($count / 25));
        $page = max(1, min($pages, $page));
        $records = $count
            ? $q->orderByDesc('id')->offset(($page - 1) * 25)->limit(25)->get()->map(fn ($row) => $this->public($row))->all()
            : [];

        return ['records' => $records, 'page' => $page, 'pages' => $pages, 'count' => $count];
    }

    public function linkOrder(int $customerId, int $orderId): void
    {
        if ($customerId < 1 || $orderId < 1) {
            return;
        }
        $now = gmdate('Y-m-d H:i:s');
        $this->db->table('flex_accounts_order_links')->insertOrIgnore([
            'customer_id' => $customerId,
            'order_id' => $orderId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @return list<int> */
    public function orderIdsFor(int $customerId): array
    {
        return $this->db->table('flex_accounts_order_links')
            ->where('customer_id', $customerId)
            ->orderByDesc('order_id')
            ->pluck('order_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * The customer's Flex Commerce orders for the account list, newest first, in one query and without the
     * items, customer details and history that only the order page needs.
     * @return list<array{id: int, number: string, status: string, payment_status: string, total_minor: int, currency: string, created_at: string|null}>
     */
    public function orderSummariesFor(int $customerId): array
    {
        $ids = $this->orderIdsFor($customerId);
        if ($ids === []) {
            return [];
        }

        return $this->db->table('flex_commerce_orders')
            ->whereIn('id', $ids)
            ->orderByDesc('id')
            ->get(['id', 'number', 'status', 'payment_status', 'total_minor', 'currency', 'created_at'])
            ->map(static fn (object $row): array => [
                'id' => (int) $row->id,
                'number' => (string) $row->number,
                'status' => (string) $row->status,
                'payment_status' => (string) $row->payment_status,
                'total_minor' => (int) $row->total_minor,
                'currency' => (string) $row->currency,
                'created_at' => $row->created_at === null ? null : (string) $row->created_at,
            ])
            ->all();
    }

    public function ownsOrder(int $customerId, int $orderId): bool
    {
        return $this->db->table('flex_accounts_order_links')
            ->where('customer_id', $customerId)
            ->where('order_id', $orderId)
            ->exists();
    }

    public function connection(): Connection
    {
        return $this->db;
    }

    /**
     * @param object $row
     * @return array{id: int, email: string, first_name: string, last_name: string, phone: ?string, status: string, auth_version: int, email_verified_at: ?string, email_verification_required: bool, last_login_at: ?string, created_at: ?string, shipping_preferences: ?array<string, string>}
     */
    private function public(object $row): array
    {
        $preferences = null;
        if (isset($row->shipping_preferences) && is_string($row->shipping_preferences) && $row->shipping_preferences !== '') {
            $decoded = json_decode($row->shipping_preferences, true);
            $preferences = is_array($decoded) ? $this->normalizeShippingPreferences($decoded) : null;
        }

        return [
            'id' => (int) $row->id,
            'email' => (string) $row->email,
            'first_name' => (string) $row->first_name,
            'last_name' => (string) $row->last_name,
            'phone' => $row->phone !== null ? (string) $row->phone : null,
            'status' => (string) $row->status,
            'auth_version' => (int) $row->auth_version,
            'email_verified_at' => $row->email_verified_at !== null ? (string) $row->email_verified_at : null,
            'email_verification_required' => (bool) $row->email_verification_required,
            'last_login_at' => $row->last_login_at !== null ? (string) $row->last_login_at : null,
            'created_at' => $row->created_at !== null ? (string) $row->created_at : null,
            'shipping_preferences' => $preferences,
        ];
    }
}
