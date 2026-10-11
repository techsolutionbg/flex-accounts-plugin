<?php

declare(strict_types=1);

namespace Flex\Accounts;

use Flex\Configuration\ProjectPaths;
use Flex\Contracts\Http\ResponseFactoryInterface;
use Flex\Media\PublicMediaApi;
use Flex\Menus\{PublicMenuApi, PublicMenuRenderer};
use Flex\Pages\{Page, PageRepository};
use Flex\Themes\ThemeManager;
use Psr\Http\Message\ResponseInterface;
use Twig\{Environment, Loader\FilesystemLoader, TwigFunction};

final readonly class AccountPageRenderer
{
    public function __construct(
        private ResponseFactoryInterface $responses,
        private ThemeManager $themes,
        private PageRepository $pages,
        private PublicMenuRenderer $menus,
        private PublicMediaApi $media,
        private ProjectPaths $paths,
    ) {}

    /** @param array<string, mixed> $data */
    public function render(string $title, string $template, array $data = [], int $status = 200): ResponseInterface
    {
        $html = $this->template($template, $data);
        // The forms' files load only on account pages, with the theme's plugin tags.
        AccountAssets::account();
        $content = '<div class="flex-accounts">' . $html . '</div>';
        $pages = $this->pages->publicPageSet();
        $capabilities = $this->themes->capabilities();
        $menus = $this->menus->forTheme($capabilities['theme'] ?? '', $pages, $capabilities['menu_locations']);
        $menus['__flex_menu_api'] = new PublicMenuApi($this->menus, $pages);
        $menus['__flex_media_api'] = $this->media;
        $page = new Page([
            'title' => $title,
            'slug' => 'account',
            'content' => $content,
            'status' => 'published',
            'settings' => [],
        ]);

        return $this->responses->html(
            $this->themes->render('page.twig', [
                'page' => $page,
                'page_settings' => [],
                'page_public_path' => 'plugins/flex/accounts',
                'head_tags' => '',
                'navigation' => [],
                'featured_media' => [],
            ] + $menus),
            $status,
            ['Cache-Control' => 'no-store'],
        );
    }

    /** @param array<string, mixed> $data */
    private function template(string $template, array $data): string
    {
        $loader = new FilesystemLoader(__DIR__ . '/../templates');

        $twig = new Environment($loader, [
            // Compiled templates are kept between requests and recompiled when a template changes.
            'cache' => $this->templateCache(),
            'auto_reload' => true,
            'strict_variables' => true,
            'autoescape' => 'html',
        ]);
        $twig->addFunction(new TwigFunction('flex_time', [self::class, 'time'], ['is_safe' => ['html']]));

        return $twig->render($template, $data);
    }

    /** storage/cache/accounts-twig when it can be written, otherwise no cache. */
    private function templateCache(): string|false
    {
        $directory = $this->paths->storage('cache/accounts-twig');
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        return is_dir($directory) && is_writable($directory) ? $directory : false;
    }

    /** Stored times are UTC; /assets/flex-time.js shows them in the visitor's zone (platform 0.1.59+). */
    public static function time(mixed $value, string $style = 'datetime'): string
    {
        if (class_exists(\Flex\Extension\V1\Time::class)) {
            return \Flex\Extension\V1\Time::html($value, $style);
        }

        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
