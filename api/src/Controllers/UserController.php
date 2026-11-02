<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\UserRepository;
use App\Users\UserAdminService;
use App\Users\UserRoleConversionService;

final class UserController extends ApiController
{
    public function __construct(
        private readonly UserRepository $users = new UserRepository(),
        private readonly UserAdminService $admin = new UserAdminService(),
        private readonly UserRoleConversionService $conversion = new UserRoleConversionService()
    ) {
        parent::__construct();
    }

    private function denyInDemo(): void
    {
        if (Config::demoMode()) {
            throw new HttpException(403, 'demo_mode', 'Действие недоступно в демо-режиме');
        }
    }

    public function managers(Request $request): Response
    {
        $this->context($request);

        $items = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'sid' => (string) $row['sid'],
            'login' => (string) $row['login'],
            'name' => (string) $row['name'],
            'level' => (int) $row['level'],
            'email' => (string) ($row['email'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
        ], $this->users->listManagers());

        return Response::ok(['items' => $items]);
    }

    public function index(Request $request): Response
    {
        [, $capabilities] = $this->context($request);

        if (!in_array('users.manage', $capabilities, true)) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав');
        }

        return Response::ok($this->admin->list([
            'q' => trim($request->queryParam('q')),
            'scope' => trim($request->queryParam('scope')),
            'level' => (int) $request->queryParam('level', '0'),
            'state' => trim($request->queryParam('state')),
            'sort' => trim($request->queryParam('sort')),
        ], max(1, (int) $request->queryParam('page', '1')), (int) $request->queryParam('per_page', '50') <= 0 ? 0 : max(10, min(100, (int) $request->queryParam('per_page', '50')))));
    }

    public function show(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);

        return Response::ok($this->admin->card($user, $capabilities, (int) ($params['id'] ?? 0)));
    }

    public function history(Request $request, array $params): Response
    {
        [, $capabilities] = $this->context($request);

        return Response::ok($this->admin->history($capabilities, (int) ($params['id'] ?? 0)));
    }

    public function create(Request $request): Response
    {
        $this->denyInDemo();

        [$user, $capabilities] = $this->context($request);

        $result = $this->admin->create($user, $capabilities, $request->bodyAll());
        $this->audit($request, $user, 'user.create', 'user', $result['id'], ['login' => $result['login']]);

        return Response::ok($result, 201);
    }

    public function update(Request $request, array $params): Response
    {
        $this->denyInDemo();

        [$user, $capabilities] = $this->context($request);

        $result = $this->admin->update($user, $capabilities, (int) ($params['id'] ?? 0), $request->bodyAll());
        $this->audit($request, $user, 'user.update', 'user', (int) ($params['id'] ?? 0), [
            'level' => $result['level'],
        ]);

        return Response::ok($result);
    }

    public function conversion(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);

        return Response::ok($this->conversion->preview(
            $user,
            $capabilities,
            (int) ($params['id'] ?? 0)
        ));
    }

    public function convert(Request $request, array $params): Response
    {
        $this->denyInDemo();

        [$user, $capabilities] = $this->context($request);
        $id = (int) ($params['id'] ?? 0);
        $result = $this->conversion->convert($user, $capabilities, $id, $request->bodyAll());
        $this->audit(
            $request,
            $user,
            $result['direction'] === 'to_staff' ? 'user.converted_to_staff' : 'user.converted_to_client',
            'user',
            $id,
            [
                'from_level' => $result['from_level'],
                'to_level' => $result['to_level'],
                'counts' => $result['counts'],
            ]
        );

        return Response::ok($result);
    }

    public function block(Request $request, array $params): Response
    {
        $this->denyInDemo();

        [$user, $capabilities] = $this->context($request);

        $result = $this->admin->block($user, $capabilities, (int) ($params['id'] ?? 0), true);
        $this->audit($request, $user, 'user.block', 'user', (int) ($params['id'] ?? 0));

        return Response::ok($result);
    }

    public function unblock(Request $request, array $params): Response
    {
        $this->denyInDemo();

        [$user, $capabilities] = $this->context($request);

        $result = $this->admin->block($user, $capabilities, (int) ($params['id'] ?? 0), false);
        $this->audit($request, $user, 'user.unblock', 'user', (int) ($params['id'] ?? 0));

        return Response::ok($result);
    }

    public function resetPassword(Request $request, array $params): Response
    {
        $this->denyInDemo();

        [$user, $capabilities] = $this->context($request);

        $result = $this->admin->resetPassword($user, $capabilities, (int) ($params['id'] ?? 0));
        $this->audit($request, $user, 'user.reset_password', 'user', (int) ($params['id'] ?? 0));

        return Response::ok($result);
    }

    public function sendCredentials(Request $request, array $params): Response
    {
        $this->denyInDemo();

        [$user, $capabilities] = $this->context($request);
        $id = (int) ($params['id'] ?? 0);
        $body = $request->bodyAll();
        $result = $this->admin->sendCredentials(
            $user,
            $capabilities,
            $id,
            (string) ($body['login'] ?? ''),
            (string) ($body['password'] ?? '')
        );
        $this->audit($request, $user, 'user.credentials_sent', 'user', $id, [
            'email' => $result['email'] ?? null,
        ]);

        return Response::ok($result);
    }

    public function changeLogin(Request $request, array $params): Response
    {
        $this->denyInDemo();

        [$user, $capabilities] = $this->context($request);
        $id = (int) ($params['id'] ?? 0);
        $result = $this->admin->changeLogin(
            $user,
            $capabilities,
            $id,
            (string) ($request->bodyAll()['login'] ?? '')
        );
        $this->audit($request, $user, 'user.login_changed', 'user', $id, [
            'login' => $result['login'],
        ]);

        return Response::ok($result);
    }

    public function revokeSessions(Request $request, array $params): Response
    {
        $this->denyInDemo();

        [$user, $capabilities] = $this->context($request);

        $result = $this->admin->revokeSessions($user, $capabilities, (int) ($params['id'] ?? 0));
        $this->audit($request, $user, 'user.revoke_sessions', 'user', (int) ($params['id'] ?? 0));

        return Response::ok($result);
    }

    public function setPriceTypes(Request $request, array $params): Response
    {
        $this->denyInDemo();

        [$user, $capabilities] = $this->context($request);

        $id = (int) ($params['id'] ?? 0);
        $result = $this->admin->setPriceTypes($user, $capabilities, $id, $request->bodyAll());
        $this->audit($request, $user, 'user.price_types', 'user', $id, [
            'price_type_ids' => array_map(
                static fn (array $type): int => (int) $type['id'],
                $result['price_types']
            ),
        ]);

        return Response::ok($result);
    }

    public function roles(Request $request): Response
    {
        [, $capabilities] = $this->context($request);

        if (!in_array('users.manage', $capabilities, true) && !in_array('roles.manage', $capabilities, true)) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав');
        }

        return Response::ok($this->admin->roles());
    }

    public function saveRole(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);

        return Response::ok($this->admin->saveRole(
            $user,
            $capabilities,
            (int) ($params['level'] ?? 0),
            $request->bodyAll()
        ));
    }
}
