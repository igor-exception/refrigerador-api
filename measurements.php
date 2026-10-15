<?php

declare(strict_types=1);

// Normaliza somente o contrato enriquecido. null identifica o contrato legado.
function measurementMetadata(stdClass $data): ?array
{
    $fields = ['device_id', 'measurement_id', 'measured_at', 'time_quality', 'wifi_rssi', 'uptime_ms', 'observation'];
    $values = get_object_vars($data);
    if (!array_intersect($fields, array_keys($values))) {
        return null;
    }
    foreach (array_diff($fields, ['observation']) as $field) {
        if (!array_key_exists($field, $values)) {
            throw new InvalidArgumentException('Informe todos os metadados obrigatórios da medição.');
        }
    }
    if (array_diff(array_keys($values), [...$fields, 'sensor_id', 'temperature'])) {
        throw new InvalidArgumentException('Campo não permitido no contrato enriquecido; received_at pertence ao servidor.');
    }
    foreach (['device_id' => 64, 'measurement_id' => 128] as $field => $length) {
        if (!is_string($values[$field])
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,' . ($length - 1) . '}$/D', $values[$field])) {
            throw new InvalidArgumentException('Identificador de dispositivo ou medição inválido.');
        }
    }
    if (!in_array($values['time_quality'], ['synced', 'estimated', 'unknown'], true)) {
        throw new InvalidArgumentException('time_quality deve ser synced, estimated ou unknown.');
    }
    if ($values['time_quality'] === 'unknown') {
        if ($values['measured_at'] !== null) {
            throw new InvalidArgumentException('measured_at deve ser null quando time_quality é unknown.');
        }
    } else {
        $values['measured_at'] = normalizeMeasurementTime($values['measured_at']);
    }
    if ($values['wifi_rssi'] !== null && (!is_int($values['wifi_rssi'])
        || $values['wifi_rssi'] < -127 || $values['wifi_rssi'] > 0)) {
        throw new InvalidArgumentException('wifi_rssi deve ser null ou inteiro entre -127 e 0 dBm.');
    }
    if (!is_int($values['uptime_ms']) || $values['uptime_ms'] < 0 || $values['uptime_ms'] > 9007199254740991) {
        throw new InvalidArgumentException('uptime_ms deve ser inteiro entre 0 e 9007199254740991.');
    }
    $values['observation'] ??= null;
    if ($values['observation'] !== null && (!is_string($values['observation'])
        || str_contains($values['observation'], "\0")
        || preg_match('/\A.{0,1024}\z/us', $values['observation']) !== 1)) {
        throw new InvalidArgumentException('observation deve ser null ou texto de até 1024 caracteres, sem NUL.');
    }
    return array_intersect_key($values, array_flip($fields));
}

function normalizeMeasurementTime(mixed $value): string
{
    if (!is_string($value) || !preg_match(
        '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d{1,6}))?(?:Z|\+00:00)$/D', $value, $parts,
    ) || str_starts_with($value, '0000-')) {
        throw new InvalidArgumentException('measured_at deve ser ISO 8601 UTC com Z ou +00:00 e até 6 casas decimais.');
    }
    $normalized = $parts[1] . '.' . str_pad($parts[2] ?? '', 6, '0') . '+00:00';
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.uP', $normalized);
    $errors = DateTimeImmutable::getLastErrors();
    if ($date === false || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) {
        throw new InvalidArgumentException('measured_at contém uma data ou horário inválido.');
    }
    return $date->format('Y-m-d\TH:i:s.u\Z');
}
