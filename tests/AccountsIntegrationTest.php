<?php

declare(strict_types=1);

namespace Flex\Accounts\Tests;

use DI\Container;
use DI\ContainerBuilder;
use Flex\Accounts\{AccountAuthService, AccountEmailVerification, AccountRateLimiter, AccountRecovery, AccountSessionReader, AccountStore, AccountsPlugin, CommerceBridge, CommerceDetector, PublicController};
use Flex\Auth\{AuthenticatedUser, PasswordHasher};
use Flex\Configuration\{ConfigurationRepository, ProjectPaths};
use Flex\Contracts\Auth\AuthenticationInterface;
use Flex\Contracts\Configuration\ConfigRepositoryInterface;
use Flex\Contracts\Http\ResponseFactoryInterface;
use Flex\Contracts\Session\SessionInterface;
use Flex\Database\DatabaseManager;
use Flex\Extension\V1\PluginContext;
use Flex\Extensions\{ExtensionApi, PluginEntrypointLoader, PluginManifest, PluginRouteRegistrar};
use Flex\Http\{ResponseFactory, RequestInput};
use Flex\Http\Routing\{RouteRegistry, RouterFactory};
use Flex\Media\{MediaService, PublicMediaApi};
use Flex\Menus\PublicMenuRenderer;
use Flex\Pages\PageRepository;
use Flex\Session\CsrfTokenManager;
use Flex\Tests\Support\ArraySession;
use Flex\Themes\ThemeManager;
use Illuminate\Database\Schema\Blueprint;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\{PreserveGlobalState, RunInSeparateProcess};
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

(new PluginEntrypointLoader())->load(PluginManifest::fromFile(__DIR__ . '/../plugin.json'), dirname(__DIR__));

final class AccountsIntegrationTest extends TestCase
{
    private DatabaseManager $database;
    private AccountStore $store;
    private ConfigurationRepository $config;
    private Container $container;
    private ArraySession $session;
    private string $temp;
    private string $prefix = '';
    private bool $mailFailure = false;
    private ?AuthenticatedUser $admin = null;
    private ExtensionApi $api;
    private RouteRegistry $routes;
    /** @var list<Email> */
    private array $messages = [];

