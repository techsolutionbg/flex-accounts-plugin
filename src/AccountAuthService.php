<?php

declare(strict_types=1);

namespace Flex\Accounts;

use Flex\Auth\PasswordHasher;
use Flex\Contracts\Session\SessionInterface;
use Flex\Extension\V1\ExtensionApiInterface;

final class AccountAuthService
{
    public const SESSION_USER = 'flex_accounts_user_id';
    public const SESSION_VERSION = 'flex_accounts_auth_version';
    public const SESSION_ACTIVITY = 'flex_accounts_last_activity';

    private const DUMMY_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
    private const IDLE_MINUTES = 120;

    private bool $resolved = false;

    /** @var array{id: int, email: string, first_name: string, last_name: string, phone: ?string, status: string, auth_version: int, email_verified_at: ?string, email_verification_required: bool, last_login_at: ?string, created_at: ?string}|null */
    private ?array $current = null;

    public function __construct(
        private readonly AccountStore $store,
        private readonly PasswordHasher $passwords,
        private readonly SessionInterface $session,
        private readonly AccountLoginThrottle $throttle,
        private readonly AccountEmailVerification $verification,
        private readonly ?ExtensionApiInterface $extensions = null,
    ) {}

    /** @return array{id: int, email: string, first_name: string, last_name: string, phone: ?string, status: string, auth_version: int, email_verified_at: ?string, email_verification_required: bool, last_login_at: ?string, created_at: ?string}|null */
    public function user(): ?array
    {
        if ($this->resolved) {
            return $this->current;
        }
        $this->resolved = true;
        $lastActivity = $this->session->get(self::SESSION_ACTIVITY);
        if (self::sessionExpired($lastActivity)) {
            $this->clearSession();
            return null;
        }
        $userId = $this->session->get(self::SESSION_USER);
        if (!is_int($userId) && !(is_string($userId) && ctype_digit($userId))) {
            return null;
        }
        $customer = $this->store->find((int) $userId);
        if ($customer === null
            || (int) $this->session->get(self::SESSION_VERSION, 0) !== (int) $customer['auth_version']
            || $customer['status'] !== 'active'
            || (($customer['email_verification_required'] ?? false) && $customer['email_verified_at'] === null)) {
            $this->clearSession();
            return null;
        }
        $this->session->put(self::SESSION_ACTIVITY, time());
        return $this->current = $customer;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public static function sessionExpired(mixed $lastActivity): bool
    {
        return !is_int($lastActivity) || time() - $lastActivity >= self::IDLE_MINUTES * 60;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{id: int, email: string, first_name: string, last_name: string, phone: ?string, status: string, auth_version: int, email_verified_at: ?string, email_verification_required: bool, last_login_at: ?string, created_at: ?string}
     */
    public function register(array $input, string $ipAddress): array
    {
        $settings = $this->store->settings();
        if (!($settings['registration_enabled'] ?? true)) {
            throw new \InvalidArgumentException('Регистрацията е временно спряна.', 403);
        }
        if ($this->throttle->tooManyAttempts('register:' . $ipAddress, $ipAddress)) {
            throw new \InvalidArgumentException('Твърде много опити. Опитайте отново по-късно.', 429);
        }
        $data = $this->validateProfile($input, true);
        $password = is_string($input['password'] ?? null) ? $input['password'] : '';
        $confirmation = is_string($input['password_confirmation'] ?? null) ? $input['password_confirmation'] : '';
        $this->assertPassword($password, $confirmation, (int) $settings['password_min_length']);
        if ($this->store->findByEmail($data['email']) !== null) {
            $this->throttle->recordFailure('register:' . $ipAddress, $ipAddress);
            throw new \InvalidArgumentException('Вече съществува акаунт с този имейл.', 409);
        }
        $status = ($settings['email_verification'] ?? false) ? 'pending' : 'active';
        $customer = $this->store->create($data, $this->passwords->hash($password), $status);
        if ($status === 'active') {
            $this->establish($customer);
        } else {
            $this->verification->request($customer['email'], $ipAddress);
        }

        return $customer;
    }

    public function attempt(string $email, string $password, string $ipAddress): bool
    {
        $email = mb_strtolower(trim($email));
        if ($this->throttle->tooManyAttempts($email, $ipAddress)) {
            throw new \InvalidArgumentException('Твърде много опити за вход. Опитайте отново след 15 минути.', 429);
        }
        $customer = $this->store->findByEmail($email);
        $hash = $customer !== null ? (string) $customer['password_hash'] : self::DUMMY_HASH;
        $valid = $this->passwords->verify($password, $hash);
        if (!$valid
            || $customer === null
            || $customer['status'] !== 'active'
            || (($customer['email_verification_required'] ?? false) && ($customer['email_verified_at'] ?? null) === null)) {
            $this->throttle->recordFailure($email, $ipAddress);
            return false;
        }
        if ($this->passwords->needsRehash($hash)) {
            $this->store->updatePassword((int) $customer['id'], $this->passwords->hash($password));
            $fresh = $this->store->find((int) $customer['id']);
            if ($fresh !== null) {
                $customer = $fresh + ['password_hash' => ''];
            }
        }
        unset($customer['password_hash']);
        $this->store->touchLogin((int) $customer['id']);
        $this->establish($customer);
        $this->throttle->clear($email, $ipAddress);

        return true;
    }

    public function logout(): void
    {
        $was = $this->current;
        $this->clearSession();
        $this->resolved = true;
        $this->current = null;
        if ($was !== null) {
            $this->extensions?->doAction('accounts.logged_out', ['customer' => $was]);
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array{id: int, email: string, first_name: string, last_name: string, phone: ?string, status: string, auth_version: int, email_verified_at: ?string, email_verification_required: bool, last_login_at: ?string, created_at: ?string}
     */
    public function updateProfile(array $input): array
    {
        $user = $this->user();
        if ($user === null) {
            throw new \InvalidArgumentException('Необходимо е да влезете в профила си.', 401);
        }
        $data = $this->validateProfile($input, false);
        $updated = $this->store->updateProfile((int) $user['id'], $data);
        $this->current = $updated;

        return $updated;
    }

    public function changePassword(string $current, string $password, string $confirmation): void
    {
        $user = $this->user();
        if ($user === null) {
            throw new \InvalidArgumentException('Необходимо е да влезете в профила си.', 401);
        }
        $row = $this->store->findByEmail($user['email']);
        if ($row === null || !$this->passwords->verify($current, (string) $row['password_hash'])) {
            throw new \InvalidArgumentException('Текущата парола е грешна.', 422);
        }
        $settings = $this->store->settings();
        $this->assertPassword($password, $confirmation, (int) $settings['password_min_length']);
        $this->store->updatePassword((int) $user['id'], $this->passwords->hash($password));
        $fresh = $this->store->find((int) $user['id']);
        if ($fresh === null) {
            throw new \InvalidArgumentException('Профилът не е намерен.', 404);
        }
        $this->establish($fresh);
    }

    public function redirectAfterLogin(?string $next = null): string
    {
        if (is_string($next) && $this->store->safeRedirectPath($next) && $next !== '') {
            return $next;
        }
        $configured = (string) ($this->store->settings()['redirect_after_login'] ?? '/');

        return $configured !== '' && $this->store->safeRedirectPath($configured) ? $configured : '/';
    }

    /**
     * @param array{id: int, email: string, first_name: string, last_name: string, phone: ?string, status: string, auth_version: int, email_verified_at: ?string, email_verification_required: bool, last_login_at: ?string, created_at: ?string} $customer
     */
    private function establish(array $customer): void
    {
        $this->session->regenerate();
        $this->session->put(self::SESSION_USER, (int) $customer['id']);
        $this->session->put(self::SESSION_VERSION, (int) $customer['auth_version']);
        $this->session->put(self::SESSION_ACTIVITY, time());
        $this->resolved = true;
        $this->current = $customer;
        $this->extensions?->doAction('accounts.logged_in', ['customer' => $customer]);
    }

    private function clearSession(): void
    {
        $this->session->remove(self::SESSION_USER);
        $this->session->remove(self::SESSION_VERSION);
        $this->session->remove(self::SESSION_ACTIVITY);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{email?: string, first_name: string, last_name: string, phone: ?string}
     */
    private function validateProfile(array $input, bool $withEmail): array
    {
        $result = [];
        if ($withEmail) {
            $email = is_string($input['email'] ?? null) ? mb_strtolower(trim($input['email'])) : '';
            if ($email === '' || strlen($email) > 190 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new \InvalidArgumentException('Въведете валиден имейл адрес.', 422);
            }
            $result['email'] = $email;
        }
        foreach (['first_name' => 80, 'last_name' => 80] as $field => $max) {
            $value = is_string($input[$field] ?? null) ? trim($input[$field]) : '';
            if ($value === '' || mb_strlen($value) > $max || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) {
                throw new \InvalidArgumentException('Попълнете коректно името и фамилията.', 422);
            }
            $result[$field] = $value;
        }
        $phone = $input['phone'] ?? null;
        if ($phone === null || $phone === '') {
            $result['phone'] = null;
        } elseif (!is_string($phone) || !preg_match('/^[+0-9 ()-]{6,30}$/D', trim($phone))) {
            throw new \InvalidArgumentException('Въведете валиден телефон или оставете полето празно.', 422);
        } else {
            $result['phone'] = trim($phone);
        }

        return $result;
    }

    private function assertPassword(string $password, string $confirmation, int $minimum): void
    {
        if (mb_strlen($password) < $minimum || strlen($password) > 72 || $password !== $confirmation) {
            throw new \InvalidArgumentException("Паролите трябва да съвпадат, да съдържат поне $minimum знака и да не надвишават 72 байта.", 422);
        }
    }
}
