<?php

declare(strict_types=1);

function connectDatabase(string $prefix = ''): PDO
{
    $settings = [];
    foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $name) {
        $value = getenv($prefix . $name);
        if ($value === false || $value === '') {
            throw new RuntimeException('Configuração de banco incompleta.');
        }
        $settings[$name] = $value;
    }

    // Evitar que valores de configuração introduzam parâmetros extras no DSN.
    foreach (['DB_HOST', 'DB_NAME'] as $name) {
        if (preg_match('/[;\s]/', $settings[$name])) {
            throw new RuntimeException('Configuração de banco inválida.');
        }
    }
    if (!ctype_digit($settings['DB_PORT'])) {
        throw new RuntimeException('Porta de banco inválida.');
    }

    return new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s;connect_timeout=5',
            $settings['DB_HOST'], $settings['DB_PORT'], $settings['DB_NAME']),
        $settings['DB_USER'],
        $settings['DB_PASSWORD'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
    );
}

function storeTemperature(PDO $pdo, string $sensor, int|float $temperature, ?array $metadata = null): array
{
    $values = array_fill_keys(['device_id', 'measurement_id', 'measured_at', 'time_quality', 'wifi_rssi', 'uptime_ms', 'observation'], null);
    if ($metadata !== null) {
        $values = array_replace($values, $metadata);
    }
    $values['sensor_id'] = $sensor;
    // JSON mantém o ponto decimal independentemente do locale do processo.
    $values['temperature'] = json_encode($temperature, JSON_THROW_ON_ERROR);
    $statement = $pdo->prepare(
        'INSERT INTO temperature_readings
         (sensor_id, temperature, device_id, measurement_id, measured_at, time_quality, wifi_rssi, uptime_ms, observation)
         VALUES (:sensor_id, :temperature, :device_id, :measurement_id, :measured_at, :time_quality, :wifi_rssi, :uptime_ms, :observation)
         ON CONFLICT (device_id, measurement_id) DO NOTHING RETURNING *',
    );
    $statement->execute($values);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if ($row !== false) {
        return ['reading' => temperatureReading($row), 'created' => true];
    }
    // Uma nova instrução vê o registro após a resolução do conflito concorrente.
    $existing = $pdo->prepare('SELECT * FROM temperature_readings WHERE device_id = :device_id AND measurement_id = :measurement_id');
    $existing->execute(['device_id' => $values['device_id'], 'measurement_id' => $values['measurement_id']]);
    $row = $existing->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        throw new RuntimeException('Medição existente indisponível após conflito.');
    }
    $reading = temperatureReading($row);
    $expected = $metadata;
    $expected['sensor_id'] = $sensor;
    $expected['temperature'] = (float) $temperature;
    foreach ($expected as $field => $value) {
        if ($reading[$field] !== $value) {
            throw new DomainException('Conteúdo diferente para a mesma medição.');
        }
    }
    return ['reading' => $reading, 'created' => false];
}

function listTemperatures(PDO $pdo, ?int $beforeId = null): array
{
    $where = $beforeId === null ? '' : ' WHERE (received_at, id) <
        (SELECT received_at, id FROM temperature_readings WHERE id = :before_id)';
    $statement = $pdo->prepare(
        'SELECT id, sensor_id, temperature, received_at, device_id, measurement_id,
                measured_at, time_quality, wifi_rssi, uptime_ms, observation FROM temperature_readings'
        . $where . ' ORDER BY received_at DESC, id DESC LIMIT 50',
    );
    $statement->execute($beforeId === null ? [] : ['before_id' => $beforeId]);

    return array_map('temperatureReading', $statement->fetchAll(PDO::FETCH_ASSOC));
}

function temperatureReading(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'sensor_id' => $row['sensor_id'],
        'temperature' => (float) $row['temperature'],
        'received_at' => (new DateTimeImmutable($row['received_at']))
            ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
        'device_id' => $row['device_id'],
        'measurement_id' => $row['measurement_id'],
        'measured_at' => $row['measured_at'] === null ? null : (new DateTimeImmutable($row['measured_at']))
            ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
        'time_quality' => $row['time_quality'],
        'wifi_rssi' => $row['wifi_rssi'] === null ? null : (int) $row['wifi_rssi'],
        'uptime_ms' => $row['uptime_ms'] === null ? null : (int) $row['uptime_ms'],
        'observation' => $row['observation'],
    ];
}