    protected function setUp(): void
    {
        $this->temp = sys_get_temp_dir() . '/flex-accounts-integration-' . bin2hex(random_bytes(8));
        mkdir($this->temp . '/themes/test/templates', 0775, true);
        file_put_contents($this->temp . '/themes/test/templates/page.twig', '<!doctype html><html><body><main>{{ page.content|raw }}</main></body></html>');
        $connection = ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''];
        if (getenv('FLEX_ACCOUNTS_TEST_MYSQL') === '1') {
            (new \Flex\Configuration\EnvironmentLoader())->load(dirname(__DIR__, 4));
            $platform = (new \Flex\Configuration\ConfigurationLoader())->load(dirname(__DIR__, 4), false);
            $connection = $platform->array('database.connections.mysql');
            $this->prefix = 'at' . bin2hex(random_bytes(3)) . '_';
            $connection['prefix'] = $this->prefix;
        }
        $this->config = new ConfigurationRepository([
            'app' => ['key' => 'integration-key', 'url' => 'https://shop.example.test', 'active_theme' => 'test'],
            'paths' => ['storage' => 'storage', 'themes' => 'themes'],
            'session' => ['csrf_token_lifetime' => 7200],
            'mail' => ['from' => ['address' => 'noreply@example.test', 'name' => 'Test']],
            'database' => ['default' => 'test', 'connections' => ['test' => $connection]],
        ]);
        $this->database = new DatabaseManager($this->config);
        $schema = $this->database->schema();
        $schema->create('plugins', static function (Blueprint $t): void { $t->string('id')->primary(); $t->string('status'); });
        $schema->create('settings', static function (Blueprint $t): void { $t->string('key')->primary(); $t->text('value'); });
        $schema->create('pages', static function (Blueprint $t): void { $t->increments('id'); $t->softDeletes(); });
        $this->store = new AccountStore($this->database->connection());
        $this->store->install();
        $this->session = new ArraySession();
        $paths = new ProjectPaths($this->temp, $this->config);
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(function (Email $message): void {
            if ($this->mailFailure) throw new \RuntimeException('Simulated SMTP failure');
            $this->messages[] = $message;
        });
        $auth = $this->createStub(AuthenticationInterface::class);
        $auth->method('user')->willReturnCallback(fn() => $this->admin);
        $auth->method('check')->willReturnCallback(fn() => $this->admin !== null);
        $builder = new ContainerBuilder();
        $builder->addDefinitions([
            AccountStore::class => $this->store,
            ConfigRepositoryInterface::class => $this->config,
            SessionInterface::class => $this->session,
            ProjectPaths::class => $paths,
            MailerInterface::class => $mailer,
            AuthenticationInterface::class => $auth,
            ResponseFactoryInterface::class => new ResponseFactory(new Psr17Factory()),
            ThemeManager::class => new ThemeManager($paths, $this->config),
            PublicMenuRenderer::class => new PublicMenuRenderer($this->database),
            PublicMediaApi::class => new PublicMediaApi(new MediaService($this->database, $paths, $this->config)),
        ]);
        $this->container = $builder->build();
        $this->bootPlugin();
    }

    protected function tearDown(): void
    {
        if ($this->prefix !== '') {
            foreach ($this->database->schema()->getTableListing() as $table) {
                if (str_starts_with($table, $this->prefix)) {
                    $this->database->schema()->drop(substr($table, strlen($this->prefix)));
                }
            }
        }
        $this->database->disconnect();
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->temp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        rmdir($this->temp);
    }

    private function bootPlugin(bool $commerce = false): void
    {
        $this->database->connection()->table('plugins')->updateOrInsert(['id' => 'flex/commerce'], ['status' => $commerce ? 'active' : 'inactive']);
        $this->api = new ExtensionApi();
        $this->routes = new RouteRegistry();
        $manifest = PluginManifest::fromFile(__DIR__ . '/../plugin.json');
        $context = new PluginContext($manifest->id, $manifest->version, dirname(__DIR__), [], $this->api,
            new PluginRouteRegistrar($this->routes, $manifest->id, $manifest->permissions));
        (new AccountsPlugin())->boot($context);
    }

    private function request(string $method, string $path, array $data = [], bool $csrf = true): ResponseInterface
    {
        // Services cache the current customer within a request, not across requests.
        $this->container->set(AccountAuthService::class, $this->container->make(AccountAuthService::class));
        $this->container->set(PublicController::class, $this->container->make(PublicController::class));
        $request = (new ServerRequest($method, 'https://shop.example.test' . $path, [], null, '1.1', ['REMOTE_ADDR' => '192.0.2.1']))
            ->withHeader('Accept', str_contains($path, '/plugins/') ? 'application/json' : 'text/html');
        if ($method === 'GET') {
            parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
            $request = $request->withQueryParams($query);
        } else {
            if ($csrf) $data['_token'] = $this->container->get(CsrfTokenManager::class)->token();
            $request = $request->withParsedBody($data);
        }
        // A fresh registry preserves the platform's real route and middleware behavior.
        $routes = new RouteRegistry();
        foreach ($this->routes->all() as $route) $routes->add($route->methods, $route->path, $route->handler, $route->name, $route->middleware);
        return (new RouterFactory($this->container, $routes))->create()->dispatch($request);
    }

    private function register(string $email = 'customer@example.test'): array
    {
        $response = $this->request('POST', '/plugins/flex/accounts/register', [
            'email' => $email, 'first_name' => 'Test', 'last_name' => 'Customer', 'phone' => '+359888123456',
            'password' => 'password-12345', 'password_confirmation' => 'password-12345',
        ]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function link(int $index = 0): array
    {
        self::assertSame(1, preg_match('#https://shop\.example\.test/account/verify-email\?customer=(\d+)&token=([a-f0-9]{64})#', (string) $this->messages[$index]->getTextBody(), $match));
        return ['customer' => (int) $match[1], 'token' => $match[2]];
    }

    private function login(string $email = 'customer@example.test', string $password = 'password-12345'): ResponseInterface
    {
        return $this->request('POST', '/plugins/flex/accounts/login', ['email' => $email, 'password' => $password]);
    }

    public function testRegistrationMailConfirmationLoginProfilePasswordAndLogout(): void
    {
        $this->store->saveSettings(['email_verification' => true]);
        $registration = $this->register();
        self::assertTrue($registration['pending']);
        self::assertNull($registration['customer']);
        self::assertSame(401, $this->login()->getStatusCode());
        $link = $this->link();
        $landing = $this->request('GET', '/account/verify-email?' . http_build_query($link));
        self::assertSame(200, $landing->getStatusCode());
        self::assertStringContainsString('Потвърди имейла', (string) $landing->getBody());
        self::assertSame('no-referrer', $landing->getHeaderLine('Referrer-Policy'));
        self::assertNull($this->store->find($link['customer'])['email_verified_at']);
        self::assertSame(403, $this->request('POST', '/account/verify-email', $link, false)->getStatusCode());
        self::assertSame(200, $this->request('POST', '/account/verify-email', $link)->getStatusCode());
        self::assertSame('active', $this->store->find($link['customer'])['status']);
        self::assertSame(422, $this->request('POST', '/account/verify-email', $link)->getStatusCode());
        $this->database->connection()->table('flex_accounts_reset_limits')->update(['last_attempt_at' => time() - 61]);
        self::assertSame(200, $this->request('POST', '/account/resend-verification', ['email' => 'customer@example.test'])->getStatusCode());
        self::assertCount(1, $this->messages);
        self::assertSame(200, $this->login()->getStatusCode());
        $session = $this->request('GET', '/plugins/flex/accounts/session');
        $payload = json_decode((string) $session->getBody(), true);
        self::assertSame($link['customer'], $payload['customer']['id']);
        self::assertArrayNotHasKey('password_hash', $payload['customer']);
        self::assertSame('no-store', $session->getHeaderLine('Cache-Control'));
        self::assertSame(200, $this->request('POST', '/account', ['first_name' => 'Updated', 'last_name' => 'Customer', 'phone' => ''])->getStatusCode());
        self::assertSame('Updated', $this->store->find($link['customer'])['first_name']);
        self::assertSame(200, $this->request('POST', '/account/password', ['current_password' => 'password-12345', 'password' => 'changed-password', 'password_confirmation' => 'changed-password'])->getStatusCode());
        self::assertSame(200, $this->request('POST', '/plugins/flex/accounts/logout')->getStatusCode());
        self::assertSame(401, $this->login()->getStatusCode());
        self::assertSame(200, $this->login('customer@example.test', 'changed-password')->getStatusCode());
    }

    public function testResendCooldownRotationExpirationAndUnknownEmail(): void
    {
        $this->store->saveSettings(['email_verification' => true]);
        $this->register();
        $old = $this->link();
        self::assertSame(429, $this->request('POST', '/account/resend-verification', ['email' => 'customer@example.test'])->getStatusCode());
        $this->database->connection()->table('flex_accounts_reset_limits')->update(['last_attempt_at' => time() - 61]);
        self::assertSame(200, $this->request('POST', '/account/resend-verification', ['email' => 'customer@example.test'])->getStatusCode());
        self::assertCount(2, $this->messages);
        self::assertSame(422, $this->request('POST', '/account/verify-email', $old)->getStatusCode());
        $fresh = $this->link(1);
        self::assertNotSame($old['token'], $fresh['token']);
        self::assertNotSame($fresh['token'], $this->database->connection()->table('flex_accounts_email_verifications')->orderByDesc('id')->value('token_hash'));
        $this->database->connection()->table('flex_accounts_email_verifications')->whereNull('used_at')->update(['expires_at' => gmdate('Y-m-d H:i:s', time() - 1)]);
        self::assertSame(422, $this->request('POST', '/account/verify-email', $fresh)->getStatusCode());
        self::assertSame(200, $this->request('POST', '/account/resend-verification', ['email' => 'unknown@example.test'])->getStatusCode());
        self::assertCount(2, $this->messages);
        self::assertSame(403, $this->request('POST', '/account/resend-verification', ['email' => 'unknown@example.test'], false)->getStatusCode());
    }

    public function testMailFailurePreservesPendingAccountAndAllowsRetry(): void
    {
        $this->store->saveSettings(['email_verification' => true]);
        $this->mailFailure = true;
        $response = $this->request('POST', '/plugins/flex/accounts/register', [
            'email' => 'customer@example.test', 'first_name' => 'Test', 'last_name' => 'Customer',
            'password' => 'password-12345', 'password_confirmation' => 'password-12345',
        ]);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('pending', $this->store->findByEmail('customer@example.test')['status']);
        self::assertSame(0, $this->database->connection()->table('flex_accounts_email_verifications')->whereNull('used_at')->count());
        $this->mailFailure = false;
        $this->database->connection()->table('flex_accounts_reset_limits')->update(['last_attempt_at' => time() - 61]);
        self::assertSame(200, $this->request('POST', '/account/resend-verification', ['email' => 'customer@example.test'])->getStatusCode());
        self::assertSame(200, $this->request('POST', '/account/verify-email', $this->link())->getStatusCode());
        self::assertSame(200, $this->login()->getStatusCode());
    }

    public function testDisabledDeletedAndWrongCustomerCannotBeActivated(): void
    {
        $this->store->saveSettings(['email_verification' => true]);
        $this->register();
        $link = $this->link();
        self::assertSame(422, $this->request('POST', '/account/verify-email', ['customer' => $link['customer'] + 1, 'token' => $link['token']])->getStatusCode());
        $this->store->setStatus($link['customer'], 'disabled');
        self::assertSame(422, $this->request('POST', '/account/verify-email', $link)->getStatusCode());
        self::assertSame('disabled', $this->store->find($link['customer'])['status']);
        $this->database->connection()->table('flex_accounts_reset_limits')->update(['last_attempt_at' => time() - 61]);
        self::assertSame(200, $this->request('POST', '/account/resend-verification', ['email' => 'customer@example.test'])->getStatusCode());
        self::assertCount(1, $this->messages);
        $this->store->setStatus($link['customer'], 'pending');
        $this->database->connection()->table('flex_accounts_customers')->where('id', $link['customer'])->update(['deleted_at' => gmdate('Y-m-d H:i:s')]);
        self::assertSame(422, $this->request('POST', '/account/verify-email', $link)->getStatusCode());
    }

    public function testExistingCustomersAndSessionSurviveEnablingVerification(): void
    {
        $registration = $this->register();
        self::assertFalse($registration['pending']);
        self::assertCount(0, $this->messages);
        $this->database->connection()->table('flex_accounts_customers')->update(['email_verified_at' => null]);
        $this->store->saveSettings(['email_verification' => true]);
        self::assertNotNull(json_decode((string) $this->request('GET', '/plugins/flex/accounts/session')->getBody(), true)['customer']);
        self::assertSame(200, $this->login()->getStatusCode());
        $this->store->saveSettings(['email_verification' => false]);
        self::assertSame(200, $this->login()->getStatusCode());
    }

    public function testVerificationRequirementCannotBeBypassedBySettingOrAdminStatus(): void
    {
        $this->store->saveSettings(['email_verification' => true]);
        $this->register();
        $link = $this->link();
        $this->store->saveSettings(['email_verification' => false]);
        $this->store->setStatus($link['customer'], 'active');
        self::assertSame(401, $this->login()->getStatusCode());
        self::assertSame(200, $this->request('POST', '/account/verify-email', $link)->getStatusCode());
        self::assertSame(200, $this->login()->getStatusCode());
    }

    public function testAdminAccessSettingsFiltersAndDisablingSessions(): void
    {
        $customer = $this->register()['customer'];
        self::assertSame(401, $this->request('GET', '/admin/plugins/flex/accounts/customers')->getStatusCode());
        $this->admin = new AuthenticatedUser(900, 'Admin', 'admin@example.test', 'admin', 'active');
        self::assertSame(403, $this->request('GET', '/admin/plugins/flex/accounts/customers')->getStatusCode());
        $this->admin = new AuthenticatedUser(900, 'Admin', 'admin@example.test', 'super_admin', 'active');
        self::assertSame(200, $this->request('GET', '/admin/plugins/flex/accounts/customers?q=customer')->getStatusCode());
        self::assertSame(403, $this->request('PUT', '/admin/plugins/flex/accounts/settings', ['email_verification' => true], false)->getStatusCode());
        self::assertSame(200, $this->request('PUT', '/admin/plugins/flex/accounts/settings', ['email_verification' => true])->getStatusCode());
        self::assertSame(200, $this->request('PUT', '/admin/plugins/flex/accounts/customers/' . $customer['id'], ['status' => 'disabled'])->getStatusCode());
        self::assertNull(json_decode((string) $this->request('GET', '/plugins/flex/accounts/session')->getBody(), true)['customer']);
        self::assertSame(401, $this->login()->getStatusCode());
    }

    public function testRecoveryThroughPublicPagesAfterVerification(): void
    {
        $this->store->saveSettings(['email_verification' => true]);
        $this->register();
        self::assertSame(200, $this->request('POST', '/account/verify-email', $this->link())->getStatusCode());
        self::assertSame(200, $this->request('POST', '/account/forgot', ['email' => 'customer@example.test'])->getStatusCode());
        self::assertCount(2, $this->messages);
        self::assertSame(1, preg_match('/\b\d{6}\b/', (string) $this->messages[1]->getTextBody(), $matches));
        $response = $this->request('POST', '/account/reset', ['email' => 'customer@example.test', 'code' => $matches[0]]);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, preg_match('/name="grant" value="([a-f0-9]{64})"/', (string) $response->getBody(), $grant));
        self::assertSame(302, $this->request('POST', '/account/reset', ['email' => 'customer@example.test', 'grant' => $grant[1], 'password' => 'recovered-password', 'password_confirmation' => 'recovered-password'])->getStatusCode());
        self::assertSame(200, $this->login('customer@example.test', 'recovered-password')->getStatusCode());
    }

    public function testInvalidTokensAndIpLimits(): void
    {
        for ($i = 0; $i < 30; ++$i) self::assertSame(422, $this->request('POST', '/account/verify-email', ['customer' => 1, 'token' => 'invalid'])->getStatusCode());
        self::assertSame(429, $this->request('POST', '/account/verify-email', ['customer' => 1, 'token' => str_repeat('a', 64)])->getStatusCode());
        self::assertSame(422, $this->request('POST', '/account/resend-verification', ['email' => 'invalid'])->getStatusCode());
        for ($i = 0; $i < 20; ++$i) self::assertSame(200, $this->request('POST', '/account/resend-verification', ['email' => "missing$i@example.test"])->getStatusCode());
        self::assertSame(429, $this->request('POST', '/account/resend-verification', ['email' => 'missing21@example.test'])->getStatusCode());
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCommerceHooksPrefillOrderOwnershipShippingAndExpiredSessions(): void
    {
        self::assertTrue(session_start(['use_cookies' => 0, 'cache_limiter' => '']));
        try {
            $this->bootPlugin(true);
            (new PluginEntrypointLoader())->load(PluginManifest::fromFile(__DIR__ . '/../../commerce/plugin.json'), __DIR__ . '/../../commerce');
            $catalog = new \Flex\Commerce\CatalogStore();
            $catalog->install();
            $orders = new \Flex\Commerce\OrderStore();
            $orders->install();
            $this->database->connection()->table('flex_commerce_products')->insert([
                'id' => 1, 'name' => 'Test', 'slug' => 'test', 'description' => '', 'status' => 'active',
                'price_minor' => 1000, 'stock' => 5, 'images' => '[]', 'category_ids' => '[]', 'sku' => 'TEST',
            ]);
            $customer = $this->register()['customer'];
            $_SESSION = $this->session->values;
            self::assertSame($customer['id'], $this->api->applyFilters('accounts.current', null)['id']);
            $this->store->saveSettings(['require_login_checkout' => true]);
            $prefill = $this->api->applyFilters('commerce.checkout.data', []);
            self::assertSame('customer@example.test', $prefill['customer_prefill']['email']);
            self::assertTrue($prefill['accounts']['require_login']);
            $input = $this->api->applyFilters('commerce.order.validate', [
                'first_name' => 'Test', 'last_name' => 'Customer', 'email' => 'customer@example.test', 'phone' => '+359888123456',
                'city' => 'Sofia', 'postcode' => '1000', 'address' => 'Test 1', 'terms' => true,
                'payment' => 'cod', 'shipping' => 'address', 'fingerprint' => $orders->quote([1 => 1])['fingerprint'],
            ]);
            self::assertSame($customer['id'], $input['account_id']);
            $order = $orders->place([1 => 1], $input, str_repeat('a', 64));
            $order['customer']['shipping'] = 'econt_office';
            $order['customer']['shipping_meta'] = ['office_code' => '1121', 'office_name' => 'Test office', 'city' => 'Sofia', 'postcode' => '1000'];
            $this->api->doAction('commerce.order.placed', ['order' => $order]);
            self::assertTrue($this->store->ownsOrder($customer['id'], $order['id']));
            self::assertSame('1121', $this->store->find($customer['id'])['shipping_preferences']['office_code']);
            self::assertSame('1121', $this->api->applyFilters('commerce.checkout.data', [])['shipping_prefill']['shipping_meta']['office_code']);
            (new PluginEntrypointLoader())->load(PluginManifest::fromFile(__DIR__ . '/../../econt/plugin.json'), __DIR__ . '/../../econt');
            $shipping = new \Flex\Econt\ShippingService();
            $validated = $shipping->validateCustomer(['shipping_meta' => $this->store->find($customer['id'])['shipping_preferences']], ['method' => ['id' => 'econt_office']]);
            self::assertSame('1121', $validated['shipping_meta']['office_code']);
            self::assertSame('econt', $validated['shipping_meta']['provider']);
            self::assertSame(200, $this->request('POST', '/account', ['form' => 'shipping_office', 'shipping_action' => 'clear'])->getStatusCode());
            self::assertNull($this->store->find($customer['id'])['shipping_preferences']);
            self::assertSame(200, $this->request('POST', '/account', ['form' => 'shipping_office', 'office_code' => '1122', 'office_name' => 'Updated office', 'office_city' => 'Sofia', 'office_postcode' => '1000'])->getStatusCode());
            self::assertSame('1122', $this->api->applyFilters('commerce.checkout.data', [])['shipping_prefill']['shipping_meta']['office_code']);
            self::assertSame(200, $this->request('GET', '/account/orders')->getStatusCode());
            self::assertSame(200, $this->request('GET', '/account/orders/' . $order['id'])->getStatusCode());
            $other = $this->store->create(['email' => 'other@example.test', 'first_name' => 'Other', 'last_name' => 'Customer'], (new PasswordHasher())->hash('password-12345'));
            self::assertFalse($this->store->ownsOrder($other['id'], $order['id']));
            self::assertSame(200, $this->login('other@example.test')->getStatusCode());
            self::assertSame(404, $this->request('GET', '/account/orders/' . $order['id'])->getStatusCode());
            $_SESSION[AccountAuthService::SESSION_ACTIVITY] = time() - 121 * 60;
            self::assertFalse($this->api->applyFilters('commerce.checkout.data', [])['accounts']['logged_in']);
            try {
                $this->api->applyFilters('commerce.order.validate', $input);
                self::fail('Expired customer reached checkout.');
            } catch (\Flex\Commerce\CommerceException $e) { self::assertSame(401, $e->getCode()); }
        } finally { session_destroy(); }
    }

    public function testInstallUpgradeAndUninstallRetention(): void
    {
        $this->store->install();
        self::assertTrue($this->database->schema()->hasTable('flex_accounts_email_verifications'));
        $this->register();
        $this->store->uninstall();
        self::assertSame(1, $this->database->connection()->table('flex_accounts_customers')->count());
        $this->store->saveSettings(['delete_on_uninstall' => true]);
        $this->store->uninstall();
        self::assertFalse($this->database->schema()->hasTable('flex_accounts_email_verifications'));
        self::assertFalse($this->database->schema()->hasTable('flex_accounts_customers'));
        self::assertFalse($this->database->schema()->hasTable('flex_accounts_settings'));
        self::assertFalse($this->database->schema()->hasTable('flex_accounts_password_resets'));
        self::assertFalse($this->database->schema()->hasTable('flex_accounts_reset_limits'));
        self::assertFalse($this->database->schema()->hasTable('flex_accounts_order_links'));
    }

    public function testUpgradePreservesLegacyCustomersAndPendingVerification(): void
    {
        $active = $this->register()['customer'];
        $this->store->saveSettings(['email_verification' => true]);
        $pending = $this->store->create(['email' => 'legacy-pending@example.test', 'first_name' => 'Legacy', 'last_name' => 'Pending'], (new PasswordHasher())->hash('password-12345'), 'pending');
        $this->database->connection()->table('flex_accounts_customers')->where('id', $active['id'])->update(['email_verified_at' => null]);
        // Reproduce the previous schema and run the real additive migration twice.
        $this->database->schema()->drop('flex_accounts_email_verifications');
        $this->database->schema()->table('flex_accounts_customers', static fn(Blueprint $t) => $t->dropColumn('email_verification_required'));
        $this->store->install();
        $this->store->install();
        self::assertFalse($this->store->find($active['id'])['email_verification_required']);
        self::assertTrue($this->store->find($pending['id'])['email_verification_required']);
        self::assertSame(200, $this->login()->getStatusCode());
        self::assertSame(401, $this->login('legacy-pending@example.test')->getStatusCode());
        self::assertSame(200, $this->request('POST', '/account/resend-verification', ['email' => 'legacy-pending@example.test'])->getStatusCode());
        self::assertSame(200, $this->request('POST', '/account/verify-email', $this->link())->getStatusCode());
        self::assertSame(200, $this->login('legacy-pending@example.test')->getStatusCode());
    }

    public function testEmailLimitAndInvalidConfiguredUrl(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(200, $this->request('POST', '/account/resend-verification', ['email' => 'absent@example.test'])->getStatusCode());
            $this->database->connection()->table('flex_accounts_reset_limits')->update(['last_attempt_at' => time() - 61]);
        }
        self::assertSame(429, $this->request('POST', '/account/resend-verification', ['email' => 'absent@example.test'])->getStatusCode());
        $customer = $this->store->create(['email' => 'pending-url@example.test', 'first_name' => 'Test', 'last_name' => 'URL'], (new PasswordHasher())->hash('password-12345'), 'pending');
        $config = new ConfigurationRepository(array_replace_recursive($this->config->all(), ['app' => ['url' => 'https://user@external.example']]));
        $service = new AccountEmailVerification($this->store, $config, $this->container->get(MailerInterface::class), new AccountRateLimiter($this->store, $config));
        try {
            $service->request('pending-url@example.test', '192.0.2.20');
            self::fail('Unsafe configured URL accepted.');
        } catch (\InvalidArgumentException $e) { self::assertSame(503, $e->getCode()); }
        self::assertCount(0, $this->messages);
        self::assertSame(0, $this->database->connection()->table('flex_accounts_email_verifications')->where('customer_id', $customer['id'])->whereNull('used_at')->count());
    }

    public function testLoginThrottleRegistrationValidationAndSessionExpiry(): void
    {
        $customer = $this->register()['customer'];
        $this->store->saveSettings(['registration_enabled' => false]);
        self::assertSame(403, $this->request('POST', '/plugins/flex/accounts/register', ['email' => 'new@example.test'])->getStatusCode());
        $this->store->saveSettings(['registration_enabled' => true]);
        self::assertSame(422, $this->request('POST', '/plugins/flex/accounts/register', ['email' => 'invalid'])->getStatusCode());
        self::assertSame(403, $this->request('POST', '/plugins/flex/accounts/logout', [], false)->getStatusCode());
        $this->session->put(AccountAuthService::SESSION_ACTIVITY, time() - 121 * 60);
        self::assertNull(json_decode((string) $this->request('GET', '/plugins/flex/accounts/session')->getBody(), true)['customer']);
        self::assertSame(302, $this->request('GET', '/account')->getStatusCode());
        for ($i = 0; $i < 5; ++$i) self::assertSame(401, $this->login('customer@example.test', 'wrong-password')->getStatusCode());
        self::assertSame(429, $this->login()->getStatusCode());
        self::assertSame($customer['id'], $this->store->findByEmail('customer@example.test')['id']);
    }
}
