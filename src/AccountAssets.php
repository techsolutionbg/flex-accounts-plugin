<?php

declare(strict_types=1);

namespace Flex\Accounts;

use Flex\Extension\V1\PublicAssetRegistrarInterface;

/**
 * The public file groups of the accounts (frontend.public.groups in plugin.json): forms on /account pages and
 * the account part of the Flex Commerce checkout. The header link is rendered on the server and needs no files.
 */
final class AccountAssets
{
    private static ?PublicAssetRegistrarInterface $registrar = null;

    public static function use(?PublicAssetRegistrarInterface $registrar): void
    {
        self::$registrar = $registrar;
    }

    /** Whether groups can be asked for (Flex CMS 0.1.62+). */
    public static function available(): bool
    {
        return self::$registrar !== null;
    }

    public static function account(): void
    {
        self::$registrar?->enqueue('account');
    }

    public static function checkout(): void
    {
        self::$registrar?->enqueue('account');
        self::$registrar?->enqueue('checkout');
    }
}
