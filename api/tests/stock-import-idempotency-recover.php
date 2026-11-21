<?php

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Core\Config;
use App\Repositories\StockImportIdempotencyRepository;

Config::load(__DIR__ . '/../config/.env');

$action = (string) ($argv[1] ?? 'recover');
$operationId = (int) ($argv[2] ?? 0);

if ($operationId <= 0) {
    fwrite(STDERR, "usage: stock-import-idempotency-recover.php <recover|forcefail> <operation_id>\n");
    exit(2);
}

$repository = new StockImportIdempotencyRepository();

try {
    $result = $action === 'forcefail'
        ? ['ok' => $repository->forceFail($operationId)]
        : $repository->recover($operationId);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception) . ': ' . $exception->getMessage() . "\n");
    exit(1);
}
