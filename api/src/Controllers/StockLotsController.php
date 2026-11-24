<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\RequestRepository;
use App\Repositories\StockLotRepository;
use App\Repositories\StocksRepository;
use App\Requests\RequestService;
use App\Stocks\StockLotService;
use App\Support\Decimal;

final class StockLotsController extends ApiController
{
    public function __construct(
        private readonly StockLotService $lots = new StockLotService(),
        private readonly RequestService $requests = new RequestService(),
        private readonly RequestRepository $repository = new RequestRepository(),
        private readonly StockLotRepository $lotRepository = new StockLotRepository(),
        private readonly StocksRepository $stocks = new StocksRepository()
    ) {
        parent::__construct();
    }

    public function issue(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);
        $requestId = (int) ($params['id'] ?? 0);
        $quantity = $this->quantity($request);
        $idempotencyKey = $this->stringKey($request->input('idempotency_key', null));

        // F-02/F-03: levels are re-read and EVERY warehouse is authorized inside
        // the lock/transaction, before any balance is changed.
        $result = $this->lots->issueWithLock(
            $requestId,
            $quantity,
            $idempotencyKey,
            function () use ($user, $capabilities, $requestId): void {
                $levels = $this->lots->reservedLevels($requestId);
                if ($levels === []) {
                    throw new HttpException(422, 'insufficient_lot_stock', 'No lot reservation for request');
                }
                $this->assertRights($user, $capabilities, $requestId, $levels);
            }
        );

