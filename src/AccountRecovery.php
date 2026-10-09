<?php

declare(strict_types=1);

namespace Flex\Accounts;

use Flex\Auth\PasswordHasher;
use Flex\Contracts\Configuration\ConfigRepositoryInterface;
use Illuminate\Database\Connection;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\{Address, Email};

final readonly class AccountRecovery
{
    public function __construct(
        private AccountStore $store,
        private ConfigRepositoryInterface $config,
        private PasswordHasher $passwords,
        private MailerInterface $mailer,
    ) {}

    private function digest(string $value): string
    {
        return hash_hmac('sha256', $value, $this->config->string('app.key'));
    }

    private function email(string $email): string
    {
        $email = strtolower(trim($email));
        if (strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Въведете валиден имейл адрес.', 422);
        }

        return $email;
    }

    private function limit(string $scope, string $value, int $maximum, int $cooldown = 0): void
    {
        (new AccountRateLimiter($this->store, $this->config))->check($scope, $value, $maximum, $cooldown);
    }

    public function request(string $email, string $ip): void
    {
        $email = $this->email($email);
        $this->limit('request-ip', $ip, 20);
        $this->limit('request-email', $email, 5, 60);
        $code = (string) random_int(100000, 999999);
        $db = $this->store->connection();
        $request = $db->transaction(function (Connection $conn) use ($email, $code): ?array {
            $user = $conn->table('flex_accounts_customers')->where('email', $email)->whereNull('deleted_at')->where('status', 'active')->lockForUpdate()->first();
            if ($user === null) {
                return null;
            }
            $now = gmdate('Y-m-d H:i:s');
            $conn->table('flex_accounts_password_resets')->where('customer_id', $user->id)->whereNull('used_at')->update(['used_at' => $now]);
            $id = $conn->table('flex_accounts_password_resets')->insertGetId([
                'customer_id' => $user->id,
                'code_hash' => $this->digest($code),
                'expires_at' => gmdate('Y-m-d H:i:s', time() + 900),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return ['id' => $id, 'email' => (string) $user->email];
        }, 3);
        if ($request === null) {
            return;
        }
        try {
            $message = (new Email())
                ->from(new Address($this->config->string('mail.from.address'), $this->config->string('mail.from.name')))
                ->to($request['email'])
                ->subject('Възстановяване на парола')
                ->text("Вашият код за възстановяване на паролата е: $code\n\nВалиден е 15 минути. Ако не сте заявили промяната, игнорирайте този имейл.")
                ->html('<p>Вашият код за възстановяване на паролата е:</p><p style="font-size:28px;letter-spacing:6px">' . $code . '</p><p>Валиден е 15 минути. Ако не сте заявили промяната, игнорирайте този имейл.</p>');
            $this->mailer->send($message);
        } catch (\Throwable $error) {
            $db->table('flex_accounts_password_resets')->where('id', $request['id'])->update(['used_at' => gmdate('Y-m-d H:i:s')]);
            throw $error;
        }
    }

    public function verify(string $email, string $code, string $ip): string
    {
        $email = $this->email($email);
        $this->limit('verify-ip', $ip, 30);
        $grant = bin2hex(random_bytes(32));
        $db = $this->store->connection();
        $valid = $db->transaction(function (Connection $conn) use ($email, $code, $grant): bool {
            $user = $conn->table('flex_accounts_customers')->where('email', $email)->whereNull('deleted_at')->where('status', 'active')->lockForUpdate()->first();
            if ($user === null) {
                return false;
            }
            $row = $conn->table('flex_accounts_password_resets')->where('customer_id', $user->id)->whereNull('used_at')->orderByDesc('id')->lockForUpdate()->first();
            if ($row === null || $row->expires_at <= gmdate('Y-m-d H:i:s') || $row->attempts >= 5 || $row->grant_hash !== null) {
                return false;
            }
            $conn->table('flex_accounts_password_resets')->where('id', $row->id)->increment('attempts');
            if (preg_match('/^\d{6}$/', $code) !== 1 || !hash_equals((string) $row->code_hash, $this->digest($code))) {
                return false;
            }
            $conn->table('flex_accounts_password_resets')->where('id', $row->id)->update([
                'grant_hash' => $this->digest($grant),
                'grant_expires_at' => gmdate('Y-m-d H:i:s', time() + 300),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);

            return true;
        }, 3);
        if (!$valid) {
            throw new \InvalidArgumentException('Кодът е невалиден, изтекъл или вече използван.', 422);
        }

        return $grant;
    }

    public function reset(string $email, string $grant, string $password, string $confirmation, string $ip): void
    {
        $email = $this->email($email);
        $minimum = (int) ($this->store->settings()['password_min_length'] ?? 10);
        if (mb_strlen($password) < $minimum || strlen($password) > 72 || $password !== $confirmation) {
            throw new \InvalidArgumentException("Паролите трябва да съвпадат, да съдържат поне $minimum знака и да не надвишават 72 байта.", 422);
        }
        if (preg_match('/^[a-f0-9]{64}$/', $grant) !== 1) {
            throw new \InvalidArgumentException('Потвърждението е невалидно. Заявете нов код.', 422);
        }
        $this->limit('reset-ip', $ip, 30);
        $hash = $this->passwords->hash($password);
        $db = $this->store->connection();
        $valid = $db->transaction(function (Connection $conn) use ($email, $grant, $hash): bool {
            $user = $conn->table('flex_accounts_customers')->where('email', $email)->whereNull('deleted_at')->where('status', 'active')->lockForUpdate()->first();
            if ($user === null) {
                return false;
            }
            $now = gmdate('Y-m-d H:i:s');
            $row = $conn->table('flex_accounts_password_resets')
                ->where('customer_id', $user->id)
                ->whereNull('used_at')
                ->where('grant_hash', $this->digest($grant))
                ->where('grant_expires_at', '>', $now)
                ->lockForUpdate()
                ->first();
            if ($row === null) {
                return false;
            }
            $conn->table('flex_accounts_customers')->where('id', $user->id)->update([
                'password_hash' => $hash,
                'auth_version' => (int) $user->auth_version + 1,
                'updated_at' => $now,
            ]);
            $conn->table('flex_accounts_password_resets')->where('customer_id', $user->id)->whereNull('used_at')->update(['used_at' => $now]);

            return true;
        }, 3);
        if (!$valid) {
            throw new \InvalidArgumentException('Потвърждението е невалидно или изтекло. Заявете нов код.', 422);
        }
    }
}
