<?php

declare(strict_types=1);

// Local Docker-only SMTP check. Uses isolated SQLite, a unique recipient and Mailpit.
require dirname(__DIR__, 4) . '/vendor/autoload.php';

use DI\ContainerBuilder;
use Flex\Accounts\{AccountEmailVerification, AccountRecovery, AccountStore};
use Flex\Auth\PasswordHasher;
use Flex\Configuration\ConfigurationRepository;
use Flex\Contracts\Configuration\ConfigRepositoryInterface;
use Flex\Database\DatabaseManager;
use Flex\Extensions\{PluginEntrypointLoader, PluginManifest};
use Flex\Providers\MailServiceProvider;

(new PluginEntrypointLoader())->load(PluginManifest::fromFile(dirname(__DIR__) . '/plugin.json'), dirname(__DIR__));
$config = new ConfigurationRepository([
    'app' => ['url' => 'http://localhost:8090', 'key' => 'isolated-smtp-test-key'],
    'mail' => ['dsn' => 'smtp://mailpit:1025', 'from' => ['address' => 'noreply@example.test', 'name' => 'Accounts test']],
    'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]],
]);
$database = new DatabaseManager($config);
$database->schema()->create('settings', static function ($t): void {
    $t->string('key')->primary(); $t->text('value'); $t->string('group');
});
$store = new AccountStore($database->connection());
$store->install();
$store->saveSettings(['email_verification' => true]);
$builder = new ContainerBuilder();
$builder->addDefinitions((new MailServiceProvider())->definitions());
$builder->addDefinitions([ConfigRepositoryInterface::class => $config, DatabaseManager::class => $database, AccountStore::class => $store]);
$container = $builder->build();
$recipient = 'accounts-smtp-' . bin2hex(random_bytes(8)) . '@example.test';
$customer = $store->create(['email' => $recipient, 'first_name' => 'SMTP', 'last_name' => 'Test'], (new PasswordHasher())->hash('password-12345'), 'pending');
$ids = [];
$check = static function (bool $valid, string $message): void { if (!$valid) throw new RuntimeException($message); };
$api = static function (string $path): array {
    $body = file_get_contents('http://mailpit:8025/api/v1/' . $path);
    if ($body === false) throw new RuntimeException('Mailpit API unavailable.');
    return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
};
try {
    $verification = $container->get(AccountEmailVerification::class);
    $verification->request($recipient, '192.0.2.10');
    $mail = $api('search?query=' . rawurlencode('to:' . $recipient));
    $check(count($mail['messages']) === 1, 'Verification email was not captured via SMTP.');
    $ids[] = $mail['messages'][0]['ID'];
    $message = $api('message/' . rawurlencode($ids[0]));
    $check(($message['To'][0]['Address'] ?? '') === $recipient, 'Wrong recipient.');
    $check(($message['From']['Address'] ?? '') === 'noreply@example.test', 'Platform mail sender was not applied.');
    $check(preg_match('#http://localhost:8090/account/verify-email\?customer=(\d+)&token=([a-f0-9]{64})#', $message['Text'], $match) === 1, 'Missing verification URL.');
    $check(str_contains($message['HTML'], '&amp;token='), 'Missing escaped HTML verification link.');
    $verification->confirm((int) $match[1], $match[2], '192.0.2.10');
    $check($store->find($customer['id'])['status'] === 'active', 'SMTP verification did not activate customer.');
    $recovery = $container->get(AccountRecovery::class);
    $recovery->request($recipient, '192.0.2.10');
    $mail = $api('search?query=' . rawurlencode('to:' . $recipient));
    $ids = array_column($mail['messages'], 'ID');
    $check(count($ids) === 2, 'Recovery email was not captured via SMTP.');
    $message = $api('message/' . rawurlencode($ids[0]));
    $check(preg_match('/\b\d{6}\b/', $message['Text'], $code) === 1, 'Missing recovery code.');
    $grant = $recovery->verify($recipient, $code[0], '192.0.2.10');
    $recovery->reset($recipient, $grant, 'recovered-password', 'recovered-password', '192.0.2.10');
    $check(password_verify('recovered-password', $store->findByEmail($recipient)['password_hash']), 'SMTP recovery did not change the password.');
    echo "PASS: platform SettingsMailer, actual SMTP capture, text/HTML verification link, confirmation and password recovery.\n";
} finally {
    // Delete only messages belonging to this test's randomly generated recipient.
    $mail = $api('search?query=' . rawurlencode('to:' . $recipient));
    $ids = array_values(array_unique(array_column($mail['messages'], 'ID')));
    if ($ids !== []) {
        $context = stream_context_create(['http' => ['method' => 'DELETE', 'header' => "Content-Type: application/json\r\n", 'content' => json_encode(['IDs' => $ids], JSON_THROW_ON_ERROR)]]);
        if (file_get_contents('http://mailpit:8025/api/v1/messages', false, $context) === false) throw new RuntimeException('Unable to clean up test email.');
    }
    $database->disconnect();
}
