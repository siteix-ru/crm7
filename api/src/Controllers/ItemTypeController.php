<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Stocks\ItemTypeService;

final class ItemTypeController extends ApiController
{
    public function __construct(private readonly ItemTypeService $service = new ItemTypeService())
    {
        parent::__construct();
    }

    public function index(Request $request): Response
    {
        [, $capabilities] = $this->context($request);

        return Response::ok($this->service->list($capabilities));
    }

    public function show(Request $request, array $params): Response
    {
        [, $capabilities] = $this->context($request);

        return Response::ok($this->service->get($capabilities, (int) ($params['id'] ?? 0)));
    }

    public function items(Request $request, array $params): Response
    {
        [, $capabilities] = $this->context($request);

        return Response::ok($this->service->items($capabilities, (int) ($params['id'] ?? 0)));
    }

    public function create(Request $request): Response
    {
        [$user, $capabilities] = $this->context($request);
        $result = $this->service->create($capabilities, $request->bodyAll());
        $type = $result['type'];

        $this->audit($request, $user, 'item_type.create', 'item_type', (int) $type['id'], [
            'code' => $type['code'],
            'title' => $type['title'],
        ]);

        return Response::ok($result, 201);
    }

    public function update(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);
        $id = (int) ($params['id'] ?? 0);
        $result = $this->service->update($capabilities, $id, $request->bodyAll());
        $type = $result['type'];

        $this->audit($request, $user, 'item_type.update', 'item_type', $id, [
            'code' => $type['code'],
            'title' => $type['title'],
            'enabled' => $type['enabled'],
        ]);

        return Response::ok($result);
    }

    public function delete(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);
        $id = (int) ($params['id'] ?? 0);
        $result = $this->service->delete($capabilities, $id);

        $this->audit($request, $user, 'item_type.delete', 'item_type', $id);

        return Response::ok($result);
    }
}
