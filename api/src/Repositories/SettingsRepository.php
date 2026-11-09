<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class SettingsRepository
{
    public function get(string $key): ?string
    {
        $stmt = Database::pdo()->prepare('SELECT value FROM settings WHERE `key` = ? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    public function set(string $key, ?string $value): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO settings (`key`, value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE value = VALUES(value)'
        );
        $stmt->execute([$key, $value]);
    }

    public function many(array $pairs): void
    {
        foreach ($pairs as $key => $value) {
            $this->set((string) $key, $value === null ? null : (string) $value);
        }
    }

    public function salesEnabled(): bool
    {
        return $this->get('sales.enabled') !== '0';
    }

    public function emailExportEnabled(): bool
    {
        return $this->get('mail.export_enabled') !== '0';
    }

    public function moduleEnabled(string $setting): bool
    {
        if ($setting === 'module.mail') {
            return $this->mailEnabled();
        }

        return $this->get($setting) !== '0';
    }

    public function mailEnabled(): bool
    {
        $module = $this->get('module.mail');

        if ($module === null) {
            return $this->get('mail.enabled') !== '0';
        }

        return $module !== '0';
    }

    public function mailConfigured(): bool
    {
        if (!$this->mailEnabled()) {
            return false;
        }

        $host = trim((string) $this->get('mail.host'));
        $port = (int) ($this->get('mail.port') ?: 465);
        $username = trim((string) $this->get('mail.username'));
        $password = trim((string) $this->get('mail.password'));
        $from = trim((string) $this->get('mail.from')) ?: $username;

        return $host !== ''
            && $port > 0
            && $username !== ''
            && $password !== ''
            && $from !== '';
    }

    public function stockReserveEnabled(): bool
    {
        return $this->get('stock.reserve_enabled') === '1';
    }

    public function pricesLevels(): array
    {
        return $this->levels('prices.levels');
    }

    public function groupsLevels(): array
    {
        return $this->levels('groups.levels');
    }

    public function pricesEnabled(): bool
    {
        return $this->pricesLevels() !== [];
    }

    public function groupsEnabled(): bool
    {
        return $this->groupsLevels() !== [];
    }

    public function pricesEnabledForLevel(int $level): bool
    {
        return in_array($level, $this->pricesLevels(), true);
    }

    public function groupsEnabledForLevel(int $level): bool
    {
        return in_array($level, $this->groupsLevels(), true);
    }

    /**
     * @return int[]
     */
    private function levels(string $key): array
    {
        $raw = $this->get($key);

        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $allowed = [90, 50, 10, 5, 1];
        $levels = [];

        foreach (explode(',', $raw) as $value) {
            $level = (int) trim($value);

            if (in_array($level, $allowed, true)) {
                $levels[$level] = true;
            }
        }

        $result = array_keys($levels);
        sort($result);

        return $result;
    }

    public function allowZeroStock(): bool
    {
        return $this->get('stock.allow_zero') === '1';
    }

    public function requestsEnabled(): bool
    {
        return $this->get('module.requests') !== '0';
    }

    public function cartEnabled(): bool
    {
        return $this->requestsEnabled();
    }

    public function clientsEnabled(): bool
    {
        return $this->get('module.clients') !== '0';
    }

    public function chatEnabled(): bool
    {
        return $this->get('module.chat') !== '0';
    }

    public function notificationsEnabled(): bool
    {
        return $this->get('module.notifications') !== '0';
    }

    public function staffEnabled(): bool
    {
        return $this->get('module.staff') !== '0';
    }

    public function registrationEnabled(): bool
    {
        return $this->get('module.registration') !== '0';
    }

    public function stockEditEnabled(): bool
    {
        return $this->get('module.stock_edit') !== '0';
    }

    public function substitutionsEnabled(): bool
    {
        return $this->get('module.substitutions') !== '0';
    }

    public function managerAssignEnabled(): bool
    {
        return $this->get('module.manager_assign') !== '0';
    }

    public function warehousesEnabled(): bool
    {
        return $this->get('module.warehouses') !== '0';
    }

    public function reportsEnabled(): bool
    {
        return $this->get('module.reports') !== '0';
    }

    public function singleWarehouseName(): string
    {
        $name = trim((string) $this->get('stocks.single_name'));

        if ($name === '' || preg_match('/^\?+(?:\s+\?+)*$/', $name) === 1) {
            return 'Основной склад';
        }

        return $name;
    }

    public function appTitle(): string
    {
        $title = trim((string) $this->get('branding.title'));

        return $title !== '' ? $title : 'CRM7';
    }

    public function appDescription(): string
    {
        return trim((string) $this->get('branding.description'));
    }

    public function legalRequisites(): array
    {
        return [
            'organization' => trim((string) $this->get('branding.organization')),
            'legal_address' => trim((string) $this->get('branding.legal_address')),
            'requisites' => trim((string) $this->get('branding.requisites')),
            'contact_email' => trim((string) $this->get('branding.contact_email')),
            'contact_phone' => trim((string) $this->get('branding.contact_phone')),
            'responsible' => trim((string) $this->get('branding.responsible')),
        ];
    }

    public function photoRatio(): string
    {
        $ratio = (string) $this->get('stocks.photo_ratio');

        return in_array($ratio, ['dynamic', 'square', 'landscape', 'portrait'], true) ? $ratio : 'square';
    }

    public function photoFit(): string
    {
        return $this->get('stocks.photo_fit') === 'cover' ? 'cover' : 'contain';
    }

    /**
     * Размеры вариантов фото по длинной стороне (px), нормализованные: max >= card >= preview.
     *
     * @return array{preview: int, card: int, max: int}
     */
    public function photoSizes(): array
    {
        $preview = $this->photoSize('stocks.photo_size_preview', 160);
        $card = max($this->photoSize('stocks.photo_size_card', 600), $preview);
        $max = max($this->photoSize('stocks.photo_size_max', 1600), $card);

        return ['preview' => $preview, 'card' => $card, 'max' => $max];
    }

    private function photoSize(string $key, int $default): int
    {
        $value = (int) $this->get($key);

        return $value >= 80 && $value <= 4000 ? $value : $default;
    }
}
