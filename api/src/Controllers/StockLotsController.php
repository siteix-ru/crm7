<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\RequestRepository;
use App\Requests\RequestService;
use App\Stocks\StockLotService;
use App\Support\Decimal;

final class StockLotsController extends ApiController
{
    public function __construct(
        private readonly StockLotService $lots = new StockLotService(),
        private readonly RequestService $requests = new RequestService(),
        private readonly RequestRepository $repository = new RequestRepository()
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

    /** F-05/N-04: strict quantity validation, no internal exceptions leak to the API. */
    private function quantity(Request $request): string
    {
        $raw = $request->input('quantity', null);
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
}
