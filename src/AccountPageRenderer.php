<?php

declare(strict_types=1);

namespace Flex\Accounts;

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
    ) {}

    /** @param array<string, mixed> $data */
    public function render(string $title, string $template, array $data = [], int $status = 200): ResponseInterface
    {
        $html = $this->template($template, $data);
        $asset = '/extensions/flex/accounts/assets/assets/';
        // accounts.js is loaded once via plugin frontend assets; avoid a second module instance.
        // CSS/JS also load via theme frontend_extension_tags; keep a content link as fallback for themes without form_controls.
        $content = '<link rel="stylesheet" href="' . $asset . 'accounts.css?v=3">'
            . '<div class="flex-accounts">' . $html . '</div>';
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
            'cache' => false,
            'strict_variables' => true,
            'autoescape' => 'html',
        ]);
        $twig->addFunction(new TwigFunction('flex_time', [self::class, 'time'], ['is_safe' => ['html']]));

        return $twig->render($template, $data);
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
