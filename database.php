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

function storeTemperature(PDO $pdo, string $sensor, int|float $temperature): array
{
    $statement = $pdo->prepare(
        'INSERT INTO temperature_readings (sensor_id, temperature) VALUES (:sensor, :temperature)
         RETURNING id, sensor_id, temperature, received_at',
    );
    $statement->execute([
        'sensor' => $sensor,
        // JSON mantém o ponto decimal independentemente do locale do processo.
        'temperature' => json_encode($temperature, JSON_THROW_ON_ERROR),
    ]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return [
        'id' => (int) $row['id'],
        'sensor_id' => $row['sensor_id'],
        'temperature' => (float) $row['temperature'],
        'received_at' => (new DateTimeImmutable($row['received_at']))
            ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
    ];
}
