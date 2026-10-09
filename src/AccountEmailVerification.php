<?php

declare(strict_types=1);

namespace Flex\Accounts;

use Flex\Contracts\Configuration\ConfigRepositoryInterface;
use Illuminate\Database\Connection;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\{Address, Email};

final readonly class AccountEmailVerification
{
    public function __construct(
        private AccountStore $store,
        private ConfigRepositoryInterface $config,
        private MailerInterface $mailer,
        private AccountRateLimiter $limits,
    ) {}

    public function request(string $email, string $ip): void
    {
        $email = mb_strtolower(trim($email));
        if (strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Въведете валиден имейл адрес.', 422);
        }
        $this->limits->check('verification-send-ip', $ip, 20);
        $this->limits->check('verification-send-email', $email, 5, 60);
        $token = bin2hex(random_bytes(32));
        $request = $this->store->connection()->transaction(function (Connection $db) use ($email, $token): ?array {
            $customer = $db->table('flex_accounts_customers')->where('email', $email)->whereNull('deleted_at')->lockForUpdate()->first();
            if ($customer === null || !in_array($customer->status, ['pending', 'active'], true) || $customer->email_verified_at !== null) {
                return null;
            }
            $now = gmdate('Y-m-d H:i:s');
            $db->table('flex_accounts_email_verifications')->where('customer_id', $customer->id)->whereNull('used_at')->update(['used_at' => $now]);
            $id = $db->table('flex_accounts_email_verifications')->insertGetId([
                'customer_id' => $customer->id, 'token_hash' => $this->digest($token),
                'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400),
                'created_at' => $now, 'updated_at' => $now,
            ]);
            return ['id' => $id, 'customer_id' => (int) $customer->id, 'email' => (string) $customer->email];
        }, 3);
        if ($request === null) return;
        try {
            // Use the configured site URL, never an untrusted request Host header.
            $base = rtrim($this->config->string('app.url'), '/');
            $parts = parse_url($base);
            if ($parts === false || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
                || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
                || isset($parts['query']) || isset($parts['fragment']) || preg_match('/[\x00-\x20\\\\]/', $base)) {
                throw new \RuntimeException('Invalid configured site URL.');
            }
            $url = $base . '/account/verify-email?customer=' . $request['customer_id'] . '&token=' . $token;
            $message = (new Email())
                ->from(new Address($this->config->string('mail.from.address'), $this->config->string('mail.from.name')))
                ->to($request['email'])->subject('Потвърдете имейла си')
                ->text("Отворете линка и потвърдете имейла си:\n$url\n\nЛинкът е валиден 24 часа. Ако не сте създали акаунта, игнорирайте това писмо.")
                ->html('<p>Потвърдете имейла си, за да активирате акаунта:</p><p><a href="'
                    . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    . '">Потвърждаване на имейл</a></p><p>Линкът е валиден 24 часа. Ако не сте създали акаунта, игнорирайте това писмо.</p>');
            $this->mailer->send($message);
        } catch (\Throwable $error) {
            $this->store->connection()->table('flex_accounts_email_verifications')->where('id', $request['id'])->update(['used_at' => gmdate('Y-m-d H:i:s')]);
            throw new \InvalidArgumentException('Не успяхме да изпратим писмото. Акаунтът е запазен; заявете нов линк от страницата за потвърждаване на имейл.', 503, $error);
        }
    }

    public function confirm(int $customerId, string $token, string $ip): void
    {
        $this->limits->check('verification-confirm-ip', $ip, 30);
        if ($customerId < 1 || preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            throw new \InvalidArgumentException('Линкът е невалиден, изтекъл или вече използван. Заявете нов линк.', 422);
        }
        $valid = $this->store->connection()->transaction(function (Connection $db) use ($customerId, $token): bool {
            $customer = $db->table('flex_accounts_customers')->where('id', $customerId)->whereNull('deleted_at')->lockForUpdate()->first();
            if ($customer === null || !in_array($customer->status, ['pending', 'active'], true) || $customer->email_verified_at !== null) return false;
            $now = gmdate('Y-m-d H:i:s');
            $grant = $db->table('flex_accounts_email_verifications')->where('customer_id', $customerId)
                ->where('token_hash', $this->digest($token))->whereNull('used_at')->where('expires_at', '>', $now)->lockForUpdate()->first();
            if ($grant === null) return false;
            $db->table('flex_accounts_customers')->where('id', $customerId)->update([
                'email_verified_at' => $now, 'email_verification_required' => false, 'status' => 'active',
                'auth_version' => (int) $customer->auth_version + 1, 'updated_at' => $now,
            ]);
            $db->table('flex_accounts_email_verifications')->where('customer_id', $customerId)->whereNull('used_at')->update(['used_at' => $now]);
            return true;
        }, 3);
        if (!$valid) throw new \InvalidArgumentException('Линкът е невалиден, изтекъл или вече използван. Заявете нов линк.', 422);
    }

    private function digest(string $token): string
    {
        return hash_hmac('sha256', $token, $this->config->string('app.key'));
    }
}
