<?php

declare(strict_types=1);

namespace Flex\Accounts;

/** Lightweight session peek for themes and extension hooks outside the DI graph. */
final class AccountSessionReader
{
    /** @return array{id: int, email: string, first_name: string, last_name: string, phone: ?string, status: string, auth_version: int, email_verified_at: ?string, email_verification_required: bool, last_login_at: ?string, created_at: ?string}|null */
    public static function user(?AccountStore $store = null): ?array
    {
        self::ensureSession();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return null;
        }
        if (AccountAuthService::sessionExpired($_SESSION[AccountAuthService::SESSION_ACTIVITY] ?? null)) {
            unset($_SESSION[AccountAuthService::SESSION_USER], $_SESSION[AccountAuthService::SESSION_VERSION], $_SESSION[AccountAuthService::SESSION_ACTIVITY]);
            return null;
        }
        $userId = $_SESSION[AccountAuthService::SESSION_USER] ?? null;
        if (!is_int($userId) && !(is_string($userId) && ctype_digit($userId))) {
            return null;
        }
        $version = $_SESSION[AccountAuthService::SESSION_VERSION] ?? 0;
        try {
            $store ??= new AccountStore();
            $customer = $store->find((int) $userId);
        } catch (\Throwable) {
            return null;
        }
        if ($customer === null
            || (int) $version !== (int) $customer['auth_version']
            || $customer['status'] !== 'active'
            || (($customer['email_verification_required'] ?? false) && $customer['email_verified_at'] === null)) {
            return null;
        }

        return $customer;
    }

    public static function loggedIn(): bool
    {
        return self::user() !== null;
    }

    private static function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE || session_status() === PHP_SESSION_DISABLED) {
            return;
        }
        if (headers_sent()) {
            return;
        }
        @session_start([
            'read_and_close' => false,
            'use_strict_mode' => 1,
            'use_only_cookies' => 1,
        ]);
    }
}
