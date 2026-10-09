<?php

declare(strict_types=1);

namespace Flex\Accounts;

use Flex\Extensions\{Plugin, PluginManager};

final class CommerceDetector
{
    public function active(): bool
    {
        try {
            $plugin = Plugin::query()->find('flex/commerce');
            return $plugin instanceof Plugin
                && $plugin->getAttribute('status') === PluginManager::STATUS_ACTIVE;
        } catch (\Throwable) {
            return false;
        }
    }
}
