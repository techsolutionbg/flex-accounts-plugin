<?php

declare(strict_types=1);

namespace Flex\Accounts\Tests;

use Flex\Accounts\{AccountAuthService, AccountLoginThrottle, AccountStore};
use Flex\Auth\PasswordHasher;
use Flex\Configuration\{ConfigurationRepository, ProjectPaths};
use Flex\Contracts\Session\SessionInterface;
use Flex\Database\DatabaseManager;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/AccountStore.php';
require_once __DIR__ . '/../src/AccountLoginThrottle.php';
require_once __DIR__ . '/../src/AccountAuthService.php';
require_once __DIR__ . '/../src/AccountRateLimiter.php';
require_once __DIR__ . '/../src/AccountEmailVerification.php';

final class AccountAuthServiceTest extends TestCase
{
    private DatabaseManager $db;
    private AccountStore $store;
    private ArraySession $session;
    private AccountAuthService $auth;
    private string $temp;

    protected function setUp(): void
    {
        $this->temp = sys_get_temp_dir() . '/flex-accounts-' . bin2hex(random_bytes(4));
        mkdir($this->temp . '/storage/cache/accounts-auth', 0777, true);
        $config = new ConfigurationRepository([
            'app' => ['key' => str_repeat('a', 64)],
            'database' => [
                'default' => 'sqlite',
                'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']],
            ],
            'paths' => ['storage' => 'storage'],
        ]);
        $this->db = new DatabaseManager($config);
        $this->store = new AccountStore($this->db->connection());
        $this->store->install();
        $this->session = new ArraySession();
        $this->auth = new AccountAuthService(
            $this->store,
            new PasswordHasher(),
            $this->session,
            new AccountLoginThrottle(new ProjectPaths($this->temp, $config), $config),
            new \Flex\Accounts\AccountEmailVerification($this->store, $config,
                $this->createStub(\Symfony\Component\Mailer\MailerInterface::class),
                new \Flex\Accounts\AccountRateLimiter($this->store, $config)),
        );
    }

    protected function tearDown(): void
    {
        $this->db->disconnect();
        $this->removeTree($this->temp);
    }

    public function testRegisterLoginAndLogout(): void
    {
        $customer = $this->auth->register([
            'email' => 'user@example.com',
            'first_name' => 'Мария',
            'last_name' => 'Иванова',
            'phone' => '',
            'password' => 'secure-pass-1',
            'password_confirmation' => 'secure-pass-1',
        ], '127.0.0.1');
        self::assertSame('active', $customer['status']);
        self::assertNotNull($this->auth->user());
        $this->auth->logout();
        self::assertNull($this->auth->user());
        self::assertTrue($this->auth->attempt('user@example.com', 'secure-pass-1', '127.0.0.1'));
        self::assertSame('user@example.com', $this->auth->user()['email']);
        self::assertFalse($this->auth->attempt('user@example.com', 'wrong-password', '127.0.0.1'));
    }

    public function testPasswordChangeInvalidatesOldSessionVersion(): void
    {
        $this->auth->register([
            'email' => 'user@example.com',
            'first_name' => 'Мария',
            'last_name' => 'Иванова',
            'password' => 'secure-pass-1',
            'password_confirmation' => 'secure-pass-1',
        ], '127.0.0.1');
        $oldVersion = (int) $this->session->get(AccountAuthService::SESSION_VERSION);
        $this->auth->changePassword('secure-pass-1', 'secure-pass-2', 'secure-pass-2');
        self::assertGreaterThan($oldVersion, (int) $this->session->get(AccountAuthService::SESSION_VERSION));
        self::assertTrue($this->auth->attempt('user@example.com', 'secure-pass-2', '127.0.0.1'));
    }

    public function testSafeRedirectOnly(): void
    {
        self::assertSame('/catalog', $this->auth->redirectAfterLogin('/catalog'));
        self::assertSame('/', $this->auth->redirectAfterLogin('https://evil.test'));
        self::assertSame('/', $this->auth->redirectAfterLogin('//evil.test'));
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . '/' . $item;
            is_dir($full) ? $this->removeTree($full) : @unlink($full);
        }
        @rmdir($path);
    }
}

final class ArraySession implements SessionInterface
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function start(): void {}
    public function has(string $key): bool { return array_key_exists($key, $this->data); }
    public function get(string $key, mixed $default = null): mixed { return $this->data[$key] ?? $default; }
    public function put(string $key, mixed $value): void { $this->data[$key] = $value; }
    public function remove(string $key): void { unset($this->data[$key]); }
    public function regenerate(): void {}
    public function invalidate(): void { $this->data = []; }
}