        return Response::ok($result);
    }

    public function reversal(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);
        $issueId = (int) ($params['id'] ?? 0);
        $quantity = $this->quantity($request);
        $idempotencyKey = $this->stringKey($request->input('idempotency_key', null));

        $context = $this->lots->issueContext($issueId);
        if ($context === null) {
            throw new HttpException(404, 'not_found', 'Issue operation not found');
        }

        $result = $this->lots->reversalWithLock(
            $issueId,
            $quantity,
            $idempotencyKey,
            function () use ($user, $capabilities, $issueId, $context): void {
                $levels = $this->lots->issueLevels($issueId);
                if ($levels === []) {
                    throw new HttpException(404, 'not_found', 'Issue operation not found');
                }
                $this->assertRights($user, $capabilities, $context['request_id'], $levels);
            }
        );

        return Response::ok($result);
    }

    /**
     * GET /stocks/lots — read-only list of lots with canonical decimal strings.
     */
    public function lots(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);
        $this->assertViewCapability($capabilities);

        $itemId = (int) $request->queryParam('item_id', '0');
        $warehouseId = (int) $request->queryParam('warehouse_id', '0');
        $lotCode = $request->queryParam('lot_code');
        $expiresBefore = $this->queryDate($request, 'expires_before');
        $expiresAfter = $this->queryDate($request, 'expires_after');
        $status = $request->queryParam('status');
        if ($status !== '' && !in_array($status, ['active', 'reserved', 'expired', 'empty'], true)) {
            throw new HttpException(422, 'validation_error', 'Некорректный статус партии');
        }

        $page = max(1, (int) $request->queryParam('page', '1'));
        $perPage = (int) $request->queryParam('per_page', '50');
        $perPage = $perPage <= 0 ? 50 : min(200, $perPage);

        // A targeted level/warehouse is authorized explicitly. Without a
        // filter the list itself is narrowed to the caller's warehouses, so
        // an inaccessible warehouse can never leak into the response.
        $stockSids = $this->accessibleStockSids($user, $capabilities);
        if ($itemId > 0) {
            $this->lots->assertLevelViewRights($user, $capabilities, $itemId);
            $stockSids = null;
        }
        if ($warehouseId > 0) {
            $warehouse = $this->stocks->find($warehouseId);
            if ($warehouse === null) {
                throw new HttpException(404, 'not_found', 'Склад не найден');
            }
            $this->lots->assertWarehouseViewRights($user, $capabilities, $warehouseId);
            $stockSids = null;
        }

        $result = $this->lotRepository->list(
            [
                'item_id' => $itemId,
                'warehouse_id' => $warehouseId,
                'lot_code' => $lotCode,
                'expires_before' => $expiresBefore,
                'expires_after' => $expiresAfter,
                'status' => $status,
                'today' => date('Y-m-d'),
            ],
            $stockSids,
            $page,
            $perPage
        );

        return Response::ok([
            'items' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'per_page' => $perPage,
        ]);
    }

    /**
     * POST /stocks/lots/receive — receipt into an existing or a new lot.
     */
    public function receive(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);
        $this->assertEditCapability($capabilities);

        $levelId = (int) $request->input('stock_level_id', 0);
        if ($levelId <= 0) {
            throw new HttpException(422, 'validation_error', 'Некорректный склад');
        }
        $lotCode = $this->stringValue($request->input('lot_code', null), 'lot_code', 128);
        $quantity = $this->quantity($request);
        $expiresAt = $this->dateValue($request->input('expires_at', null), 'expires_at');
        $idempotencyKey = $this->stringKey($request->input('idempotency_key', null));

        $this->requireLotRights($user, $capabilities, [$levelId]);

        $result = $this->lots->receive($levelId, $lotCode, $quantity, $expiresAt, $idempotencyKey);
        $lotId = (int) ($result['lot_id'] ?? 0);
        if ($lotId <= 0) {
            // Idempotent replay may not carry a freshly created identity.
            $lotId = $this->lotRepository->findLotId($levelId, $lotCode) ?? 0;
        }

        return Response::ok([
            'lot_id' => $lotId > 0 ? $lotId : null,
            'already_applied' => (bool) ($result['already_applied'] ?? false),
            'level_id' => $levelId,
            'quantity' => $quantity,
        ]);
    }

    /**
     * POST /stocks/lots/convert — initial distribution of a balance into lots.
     */
    public function convert(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);
        $this->assertEditCapability($capabilities);

        $levelId = (int) $request->input('stock_level_id', 0);
        if ($levelId <= 0) {
            throw new HttpException(422, 'validation_error', 'Некорректный склад');
        }

        $rawLots = $request->input('lots', null);
        if (!is_array($rawLots) || $rawLots === [] || count($rawLots) > 50) {
            throw new HttpException(422, 'validation_error', 'Некорректный список партий');
        }

        $lots = [];
        foreach ($rawLots as $lot) {
            if (!is_array($lot)) {
                throw new HttpException(422, 'validation_error', 'Некорректная партия');
            }
            $lots[] = [
                'lot_code' => $this->stringValue($lot['lot_code'] ?? null, 'lot_code', 128),
                'quantity' => $this->quantityValue($lot['quantity'] ?? null),
                'expires_at' => $this->dateValue($lot['expires_at'] ?? null, 'expires_at'),
            ];
        }

        // Accepted for API symmetry; convert() itself has no idempotency scope.
        $this->stringKey($request->input('idempotency_key', null));

        $this->requireLotRights($user, $capabilities, [$levelId]);

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            // convert() takes SELECT QUANTITY ... FOR UPDATE on the level and
            // validates the distribution under that row lock.
            $this->lots->convert($levelId, $lots);
            $total = '0.00000';
            foreach ($lots as $lot) {
                $total = Decimal::add($total, $lot['quantity']);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return Response::ok([
            'stock_level_id' => $levelId,
            'quantity' => $total,
            'lots' => array_map(static fn (array $lot): array => [
                'lot_code' => $lot['lot_code'],
                'quantity' => $lot['quantity'],
            ], $lots),
        ]);
    }

    /**
     * GET /requests/{id}/lots — lot reservations and issue history of a request.
     */
    public function requestLots(Request $request, array $params): Response
    {
        [$user, $capabilities] = $this->context($request);
        $this->assertViewCapability($capabilities);

        $requestId = (int) ($params['id'] ?? 0);
        $row = $this->repository->findById($requestId);
        if ($row === null) {
            throw new HttpException(404, 'not_found', 'Заявка не найдена');
        }

        if (!$this->requests->canManageRequest($user, $capabilities, $row)) {
            // A warehouse worker may not manage requests but still needs to see
            // the lots of every reserved warehouse. Read-only access is enough.
            $levels = $this->lots->reservedLevels($requestId);
            foreach ($levels as $levelId) {
                $this->lots->assertLevelViewRights($user, $capabilities, (int) $levelId);
            }
        }

        return Response::ok(array_merge(
            ['request_id' => $requestId],
            [
                'levels' => $this->lotRepository->requestLevels($requestId),
                'reservations' => $this->lotRepository->requestReservations($requestId),
                'issues' => $this->lotRepository->requestIssues($requestId),
            ]
        ));
    }

    /**
     * @param array<int, int> $levels
     */
    private function assertRights(array $user, array $capabilities, int $requestId, array $levels): void
    {
        $row = $this->repository->findById($requestId);
        if ($row === null || !$this->requests->canManageRequest($user, $capabilities, $row)) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав для заявки');
        }

        foreach ($levels as $levelId) {
            $this->lots->assertIssueRights($user, $capabilities, $requestId, $levelId);
        }
    }

    private function assertEditCapability(array $capabilities): void
    {
        if (!in_array('stocks.edit', $capabilities, true)) {
            throw new HttpException(403, 'forbidden', 'Недостаточно прав склада');
        }
    }

    /** Read-only lot data is available to anyone who may view the stock module. */
    private function assertViewCapability(array $capabilities): void
    {
        $viewCapabilities = [
            'stocks.view',
            'stocks.edit',
            'stocks.import',
            'stocks.delete',
            'stocks.warehouses.view_all',
            'stocks.warehouses.manage',
        ];
        foreach ($viewCapabilities as $capability) {
            if (in_array($capability, $capabilities, true)) {
                return;
            }
        }
        throw new HttpException(403, 'forbidden', 'Недостаточно прав для просмотра склада');
    }

    /** Server-side warehouse rights for every level resolved from a payload. */
    private function requireLotRights(array $user, array $capabilities, array $levels): void
    {
        $this->assertEditCapability($capabilities);
        foreach ($levels as $levelId) {
            $this->lots->assertLevelRights($user, $capabilities, (int) $levelId);
        }
    }

    /**
     * @return string[]|null null means every warehouse is visible
     */
    private function accessibleStockSids(array $user, array $capabilities): ?array
    {
        if (in_array('stocks.warehouses.view_all', $capabilities, true)
            || in_array('stocks.warehouses.manage', $capabilities, true)) {
            return null;
        }

        $rows = $this->stocks->warehousesForUser(
            (int) ($user['LEVEL'] ?? 0),
            (string) ($user['SID'] ?? ''),
            false
        );

        return array_values(array_unique(array_map(
            static fn (array $row): string => (string) $row['SID'],
            $rows
        )));
    }

    /** F-05/N-04: strict quantity validation, no internal exceptions leak to the API. */
    private function quantity(Request $request): string
    {
        return $this->quantityValue($request->input('quantity', null));
    }

    /** Same validation as quantity(), for values nested in a lots[] payload. */
    private function quantityValue(mixed $raw): string
    {
        if (is_string($raw)) {
            $text = trim($raw);
        } elseif (is_int($raw)) {
            $text = (string) $raw;
        } elseif (is_float($raw)) {
            // A JSON decimal is decoded as float. PHP may render it in exponent
            // form ("1.0E-5"), so expand that notation into a plain decimal
            // string without doing any float arithmetic on the value itself.
            if (is_nan($raw) || is_infinite($raw)) {
                throw new HttpException(422, 'validation_error', 'Некорректное количество');
            }
            $text = $this->expandExponent((string) $raw);
        } else {
            throw new HttpException(422, 'validation_error', 'Некорректное количество');
        }

        if ($text === '' || preg_match('/^-?[0-9]{1,13}(?:\.[0-9]{1,5})?$/', $text) !== 1) {
            throw new HttpException(422, 'validation_error', 'Некорректное количество');
        }

        try {
            $quantity = Decimal::parse($text);
        } catch (\InvalidArgumentException) {
            throw new HttpException(422, 'validation_error', 'Некорректное количество');
        }
        if (Decimal::compare($quantity, '0.00000') <= 0) {
            throw new HttpException(422, 'validation_error', 'Количество должно быть положительным');
        }

        return $quantity;
    }

    /**
     * Turns PHP's shortest round-trip float representation into a plain decimal
     * string, preserving every digit (no rounding, no float arithmetic).
     */
    private function expandExponent(string $text): string
    {
        if (stripos($text, 'e') === false) {
            return $text;
        }

        $parts = preg_split('/[eE]/', $text);
        $mantissa = (string) $parts[0];
        $exponent = (int) $parts[1];
        $negative = str_starts_with($mantissa, '-');
        if ($negative) {
            $mantissa = substr($mantissa, 1);
        }

        $decimal = explode('.', $mantissa, 2);
        $integer = (string) $decimal[0];
        $fraction = (string) ($decimal[1] ?? '');
        $digits = $integer . $fraction;
        $point = strlen($integer) + $exponent;

        if ($point <= 0) {
            $plain = '0.' . str_repeat('0', -$point) . $digits;
        } elseif ($point >= strlen($digits)) {
            $plain = $digits . str_repeat('0', $point - strlen($digits));
        } else {
            $plain = substr($digits, 0, $point) . '.' . substr($digits, $point);
        }

        if (str_contains($plain, '.')) {
            $plain = rtrim($plain, '0');
            if (str_ends_with($plain, '.')) {
                $plain .= '0';
            }
        }

        return ($negative ? '-' : '') . $plain;
    }

    private function stringKey(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $text = (string) $value;

        return preg_match('/^[A-Za-z0-9_-]{1,128}$/', $text) === 1 ? $text : null;
    }

    private function stringValue(mixed $value, string $field, int $maxLength): string
    {
        if (!is_string($value)) {
            throw new HttpException(422, 'validation_error', 'Некорректное значение: ' . $field);
        }
        $text = trim($value);
        if ($text === '' || mb_strlen($text) > $maxLength) {
            throw new HttpException(422, 'validation_error', 'Некорректное значение: ' . $field);
        }

        return $text;
    }

    private function dateValue(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $value;
        }

        throw new HttpException(422, 'validation_error', 'Некорректная дата: ' . $field);
    }

    private function queryDate(Request $request, string $field): string
    {
        $value = $request->queryParam($field);
        if ($value !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw new HttpException(422, 'validation_error', 'Некорректная дата: ' . $field);
        }

        return $value;
    }
}
