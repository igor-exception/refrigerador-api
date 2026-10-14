<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/application.php';
require_once __DIR__ . '/database.php';

return createApplication(
    getenv('API_WRITE_TOKEN') ?: '',
    static fn (string $sensor, int|float $temperature): array =>
        storeTemperature(connectDatabase(), $sensor, $temperature),
    static fn (): array => listTemperatures(connectDatabase()),
);
