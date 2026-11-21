<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Core\Config;
use App\Repositories\StockImportIdempotencyRepository;

Config::load(__DIR__ . '/../config/.env');

$ttl = (int) (getenv('CRM_IDEMPOTENCY_TTL_HOURS') ?: 24);
$limit = (int) (getenv('CRM_IDEMPOTENCY_CLEANUP_LIMIT') ?: 100);

echo (new StockImportIdempotencyRepository())->cleanup($limit, $ttl) . "\n";
