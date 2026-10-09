<?php

declare(strict_types=1);

// Concurrent verification in isolated, randomly prefixed MySQL tables.
require dirname(__DIR__, 4) . '/vendor/autoload.php';

use Flex\Accounts\{AccountEmailVerification, AccountRateLimiter, AccountStore};
use Flex\Auth\PasswordHasher;
use Flex\Configuration\ConfigurationRepository;
use Flex\Database\DatabaseManager;
use Flex\Extensions\{PluginEntrypointLoader, PluginManifest};
use Symfony\Component\Mailer\{Envelope, Mailer, MailerInterface, Transport};
use Symfony\Component\Mime\{Email, RawMessage};

(new PluginEntrypointLoader())->load(PluginManifest::fromFile(dirname(__DIR__) . '/plugin.json'), dirname(__DIR__));
if (($argv[1] ?? '') === 'worker') {
    $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $config = new ConfigurationRepository($input['config']);
    $database = new DatabaseManager($config);
    $store = new AccountStore($database->connection());
    $verification = new AccountEmailVerification($store, $config, new Mailer(Transport::fromDsn('null://null')), new AccountRateLimiter($store, $config));
    echo "READY\n";
    fflush(STDOUT);
    try {
        $verification->confirm($input['customer'], $input['token'], $input['ip']);
        echo "verified\n";
    } catch (InvalidArgumentException $e) {
        if ($e->getCode() !== 422) throw $e;
        echo "rejected\n";
    } finally { $database->disconnect(); }
    exit;
}

$root = dirname(__DIR__, 4);
(new Flex\Configuration\EnvironmentLoader())->load($root);
$platform = (new Flex\Configuration\ConfigurationLoader())->load($root, false);
$connection = $platform->array('database.connections.mysql');
$prefix = 'ar' . bin2hex(random_bytes(3)) . '_';
$connection['prefix'] = $prefix;
$config = new ConfigurationRepository([
    'app' => ['url' => 'https://shop.example.test', 'key' => 'isolated-race-key'],
    'mail' => ['from' => ['address' => 'noreply@example.test', 'name' => 'Test']],
    'database' => ['default' => 'test', 'connections' => ['test' => $connection]],
]);
$database = new DatabaseManager($config);
$db = $database->connection();
$store = new AccountStore($db);
$processes = [];
try {
    $store->install();
    $store->saveSettings(['email_verification' => true]);
    $customer = $store->create(['email' => 'race@example.test', 'first_name' => 'Race', 'last_name' => 'Test'], (new PasswordHasher())->hash('password-12345'), 'pending');
    $mailer = new class implements MailerInterface {
        public string $token = '';
        public function send(RawMessage $message, ?Envelope $envelope = null): void
        {
            if (!$message instanceof Email || preg_match('/token=([a-f0-9]{64})/', (string) $message->getTextBody(), $match) !== 1) throw new RuntimeException('Missing token.');
            $this->token = $match[1];
        }
    };
    (new AccountEmailVerification($store, $config, $mailer, new AccountRateLimiter($store, $config)))->request($customer['email'], '192.0.2.10');
    // Hold the shared customer lock while both worker processes start.
    $db->beginTransaction();
    $db->table('flex_accounts_customers')->where('id', $customer['id'])->lockForUpdate()->first();
    for ($i = 0; $i < 2; ++$i) {
        $process = proc_open([PHP_BINARY, __FILE__, 'worker'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Unable to launch verification worker.');
        $processes[] = [$process, $pipes];
        fwrite($pipes[0], json_encode(['config' => $config->all(), 'customer' => $customer['id'], 'token' => $mailer->token, 'ip' => '192.0.2.' . (20 + $i)], JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        if (trim((string) fgets($pipes[1])) !== 'READY') throw new RuntimeException('Verification worker failed to start.');
    }
    $db->commit();
    $results = [];
    foreach ($processes as [$process, $pipes]) {
        $results[] = trim(stream_get_contents($pipes[1]));
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0 || $errors !== '') throw new RuntimeException('Verification worker failed.');
    }
    $processes = [];
    sort($results);
    if ($results !== ['rejected', 'verified'] || $store->find($customer['id'])['auth_version'] !== 2
        || $db->table('flex_accounts_email_verifications')->whereNull('used_at')->count() !== 0) {
        throw new RuntimeException('Concurrent verification did not consume the token exactly once.');
    }
    echo "PASS: two concurrent MySQL confirmations; exactly one activation, one rejection and one authentication-version increment.\n";
} finally {
    if ($db->transactionLevel() > 0) $db->rollBack();
    foreach ($processes as [$process, $pipes]) {
        if (is_resource($process)) proc_terminate($process);
        foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        if (is_resource($process)) proc_close($process);
    }
    foreach ($db->getSchemaBuilder()->getTableListing() as $table) {
        if (str_starts_with($table, $prefix)) $db->getSchemaBuilder()->drop(substr($table, strlen($prefix)));
    }
    $database->disconnect();
}
