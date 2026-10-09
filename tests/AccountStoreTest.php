<?php

declare(strict_types=1);

namespace Flex\Accounts\Tests;

use Flex\Accounts\AccountStore;
use Flex\Auth\PasswordHasher;
use Flex\Configuration\ConfigurationRepository;
use Flex\Database\DatabaseManager;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/AccountStore.php';

final class AccountStoreTest extends TestCase
{
    private DatabaseManager $db;
    private AccountStore $store;

    protected function setUp(): void
    {
        $this->db = new DatabaseManager(new ConfigurationRepository([
            'database' => [
                'default' => 'sqlite',
                'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']],
            ],
        ]));
        $this->store = new AccountStore($this->db->connection());
        $this->store->install();
    }

    protected function tearDown(): void
    {
        $this->db->disconnect();
    }

    public function testInstallCreatesTablesAndDefaultSettings(): void
    {
        self::assertTrue($this->db->schema()->hasTable('flex_accounts_customers'));
        self::assertTrue($this->db->schema()->hasTable('flex_accounts_password_resets'));
        self::assertTrue($this->db->schema()->hasTable('flex_accounts_order_links'));
        self::assertTrue($this->store->settings()['registration_enabled']);
        self::assertSame(10, $this->store->settings()['password_min_length']);
    }

    public function testCreateFindAndAdminList(): void
    {
        $hash = (new PasswordHasher())->hash('password-12345');
        $customer = $this->store->create([
            'email' => 'client@example.com',
            'first_name' => 'Иван',
            'last_name' => 'Петров',
            'phone' => '+359888123456',
        ], $hash);
        self::assertSame('client@example.com', $customer['email']);
        self::assertSame($customer['id'], $this->store->findByEmail('CLIENT@example.com')['id']);
        $list = $this->store->adminList('all', 'client');
        self::assertCount(1, $list['records']);
        self::assertSame('Иван', $list['records'][0]['first_name']);
        self::assertArrayNotHasKey('password_hash', $list['records'][0]);
        self::assertCount(1, $this->store->adminList('all', '888123')['records']);
        self::assertCount(1, $this->store->adminList('all', '+359888')['records']);
        self::assertCount(1, $this->store->adminList('all', '', 1, ['phone' => 'yes'])['records']);
        self::assertCount(0, $this->store->adminList('all', '', 1, ['phone' => 'no'])['records']);
        self::assertCount(1, $this->store->adminList('all', '', 1, ['verified' => 'yes'])['records']);
        self::assertCount(1, $this->store->adminList('all', '', 1, ['login' => 'never'])['records']);
        self::assertCount(0, $this->store->adminList('all', '', 1, ['login' => 'yes'])['records']);
    }

    public function testSettingsValidationAndOrderLinks(): void
    {
        $saved = $this->store->saveSettings([
            'require_login_checkout' => true,
            'redirect_after_login' => '/catalog',
            'password_min_length' => 12,
        ]);
        self::assertTrue($saved['require_login_checkout']);
        self::assertSame('/catalog', $saved['redirect_after_login']);
        try {
            $this->store->saveSettings(['redirect_after_login' => 'https://evil.example']);
            self::fail('Unsafe redirect accepted');
        } catch (\InvalidArgumentException $e) {
            self::assertSame(422, $e->getCode());
        }
        $this->store->linkOrder(1, 10);
        $this->store->linkOrder(1, 10);
        self::assertSame([10], $this->store->orderIdsFor(1));
        self::assertTrue($this->store->ownsOrder(1, 10));
        self::assertFalse($this->store->ownsOrder(1, 11));
    }

    public function testDisableBumpsAuthVersion(): void
    {
        $hash = (new PasswordHasher())->hash('password-12345');
        $customer = $this->store->create([
            'email' => 'a@example.com',
            'first_name' => 'A',
            'last_name' => 'B',
            'phone' => null,
        ], $hash);
        $disabled = $this->store->setStatus((int) $customer['id'], 'disabled');
        self::assertSame('disabled', $disabled['status']);
        self::assertSame((int) $customer['auth_version'] + 1, (int) $disabled['auth_version']);
    }

    public function testShippingPreferencesPersistAndClear(): void
    {
        $hash = (new PasswordHasher())->hash('password-12345');
        $customer = $this->store->create([
            'email' => 'office@example.com',
            'first_name' => 'Офис',
            'last_name' => 'Клиент',
            'phone' => null,
        ], $hash);
        self::assertNull($customer['shipping_preferences']);
        self::assertTrue($this->db->schema()->hasColumn('flex_accounts_customers', 'shipping_preferences'));

        $saved = $this->store->updateShippingPreferences((int) $customer['id'], [
            'shipping' => 'econt_office',
            'office_code' => '1121',
            'office_name' => 'София - Център',
            'office_address' => 'ул. Пример 1',
            'city' => 'София',
            'postcode' => '1000',
        ]);
        self::assertSame('1121', $saved['shipping_preferences']['office_code']);
        self::assertSame('София - Център', $saved['shipping_preferences']['office_name']);
        self::assertSame('1121', $this->store->find((int) $customer['id'])['shipping_preferences']['office_code']);

        try {
            $this->store->updateShippingPreferences((int) $customer['id'], [
                'shipping' => 'econt_office',
                'office_code' => 'bad code!',
            ]);
            self::fail('Invalid office code accepted');
        } catch (\InvalidArgumentException $e) {
            self::assertSame(422, $e->getCode());
        }

        $cleared = $this->store->updateShippingPreferences((int) $customer['id'], null);
        self::assertNull($cleared['shipping_preferences']);
    }

    public function testUninstallRespectsRetentionFlag(): void
    {
        $this->store->uninstall();
        self::assertTrue($this->db->schema()->hasTable('flex_accounts_customers'));
        $this->store->saveSettings(['delete_on_uninstall' => true]);
        $this->store->uninstall();
        self::assertFalse($this->db->schema()->hasTable('flex_accounts_customers'));
    }
}
