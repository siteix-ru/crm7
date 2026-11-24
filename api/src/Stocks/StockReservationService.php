<?php

declare(strict_types=1);

namespace App\Stocks;

use App\Http\HttpException;
use App\Repositories\SettingsRepository;
use App\Repositories\StockReservationRepository;
use PDOException;

final class StockReservationService
{
    private const EPSILON = 0.00001;

    public function __construct(
        private readonly StockReservationRepository $reservations = new StockReservationRepository(),
        private readonly SettingsRepository $settings = new SettingsRepository(),
        private readonly StockLotService $lots = new StockLotService()
    ) {
    }

    /**
     * Приводит резервы заявки в соответствие с её составом: проверяет доступность,
     * уменьшает/восстанавливает остатки и сохраняет актуальные резервы.
     */
    /**
     * Caller must hold this lock from before the reservation read until after
     * the surrounding transaction is committed or rolled back.
     */
    public function lock(int $requestId): void
    {
        StockRequestLock::lock($requestId);
    }

    public function unlock(int $requestId): void
    {
        StockRequestLock::unlock($requestId);
    }

    public function apply(int $requestId, array $items): void
    {
        // Shared topology lock: prevents a concurrent warehouse collapse from
        // deleting levels while this reservation is being written.
        $this->reservations->lockTopologyShared();
        $desired = $this->aggregate($items);
        $allowZero = $this->settings->allowZeroStock();
        $reserveEnabled = $this->settings->stockReserveEnabled();

        if (!$reserveEnabled) {
            // Preserve the legacy check-only behaviour when reservation is disabled.
            if (!$allowZero) {
                $this->assertAvailable($desired, $this->reservations->forRequestFresh($requestId));
                foreach ($desired as $levelId => $quantity) {
                    if ($this->lots->isLotLevel((int) $levelId)) {
                        $this->lots->assertAvailable((int) $levelId, number_format((float) $quantity, 5, '.', ''));
                    }
                }
            }

            return;
        }

        $current = $this->reservations->forRequestFresh($requestId);
        $lotDesired = [];

        // F-01: a lot level that disappears from the composition must have its
        // allocations released BEFORE replace() deletes the parent reservation.
        foreach (array_keys($current) as $levelId) {
            if (!array_key_exists($levelId, $desired) && $this->lots->isLotLevel((int) $levelId)) {
                $this->lots->releaseLevel($requestId, (int) $levelId);
            }
        }

        foreach ($this->levelIds($desired, $current) as $levelId) {
            if ($this->lots->isLotLevel($levelId)) {
                // Lot levels keep A/R/P in sync inside StockLotService.
                if (isset($desired[$levelId])) {
                    $lotDesired[$levelId] = number_format((float) $desired[$levelId], 5, '.', '');
                }
                continue;
            }

            $delta = ($desired[$levelId] ?? 0.0) - ($current[$levelId] ?? 0.0);
            $change = -$delta;

            if ($allowZero) {
                // Explicitly preserved legacy bypass: allow over-reservation.
                $this->reservations->adjustLevel($levelId, $change);
                continue;
            }

            if (!$this->adjustLevelAtomically(
                $levelId,
                $change,
                (float) ($desired[$levelId] ?? 0.0),
                (float) ($current[$levelId] ?? 0.0)
            )) {
                throw $this->insufficientStock(
                    $levelId,
                    $this->reservations->freshLevelQuantity($levelId) + ($current[$levelId] ?? 0.0),
                    (float) ($desired[$levelId] ?? 0.0)
                );
            }
        }

        $this->reservations->replace($requestId, $desired);

        if ($lotDesired !== []) {
            $this->lots->reserve($requestId, $lotDesired);
        }
    }

    private function insufficientStock(int $levelId, float $available, float $want): HttpException
    {
        return new HttpException(
            422,
            'insufficient_stock',
            'Недостаточно остатка «' . $this->reservations->levelLabel($levelId) . '»: доступно '
                . $this->formatQuantity($available) . ', запрошено ' . $this->formatQuantity($want)
        );
    }

