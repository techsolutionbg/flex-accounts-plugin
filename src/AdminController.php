<?php

declare(strict_types=1);

namespace Flex\Accounts;

use Flex\Contracts\Http\ResponseFactoryInterface;
use Flex\Http\RequestInput;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};

final readonly class AdminController
{
    public function __construct(
        private ResponseFactoryInterface $responses,
        private RequestInput $input,
        private AccountStore $store,
        private CommerceDetector $commerce,
    ) {}

    public function __invoke(ServerRequestInterface $request, array $arguments = []): ResponseInterface
    {
        try {
            $path = $request->getUri()->getPath();
            if (str_ends_with($path, '/settings')) {
                $settings = $request->getMethod() === 'GET'
                    ? $this->store->settings()
                    : $this->store->saveSettings($this->input->all($request));

                return $this->responses->json([
                    'settings' => $settings,
                    'commerce_active' => $this->commerce->active(),
                ], 200, ['Cache-Control' => 'no-store']);
            }
            $id = (int) ($arguments['id'] ?? 0);
            if ($id > 0) {
                if ($request->getMethod() === 'PUT') {
                    $body = $this->input->all($request);
                    $status = is_string($body['status'] ?? null) ? $body['status'] : '';

                    return $this->responses->json([
                        'record' => $this->store->setStatus($id, $status),
                    ], 200, ['Cache-Control' => 'no-store']);
                }
                $record = $this->store->find($id);
                if ($record === null) {
                    throw new \InvalidArgumentException('Клиентът не е намерен.', 404);
                }

                return $this->responses->json(['record' => $record], 200, ['Cache-Control' => 'no-store']);
            }
            $q = $request->getQueryParams();
            if (!is_string($q['q'] ?? '')) {
                throw new \InvalidArgumentException('Невалидно търсене.', 422);
            }

            return $this->responses->json(
                $this->store->adminList(
                    (string) ($q['status'] ?? 'all'),
                    $q['q'] ?? '',
                    (int) ($q['page'] ?? 1),
                    [
                        'verified' => (string) ($q['verified'] ?? 'all'),
                        'login' => (string) ($q['login'] ?? 'all'),
                        'phone' => (string) ($q['phone'] ?? 'all'),
                    ],
                ) + ['commerce_active' => $this->commerce->active()],
                200,
                ['Cache-Control' => 'no-store'],
            );
        } catch (\InvalidArgumentException $e) {
            return $this->responses->json(['error' => ['message' => $e->getMessage()]], $e->getCode() ?: 422, ['Cache-Control' => 'no-store']);
        }
    }
}
