<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/application.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/dashboard.php';

return createApplication(
    getenv('API_WRITE_TOKEN') ?: '',
    static fn (string $sensor, int|float $temperature, ?array $metadata): array =>
        storeTemperature(connectDatabase(), $sensor, $temperature, $metadata),
    static fn (?int $beforeId = null): array => listTemperatures(connectDatabase(), $beforeId),
    static fn (string $sensor): array => dashboardHeatmap(connectDatabase(), $sensor),
);