    /**
     * Applies the guarded balance change and translates only the confirmed
     * MariaDB "record changed since last read" conflict (1020) into a domain
     * decision. A consistent read earlier in the same transaction (for example
     * of the settings row) pins a REPEATABLE READ snapshot, so the conditional
     * UPDATE of a row changed by a committed competitor raises 1020 instead of
     * simply failing its predicate. Re-reading the balance outside that
     * snapshot distinguishes a genuinely insufficient remainder (normal 422)
     * from a real DB conflict, which is rethrown rather than masked.
     */
    private function adjustLevelAtomically(int $levelId, float $change, float $want, float $current): bool
    {
        try {
            return $this->reservations->adjustLevelAtomic($levelId, $change);
        } catch (PDOException $e) {
            if (!$this->isStockRowChangedConflict($e)) {
                throw $e;
            }
            $available = $this->reservations->freshLevelQuantity($levelId) + $current;
            if ($want > $available + self::EPSILON) {
                throw $this->insufficientStock($levelId, $available, $want);
            }
            throw $e;
        }
    }

    private function isStockRowChangedConflict(PDOException $e): bool
    {
        return (int) ($e->errorInfo[1] ?? 0) === 1020
            && strpos($e->getMessage(), 'stock_levels') !== false;
    }

    /**
     * Возвращает остатки по всем активным резервам заявки.
     */
    public function release(int $requestId): void
    {
        $this->reservations->lockTopologyShared();
        $current = $this->reservations->forRequestFresh($requestId);

        if ($current === []) {
            return;
        }

        $hasLots = false;
        foreach ($current as $levelId => $quantity) {
            if ($this->lots->isLotLevel((int) $levelId)) {
                $hasLots = true;
                continue;
            }
            $this->reservations->adjustLevel($levelId, $quantity);
        }

        if ($hasLots) {
            $this->lots->release($requestId);
        }

        $this->reservations->deleteForRequest($requestId);
    }

    /**
     * @return array<int, float> stock_level_id => quantity
     */
    private function aggregate(array $items): array
    {
        $map = [];

        foreach ($items as $item) {
            $levelId = (int) ($item['stock_level_id'] ?? 0);

            if ($levelId <= 0) {
                continue;
            }

            $map[$levelId] = ($map[$levelId] ?? 0.0) + (float) $item['quantity'];
        }

        return $map;
    }

    /**
     * @param array<int, float> $desired
     * @param array<int, float> $current
     */
    private function assertAvailable(array $desired, array $current): void
    {
        foreach ($desired as $levelId => $want) {
            $available = $this->reservations->levelQuantity($levelId) + ($current[$levelId] ?? 0.0);

            if ($want > $available + self::EPSILON) {
                throw $this->insufficientStock($levelId, $available, $want);
            }
        }
    }

    /**
     * After a deadlock MariaDB has already rolled back the whole transaction.
     * Re-read the committed balance on an independent connection and report a
     * genuine legacy shortage with the standard domain error. Lot-mode levels
     * are skipped: their own operation contract stays authoritative.
     *
     * @param array<int, array<string, mixed>> $items
     */
    public function assertAvailableAfterConflict(int $requestId, array $items): void
    {
        $legacy = [];
        foreach ($this->aggregate($items) as $levelId => $want) {
            if (!$this->lots->isLotLevel((int) $levelId)) {
                $legacy[(int) $levelId] = $want;
            }
        }
        if ($legacy === []) {
            return;
        }

        $current = $this->reservations->forRequestFresh($requestId);
        foreach ($legacy as $levelId => $want) {
            $available = $this->reservations->lockedFreshLevelQuantity($levelId) + ($current[$levelId] ?? 0.0);
            if ($want > $available + self::EPSILON) {
                throw $this->insufficientStock($levelId, $available, $want);
            }
        }
    }

    /**
     * @param array<int, float> $desired
     * @param array<int, float> $current
     * @return array<int, int>
     */
    private function levelIds(array $desired, array $current): array
    {
        $ids = array_values(array_unique(array_map(
            'intval',
            array_merge(array_keys($desired), array_keys($current))
        )));
        // A stable order is required even when apply() is called directly
        // without the request-level pre-lock: two requests with the same set
        // of levels listed in a different client order must not deadlock.
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    private function formatQuantity(float $value): string
    {
        return number_format($value, 3, ',', ' ');
    }
}
