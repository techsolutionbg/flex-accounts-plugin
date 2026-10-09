<?php

declare(strict_types=1);

namespace Flex\Accounts;

use Flex\Contracts\Configuration\ConfigRepositoryInterface;
use Illuminate\Database\Connection;

/** Persistent limits shared by recovery and verification, with separate scopes. */
final readonly class AccountRateLimiter
{
    public function __construct(private AccountStore $store, private ConfigRepositoryInterface $config) {}

    public function check(string $scope, string $value, int $maximum, int $cooldown = 0): void
    {
        $key = hash_hmac('sha256', $scope . ':' . $value, $this->config->string('app.key'));
        $retryAfter = $this->store->connection()->transaction(function (Connection $db) use ($key, $maximum, $cooldown): int {
            $now = time();
            $db->table('flex_accounts_reset_limits')->insertOrIgnore([
                'key' => $key, 'attempts' => 0, 'window_started_at' => $now, 'last_attempt_at' => 0,
            ]);
            $row = $db->table('flex_accounts_reset_limits')->where('key', $key)->lockForUpdate()->first();
            if ($row === null) return 900;
            $count = $row->window_started_at <= $now - 900 ? 0 : (int) $row->attempts;
            if ($count >= $maximum) return max(1, (int) $row->window_started_at + 900 - $now);
            if ((int) $row->last_attempt_at > $now - $cooldown) return max(1, (int) $row->last_attempt_at + $cooldown - $now);
            $db->table('flex_accounts_reset_limits')->where('key', $key)->update([
                'attempts' => $count + 1,
                'window_started_at' => $count === 0 ? $now : $row->window_started_at,
                'last_attempt_at' => $now,
            ]);
            return 0;
        }, 3);
        if ($retryAfter > 0) {
            throw new \InvalidArgumentException('Твърде много опити. Опитайте отново след ' . $retryAfter . ' секунди.', 429);
        }
    }
}
