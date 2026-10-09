<?php

declare(strict_types=1);

namespace Flex\Accounts\Tests;

use Flex\Accounts\{AccountAuthService, AccountLoginThrottle, AccountRecovery, AccountSessionReader, AccountStore};
use Flex\Auth\PasswordHasher;
use Flex\Configuration\{ConfigurationRepository, ProjectPaths};
use Flex\Database\DatabaseManager;
use Flex\Tests\Support\ArraySession;
use PHPUnit\Framework\Attributes\{PreserveGlobalState, RunInSeparateProcess};
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

foreach (['AccountStore', 'AccountRateLimiter', 'AccountEmailVerification', 'AccountLoginThrottle', 'AccountAuthService', 'AccountRecovery', 'AccountSessionReader'] as $class) {
    require_once __DIR__ . '/../src/' . $class . '.php';
}

final class ReleaseReadinessTest extends TestCase
{
    private DatabaseManager $database;
    private AccountStore $store;
    private AccountRecovery $recovery;
    private AccountAuthService $auth;
    private \Flex\Accounts\AccountEmailVerification $verification;
    private ArraySession $session;
    private ConfigurationRepository $config;
    private int $customerId;
    private string $temp;
    /** @var list<Email> */
    private array $messages = [];

    protected function setUp(): void
    {
        $this->temp = sys_get_temp_dir() . '/flex-accounts-readiness-' . bin2hex(random_bytes(8));
        $this->config = new ConfigurationRepository([
            'app' => ['key' => 'isolated-readiness-test-key', 'url' => 'https://shop.example.test'],
            'paths' => ['storage' => 'storage'],
            'mail' => ['from' => ['address' => 'noreply@example.test', 'name' => 'Test']],
            'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            ]]],
        ]);
        $this->database = new DatabaseManager($this->config);
        $this->store = new AccountStore($this->database->connection());
        $this->store->install();
        $this->customerId = $this->store->create([
            'email' => 'customer@example.test', 'first_name' => 'Test', 'last_name' => 'Customer',
        ], (new PasswordHasher())->hash('old-password-123'))['id'];
        $this->session = new ArraySession();
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function (Email $message): void {
            $this->messages[] = $message;
        });
        $this->recovery = new AccountRecovery($this->store, $this->config, new PasswordHasher(), $mailer);
        $this->verification = new \Flex\Accounts\AccountEmailVerification($this->store, $this->config, $mailer,
            new \Flex\Accounts\AccountRateLimiter($this->store, $this->config));
        $this->auth = $this->newAuth($this->session);
    }

    protected function tearDown(): void
    {
        $this->database->disconnect();
        foreach (glob($this->temp . '/storage/cache/accounts-auth/*.json') ?: [] as $file) {
            unlink($file);
        }
        foreach (['/storage/cache/accounts-auth', '/storage/cache', '/storage', ''] as $suffix) {
            if (is_dir($this->temp . $suffix)) rmdir($this->temp . $suffix);
        }
    }

    private function newAuth(ArraySession $session): AccountAuthService
    {
        return new AccountAuthService($this->store, new PasswordHasher(), $session,
            new AccountLoginThrottle(new ProjectPaths($this->temp, $this->config), $this->config), $this->verification);
    }

    private function code(): string
    {
        $this->recovery->request('customer@example.test', '192.0.2.1');
        self::assertCount(1, $this->messages);
        self::assertSame(1, preg_match('/\b\d{6}\b/', (string) $this->messages[0]->getTextBody(), $matches));
        return $matches[0];
    }

    public function testRecoveryChangesPasswordRevokesSessionsAndRejectsReplay(): void
    {
        self::assertTrue($this->auth->attempt('customer@example.test', 'old-password-123', '192.0.2.1'));
        $oldSession = clone $this->session;
        $code = $this->code();
        $grant = $this->recovery->verify('customer@example.test', $code, '192.0.2.1');
        $row = $this->store->connection()->table('flex_accounts_password_resets')->first();
        self::assertNotSame($code, $row->code_hash);
        self::assertNotSame($grant, $row->grant_hash);
        $this->recovery->reset('customer@example.test', $grant, 'new-password-123', 'new-password-123', '192.0.2.1');
        self::assertNull($this->newAuth($oldSession)->user());
        self::assertTrue(password_verify('new-password-123', $this->store->findByEmail('customer@example.test')['password_hash']));
        $this->expectException(\InvalidArgumentException::class);
        $this->recovery->reset('customer@example.test', $grant, 'replayed-password', 'replayed-password', '192.0.2.1');
    }

    public function testExpiredCodeIsRejected(): void
    {
        $code = $this->code();
        $this->store->connection()->table('flex_accounts_password_resets')->update(['expires_at' => gmdate('Y-m-d H:i:s', time() - 1)]);
        $this->expectException(\InvalidArgumentException::class);
        $this->recovery->verify('customer@example.test', $code, '192.0.2.1');
    }

    public function testExpiredGrantIsRejected(): void
    {
        $grant = $this->recovery->verify('customer@example.test', $this->code(), '192.0.2.1');
        $this->store->connection()->table('flex_accounts_password_resets')->update(['grant_expires_at' => gmdate('Y-m-d H:i:s', time() - 1)]);
        $this->expectException(\InvalidArgumentException::class);
        $this->recovery->reset('customer@example.test', $grant, 'new-password-123', 'new-password-123', '192.0.2.1');
    }

    public function testFiveWrongCodesBlockTheCorrectCode(): void
    {
        $code = $this->code();
        $wrong = $code === '111111' ? '222222' : '111111';
        for ($i = 0; $i < 5; ++$i) {
            try {
                $this->recovery->verify('customer@example.test', $wrong, '192.0.2.1');
                self::fail('Wrong code accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertSame(422, $error->getCode());
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->recovery->verify('customer@example.test', $code, '192.0.2.1');
    }

    public function testUnknownEmailDoesNotSendMailOrCreateReset(): void
    {
        $this->recovery->request('missing@example.test', '192.0.2.1');
        self::assertCount(0, $this->messages);
        self::assertSame(0, $this->store->connection()->table('flex_accounts_password_resets')->count());
    }

    public function testEmailFailureInvalidatesTheResetRequest(): void
    {
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willThrowException(new \RuntimeException('Test mail delivery failure'));
        $recovery = new AccountRecovery($this->store, $this->config, new PasswordHasher(), $mailer);
        try {
            $recovery->request('customer@example.test', '192.0.2.1');
            self::fail('Delivery failure was swallowed.');
        } catch (\RuntimeException $error) {
            self::assertSame('Test mail delivery failure', $error->getMessage());
        }
        self::assertNotNull($this->store->connection()->table('flex_accounts_password_resets')->value('used_at'));
    }

    public function testPasswordChangeRevokesOtherSessions(): void
    {
        self::assertTrue($this->auth->attempt('customer@example.test', 'old-password-123', '192.0.2.1'));
        $oldSession = clone $this->session;
        $this->auth->changePassword('old-password-123', 'new-password-123', 'new-password-123');
        self::assertNull($this->newAuth($oldSession)->user());
        self::assertNotNull($this->newAuth($this->session)->user());
    }

    public function testOldRecoveryGrantCannotReplaceAManuallyChangedPassword(): void
    {
        $grant = $this->recovery->verify('customer@example.test', $this->code(), '192.0.2.1');
        self::assertTrue($this->auth->attempt('customer@example.test', 'old-password-123', '192.0.2.1'));
        $this->auth->changePassword('old-password-123', 'manual-password-123', 'manual-password-123');
        $this->expectException(\InvalidArgumentException::class);
        $this->recovery->reset('customer@example.test', $grant, 'reset-password-123', 'reset-password-123', '192.0.2.1');
    }

    public function testBackslashRedirectCannotLeaveTheSite(): void
    {
        self::assertFalse($this->store->safeRedirectPath('/\\external.example'));
        self::assertSame('/', $this->auth->redirectAfterLogin('/\\external.example'));
    }

    public function testRedirectValidationAndStoredSettings(): void
    {
        foreach (["/\t/external.example", " /account", "/account\n", '/%5cexternal.example', '//external.example', 'https://external.example'] as $path) {
            self::assertFalse($this->store->safeRedirectPath($path), $path);
            self::assertSame('/', $this->auth->redirectAfterLogin($path));
        }
        self::assertSame('/account?next=%2Fcheckout', $this->auth->redirectAfterLogin('/account?next=%2Fcheckout'));
        $this->store->connection()->table('flex_accounts_settings')->where('id', 1)
            ->update(['values' => json_encode(['redirect_after_login' => '/\\external.example'])]);
        self::assertSame('/', $this->auth->redirectAfterLogin());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSessionReaderRejectsAnExpiredSession(): void
    {
        self::assertTrue(session_start(['use_cookies' => 0, 'cache_limiter' => '']));
        try {
            $_SESSION[AccountAuthService::SESSION_USER] = $this->customerId;
            $_SESSION[AccountAuthService::SESSION_VERSION] = 1;
            $_SESSION[AccountAuthService::SESSION_ACTIVITY] = time() - 121 * 60;
            $_SESSION['unrelated_admin_key'] = 'preserved';
            self::assertNull(AccountSessionReader::user($this->store));
            self::assertArrayNotHasKey(AccountAuthService::SESSION_USER, $_SESSION);
            self::assertSame('preserved', $_SESSION['unrelated_admin_key']);
        } finally {
            session_destroy();
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSessionReaderDoesNotExtendAnActiveSession(): void
    {
        self::assertTrue(session_start(['use_cookies' => 0, 'cache_limiter' => '']));
        try {
            $activity = time() - 60;
            $_SESSION[AccountAuthService::SESSION_USER] = $this->customerId;
            $_SESSION[AccountAuthService::SESSION_VERSION] = 1;
            $_SESSION[AccountAuthService::SESSION_ACTIVITY] = $activity;
            self::assertSame($this->customerId, AccountSessionReader::user($this->store)['id']);
            self::assertSame($activity, $_SESSION[AccountAuthService::SESSION_ACTIVITY]);
            self::assertFalse(AccountAuthService::sessionExpired(time()));
            self::assertTrue(AccountAuthService::sessionExpired(time() - 120 * 60));
            self::assertTrue(AccountAuthService::sessionExpired(null));
            self::assertTrue(AccountAuthService::sessionExpired('invalid'));
        } finally {
            session_destroy();
        }
    }

    public function testEmailVerificationActivatesNewCustomersAndAllowsLogin(): void
    {
        $this->store->saveSettings(['email_verification' => true]);
        $customer = $this->auth->register([
            'email' => 'pending@example.test', 'first_name' => 'Pending', 'last_name' => 'Customer',
            'password' => 'pending-password-123', 'password_confirmation' => 'pending-password-123',
        ], '192.0.2.2');
        self::assertSame('pending', $customer['status']);
        self::assertNull($customer['email_verified_at']);
        $this->store->setStatus($customer['id'], 'active');
        self::assertFalse($this->auth->attempt('pending@example.test', 'pending-password-123', '192.0.2.2'));
        self::assertNull($this->store->find($customer['id'])['email_verified_at']);
        self::assertCount(1, $this->messages);
        self::assertSame(1, preg_match('/token=([a-f0-9]{64})/', (string) $this->messages[0]->getTextBody(), $matches));
        $this->verification->confirm($customer['id'], $matches[1], '192.0.2.2');
        self::assertNotNull($this->store->find($customer['id'])['email_verified_at']);
        self::assertTrue($this->auth->attempt('pending@example.test', 'pending-password-123', '192.0.2.2'));
    }
}
