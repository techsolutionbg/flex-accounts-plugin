<?php

declare(strict_types=1);

namespace Flex\Accounts;

use Flex\Contracts\Http\ResponseFactoryInterface;
use Flex\Http\RequestInput;
use Flex\Session\CsrfTokenManager;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};

final readonly class PublicController
{
    public function __construct(
        private ResponseFactoryInterface $responses,
        private RequestInput $input,
        private CsrfTokenManager $csrf,
        private AccountAuthService $auth,
        private AccountStore $store,
        private AccountRecovery $recovery,
        private AccountPageRenderer $pages,
        private CommerceDetector $commerce,
        private AccountEmailVerification $verification,
    ) {}

    public function __invoke(ServerRequestInterface $request, array $arguments = []): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        try {
            if (str_ends_with($path, '/account/verify-email')) {
                return $this->verificationPage($request);
            }
            if (str_ends_with($path, '/account/resend-verification')) {
                return $this->resendVerificationPage($request);
            }
            if (str_ends_with($path, '/session') || str_contains($path, '/plugins/flex/accounts/session')) {
                return $this->sessionJson();
            }
            if (str_contains($path, '/plugins/flex/accounts/login') && $request->getMethod() === 'POST') {
                return $this->apiLogin($request);
            }
            if (str_contains($path, '/plugins/flex/accounts/register') && $request->getMethod() === 'POST') {
                return $this->apiRegister($request);
            }
            if (str_contains($path, '/plugins/flex/accounts/logout') && $request->getMethod() === 'POST') {
                $this->auth->logout();
                return $this->responses->json(['ok' => true], 200, ['Cache-Control' => 'no-store']);
            }
            if (preg_match('#/account/orders/(\d+)$#', $path, $m)) {
                return $this->orderPage((int) $m[1]);
            }
            if (str_ends_with($path, '/account/orders')) {
                return $this->ordersPage();
            }
            if (str_ends_with($path, '/account/logout')) {
                $this->auth->logout();
                return $this->redirect('/account/login');
            }
            if (str_contains($path, '/account/register')) {
                return $this->registerPage($request);
            }
            if (str_contains($path, '/account/login')) {
                return $this->loginPage($request);
            }
            if (str_contains($path, '/account/password')) {
                return $this->passwordPage($request);
            }
            if (str_contains($path, '/account/forgot')) {
                return $this->forgotPage($request);
            }
            if (str_contains($path, '/account/reset')) {
                return $this->resetPage($request);
            }

            return $this->accountPage($request);
        } catch (\InvalidArgumentException $e) {
            $wantsJson = str_contains($path, '/plugins/flex/accounts/')
                || str_contains($request->getHeaderLine('Accept'), 'application/json');
            if ($wantsJson) {
                return $this->responses->json(['error' => ['message' => $e->getMessage()]], $e->getCode() ?: 422, ['Cache-Control' => 'no-store']);
            }
            throw $e;
        }
    }

    private function sessionJson(): ResponseInterface
    {
        $user = $this->auth->user();

        return $this->responses->json([
            'customer' => $user,
            'commerce_active' => $this->commerce->active(),
            'csrf_token' => $this->csrf->token(),
            'settings' => [
                'registration_enabled' => (bool) ($this->store->settings()['registration_enabled'] ?? true),
            ],
        ], 200, ['Cache-Control' => 'no-store']);
    }

    private function apiLogin(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->input->all($request);
        $email = is_string($body['email'] ?? null) ? $body['email'] : '';
        $password = is_string($body['password'] ?? null) ? $body['password'] : '';
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
        if (!$this->auth->attempt($email, $password, $ip)) {
            return $this->responses->json(['error' => ['message' => 'Невалиден имейл или парола.']], 401, ['Cache-Control' => 'no-store']);
        }
        $next = is_string($body['next'] ?? null) ? $body['next'] : null;

        return $this->responses->json([
            'customer' => $this->auth->user(),
            'redirect' => $this->auth->redirectAfterLogin($next),
        ], 200, ['Cache-Control' => 'no-store']);
    }

    private function apiRegister(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->input->all($request);
        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
        $customer = $this->auth->register($body, $ip);
        $pending = $customer['status'] === 'pending';

        return $this->responses->json([
            'customer' => $pending ? null : $this->auth->user(),
            'pending' => $pending,
            'message' => $pending
                ? 'Регистрацията е успешна. Изпратихме линк за потвърждаване, валиден 24 часа. Проверете и папката за спам.'
                : 'Регистрацията е успешна.',
            'redirect' => $pending ? '/account/login' : $this->auth->redirectAfterLogin(is_string($body['next'] ?? null) ? $body['next'] : null),
        ], 201, ['Cache-Control' => 'no-store']);
    }

    private function loginPage(ServerRequestInterface $request): ResponseInterface
    {
        return $this->authPage($request, 'login');
    }

    private function registerPage(ServerRequestInterface $request): ResponseInterface
    {
        return $this->authPage($request, 'register');
    }

    private function authPage(ServerRequestInterface $request, string $focus): ResponseInterface
    {
        if ($this->auth->check()) {
            return $this->redirect($focus === 'register' ? '/account' : $this->auth->redirectAfterLogin($this->nextParam($request)));
        }

        $next = $this->nextParam($request);
        $settings = $this->store->settings();
        $registrationEnabled = (bool) ($settings['registration_enabled'] ?? true);
        $loginError = '';
        $registerError = '';
        $registerMessage = '';
        $loginEmail = '';
        $values = ['first_name' => '', 'last_name' => '', 'email' => '', 'phone' => ''];

        if ($request->getMethod() === 'POST') {
            $body = $this->input->all($request);
            $form = is_string($body['form'] ?? null) ? $body['form'] : $focus;
            $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');

            if ($form === 'register') {
                foreach ($values as $key => $_) {
                    $values[$key] = is_string($body[$key] ?? null) ? $body[$key] : '';
                }
                if (!$registrationEnabled) {
                    $registerError = 'Регистрацията е временно спряна.';
                } else {
                    try {
                        $customer = $this->auth->register($body, $ip);
                        if ($customer['status'] === 'pending') {
                            $registerMessage = 'Регистрацията е успешна. Изпратихме линк за потвърждаване, валиден 24 часа. Проверете и папката за спам.';
                            $values = ['first_name' => '', 'last_name' => '', 'email' => '', 'phone' => ''];
                        } else {
                            return $this->redirect($this->auth->redirectAfterLogin(is_string($body['next'] ?? null) ? $body['next'] : $next));
                        }
                    } catch (\InvalidArgumentException $e) {
                        $registerError = $e->getMessage();
                    }
                }
            } else {
                $loginEmail = is_string($body['email'] ?? null) ? $body['email'] : '';
                $password = is_string($body['password'] ?? null) ? $body['password'] : '';
                try {
                    if (!$this->auth->attempt($loginEmail, $password, $ip)) {
                        $loginError = 'Невалиден имейл или парола.';
                    } else {
                        return $this->redirect($this->auth->redirectAfterLogin(is_string($body['next'] ?? null) ? $body['next'] : $next));
                    }
                } catch (\InvalidArgumentException $e) {
                    $loginError = $e->getMessage();
                }
            }
        }

        $terms = '';
        $privacy = '';
        if ($registrationEnabled && $this->commerce->active() && class_exists(\Flex\Commerce\CatalogStore::class)) {
            $shop = (new \Flex\Commerce\CatalogStore())->settings();
            $terms = (string) ($shop['terms_url'] ?? '');
            $privacy = (string) ($shop['privacy_url'] ?? '');
        }

        return $this->pages->render('Моят акаунт', 'auth.twig', [
            'login_error' => $loginError,
            'register_error' => $registerError,
            'register_message' => $registerMessage,
            'login_email' => $loginEmail,
            'csrf_token' => $this->csrf->token(),
            'next' => $next ?? '',
            'registration_enabled' => $registrationEnabled,
            'values' => $values,
            'terms_url' => $terms,
            'privacy_url' => $privacy,
        ]);
    }

    private function accountPage(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->auth->user();
        if ($user === null) {
            return $this->redirect('/account/login?next=' . rawurlencode('/account'));
        }
        $error = '';
        $message = '';
        $econtEnabled = $this->commerce->active() && class_exists(\Flex\Econt\ShippingService::class);
        if ($request->getMethod() === 'POST') {
            $body = $this->input->all($request);
            $form = is_string($body['form'] ?? null) ? $body['form'] : 'profile';
            try {
                if ($form === 'shipping_office') {
                    if (!$econtEnabled) {
                        throw new \InvalidArgumentException('Изборът на офис Еконт не е наличен.', 422);
                    }
                    $action = is_string($body['shipping_action'] ?? null) ? $body['shipping_action'] : 'save';
                    if ($action === 'clear') {
                        $user = $this->store->updateShippingPreferences((int) $user['id'], null);
                        $message = 'Запазеният офис е премахнат.';
                    } else {
                        $user = $this->store->updateShippingPreferences((int) $user['id'], [
                            'shipping' => 'econt_office',
                            'office_code' => $body['office_code'] ?? '',
                            'office_name' => $body['office_name'] ?? '',
                            'office_address' => $body['office_address'] ?? '',
                            'city' => $body['office_city'] ?? ($body['city'] ?? ''),
                            'postcode' => $body['office_postcode'] ?? ($body['postcode'] ?? ''),
                        ]);
                        $message = 'Офисът за доставка е запазен.';
                    }
                } else {
                    $user = $this->auth->updateProfile($body);
                    $message = 'Профилът е обновен.';
                }
            } catch (\InvalidArgumentException $e) {
                $error = $e->getMessage();
                $fresh = $this->auth->user();
                if ($fresh !== null) {
                    $user = $fresh;
                }
            }
        }

        $preferences = is_array($user['shipping_preferences'] ?? null) ? $user['shipping_preferences'] : [];
        $hasOffice = ($preferences['office_code'] ?? '') !== '';

        return $this->pages->render('Моят профил', 'account.twig', [
            'customer' => $user,
            'error' => $error,
            'message' => $message,
            'csrf_token' => $this->csrf->token(),
            'commerce_active' => $this->commerce->active(),
            'show_orders' => $this->showOrdersNav(),
            'econt_enabled' => $econtEnabled,
            'shipping_preferences' => $preferences,
            'has_shipping_office' => $hasOffice,
            'shipping_meta_json' => $hasOffice
                ? json_encode([
                    'office_code' => (string) ($preferences['office_code'] ?? ''),
                    'office_name' => (string) ($preferences['office_name'] ?? ''),
                    'office_address' => (string) ($preferences['office_address'] ?? ''),
                    'city' => (string) ($preferences['city'] ?? ''),
                    'postcode' => (string) ($preferences['postcode'] ?? ''),
                ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
                : '{}',
        ]);
    }

    private function passwordPage(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->auth->user() === null) {
            return $this->redirect('/account/login?next=' . rawurlencode('/account/password'));
        }
        $error = '';
        $message = '';
        if ($request->getMethod() === 'POST') {
            try {
                $body = $this->input->all($request);
                $this->auth->changePassword(
                    is_string($body['current_password'] ?? null) ? $body['current_password'] : '',
                    is_string($body['password'] ?? null) ? $body['password'] : '',
                    is_string($body['password_confirmation'] ?? null) ? $body['password_confirmation'] : '',
                );
                $message = 'Паролата е сменена.';
            } catch (\InvalidArgumentException $e) {
                $error = $e->getMessage();
            }
        }

        return $this->pages->render('Смяна на парола', 'password.twig', [
            'error' => $error,
            'message' => $message,
            'csrf_token' => $this->csrf->token(),
            'show_orders' => $this->showOrdersNav(),
        ]);
    }

    private function forgotPage(ServerRequestInterface $request): ResponseInterface
    {
        $error = '';
        $message = '';
        if ($request->getMethod() === 'POST') {
            try {
                $body = $this->input->all($request);
                $email = is_string($body['email'] ?? null) ? $body['email'] : '';
                $this->recovery->request($email, (string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''));
                $message = 'Ако има акаунт с този имейл, изпратихме код за възстановяване.';
            } catch (\InvalidArgumentException $e) {
                $error = $e->getMessage();
            }
        }

        return $this->pages->render('Забравена парола', 'forgot.twig', [
            'error' => $error,
            'message' => $message,
            'csrf_token' => $this->csrf->token(),
        ]);
    }

    private function resetPage(ServerRequestInterface $request): ResponseInterface
    {
        $error = '';
        $message = '';
        $grant = '';
        $email = is_string($request->getQueryParams()['email'] ?? null) ? (string) $request->getQueryParams()['email'] : '';
        $step = 'code';
        if ($request->getMethod() === 'POST') {
            try {
                $body = $this->input->all($request);
                $email = is_string($body['email'] ?? null) ? $body['email'] : $email;
                $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
                if (isset($body['password'])) {
                    $this->recovery->reset(
                        $email,
                        is_string($body['grant'] ?? null) ? $body['grant'] : '',
                        is_string($body['password'] ?? null) ? $body['password'] : '',
                        is_string($body['password_confirmation'] ?? null) ? $body['password_confirmation'] : '',
                        $ip,
                    );

                    return $this->redirect('/account/login');
                }
                $grant = $this->recovery->verify($email, is_string($body['code'] ?? null) ? $body['code'] : '', $ip);
                $step = 'password';
                $message = 'Въведете новата парола.';
            } catch (\InvalidArgumentException $e) {
                $error = $e->getMessage();
                $step = isset($body['password']) ? 'password' : 'code';
                $grant = is_string($body['grant'] ?? null) ? $body['grant'] : $grant;
            }
        }

        return $this->pages->render('Нова парола', 'reset.twig', [
            'error' => $error,
            'message' => $message,
            'csrf_token' => $this->csrf->token(),
            'email' => $email,
            'grant' => $grant,
            'step' => $step,
        ]);
    }

    private function verificationPage(ServerRequestInterface $request): ResponseInterface
    {
        $data = $request->getMethod() === 'POST' ? $this->input->all($request) : $request->getQueryParams();
        $id = $data['customer'] ?? '';
        $customerId = (is_int($id) || (is_string($id) && ctype_digit($id))) ? (int) $id : 0;
        $token = is_string($data['token'] ?? null) ? $data['token'] : '';
        $error = '';
        $message = '';
        $status = 200;
        if ($request->getMethod() === 'POST') {
            try {
                $this->verification->confirm($customerId, $token, (string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''));
                $message = 'Имейлът е потвърден. Вече можете да влезете с паролата си.';
            } catch (\InvalidArgumentException $e) {
                $error = $e->getMessage();
                $status = $e->getCode() ?: 422;
            }
        } elseif ($customerId < 1 || preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            $error = 'Линкът е невалиден. Заявете нов линк за потвърждаване.';
            $status = 422;
        }
        return $this->pages->render('Потвърждаване на имейл', 'verify-email.twig', [
            'customer_id' => $customerId, 'token' => $token, 'csrf_token' => $this->csrf->token(),
            'error' => $error, 'message' => $message,
        ], $status)->withHeader('Referrer-Policy', 'no-referrer')->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    private function resendVerificationPage(ServerRequestInterface $request): ResponseInterface
    {
        $error = '';
        $message = '';
        $status = 200;
        if ($request->getMethod() === 'POST') {
            try {
                $data = $this->input->all($request);
                $this->verification->request(is_string($data['email'] ?? null) ? $data['email'] : '', (string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''));
                $message = 'Ако акаунтът очаква потвърждение, изпратихме нов линк. Проверете и папката за спам.';
            } catch (\InvalidArgumentException $e) {
                $error = $e->getMessage();
                $status = $e->getCode() ?: 422;
            }
        }
        return $this->pages->render('Нов линк за потвърждаване', 'resend-verification.twig', [
            'error' => $error, 'message' => $message, 'csrf_token' => $this->csrf->token(),
        ], $status);
    }

    private function ordersPage(): ResponseInterface
    {
        $user = $this->auth->user();
        if ($user === null) {
            return $this->redirect('/account/login?next=' . rawurlencode('/account/orders'));
        }
        if (!$this->showOrdersNav()) {
            return $this->redirect('/account');
        }
        $bridge = new CommerceBridge($this->store, $this->auth, $this->commerce);

        return $this->pages->render('Моите поръчки', 'orders.twig', [
            'customer' => $user,
            'orders' => $bridge->ordersForCurrent(),
            'csrf_token' => $this->csrf->token(),
            'show_orders' => true,
        ]);
    }

    private function orderPage(int $id): ResponseInterface
    {
        $user = $this->auth->user();
        if ($user === null) {
            return $this->redirect('/account/login?next=' . rawurlencode('/account/orders/' . $id));
        }
        if (!$this->showOrdersNav()) {
            return $this->redirect('/account');
        }
        $bridge = new CommerceBridge($this->store, $this->auth, $this->commerce);
        $order = $bridge->orderForCurrent($id);
        if ($order === null) {
            return $this->pages->render('Поръчка', 'order.twig', [
                'customer' => $user,
                'order' => null,
                'error' => 'Поръчката не е намерена.',
                'csrf_token' => $this->csrf->token(),
                'show_orders' => true,
            ], 404);
        }

        return $this->pages->render('Поръчка ' . $order['number'], 'order.twig', [
            'customer' => $user,
            'order' => $order,
            'error' => '',
            'csrf_token' => $this->csrf->token(),
            'show_orders' => true,
        ]);
    }

    private function showOrdersNav(): bool
    {
        return $this->commerce->active() && (bool) ($this->store->settings()['show_orders_in_account'] ?? true);
    }

    private function nextParam(ServerRequestInterface $request): ?string
    {
        $next = $request->getQueryParams()['next'] ?? null;
        if (!is_string($next) || !$this->store->safeRedirectPath($next)) {
            return null;
        }

        return $next;
    }

    private function redirect(string $to): ResponseInterface
    {
        return $this->responses->text('', 302, ['Location' => $to]);
    }
}
