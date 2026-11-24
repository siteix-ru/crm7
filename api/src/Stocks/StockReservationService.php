<?php

declare(strict_types=1);

namespace App\Stocks;

use App\Http\HttpException;
use App\Repositories\SettingsRepository;
use App\Repositories\StockReservationRepository;

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

            if (!$this->reservations->adjustLevelAtomic($levelId, $change)) {
                throw $this->insufficientStock(
                    $levelId,
                    $this->reservations->levelQuantity($levelId) + ($current[$levelId] ?? 0.0),
                    $desired[$levelId] ?? 0.0
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
     * @param array<int, float> $desired
     * @param array<int, float> $current
     * @return array<int, int>
     */
    private function levelIds(array $desired, array $current): array
    {
        return array_values(array_unique(array_map(
            'intval',
            array_merge(array_keys($desired), array_keys($current))
        )));
    }

    private function formatQuantity(float $value): string
    {
        return number_format($value, 3, ',', ' ');
    }
}
