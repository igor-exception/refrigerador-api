<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';

function dashboardReading(array $row, DateTimeImmutable $now): array
{
    $reading = temperatureReading($row);
    $useMeasured = $row['measured_at'] !== null && in_array($row['time_quality'], ['synced', 'estimated'], true);
    $reading['time_source'] = $useMeasured ? 'measured_at' : 'received_at';
    $effective = new DateTimeImmutable($useMeasured ? $row['measured_at'] : $row['received_at']);
    $received = new DateTimeImmutable($row['received_at']);
    $reading['temporal_warning'] = $effective > $now || ($useMeasured && $effective > $received->modify('+60 seconds'));
    return $reading;
}

function dashboardHeatmap(PDO $pdo, string $sensor = 'refrigerator', ?DateTimeImmutable $now = null): array
{
    $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $utc = static fn (string $value): string => (new DateTimeImmutable($value))
        ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
    // Uma consulta: quatro agrupamentos PostgreSQL e 240 células, sem consultas por célula.
    $sql = <<<'SQL'
WITH config AS (SELECT CAST(:now AS timestamptz) AS now, CAST(:sensor AS text) AS sensor),
windows(name, step) AS (VALUES ('10m',10),('30m',30),('1h',60),('5h',300)),
bounds AS (
    SELECT w.*, to_timestamp(floor(extract(epoch FROM c.now)/w.step)*w.step) AS current_start
    FROM windows w CROSS JOIN config c
),
candidate AS MATERIALIZED (
    SELECT t.*, CASE WHEN measured_at IS NOT NULL AND time_quality IN ('synced','estimated')
        THEN measured_at ELSE received_at END AS effective_at,
        CASE WHEN measured_at IS NOT NULL AND time_quality IN ('synced','estimated')
        THEN 'measured_at' ELSE 'received_at' END AS time_source
    FROM temperature_readings t CROSS JOIN config c
    WHERE sensor_id=c.sensor AND (received_at >= c.now-interval '5 hours' OR measured_at >= c.now-interval '5 hours')
),
checked AS MATERIALIZED (
    SELECT t.*, (NOT isfinite(effective_at) OR effective_at > c.now
        OR (time_source='measured_at' AND measured_at > received_at+interval '60 seconds')) AS invalid_time
    FROM candidate t CROSS JOIN config c
),
grouped AS (
    SELECT w.name, floor(extract(epoch FROM effective_at)/w.step)*w.step AS epoch,
        avg(temperature) AS average, min(temperature) AS minimum, max(temperature) AS maximum,
        count(*) AS count, count(*) FILTER (WHERE time_source='received_at') AS received_count,
        (array_agg(id ORDER BY effective_at DESC, received_at DESC, id DESC))[1] AS last_id
    FROM checked t CROSS JOIN bounds w
    WHERE NOT invalid_time AND effective_at >= w.current_start-((59*w.step)*interval '1 second')
        AND effective_at < w.current_start+(w.step*interval '1 second')
    GROUP BY w.name, floor(extract(epoch FROM effective_at)/w.step)*w.step
),
latest AS (
    SELECT to_jsonb(t) AS reading FROM temperature_readings t CROSS JOIN config c
    WHERE t.sensor_id=c.sensor ORDER BY received_at DESC,id DESC LIMIT 1
)
SELECT w.name,w.step,n AS position,
    w.current_start+((n-59)*w.step)*interval '1 second' AS start,
    w.current_start+((n-58)*w.step)*interval '1 second' AS end,
    g.average,g.minimum,g.maximum,coalesce(g.count,0) AS count,coalesce(g.received_count,0) AS received_count,
    to_jsonb(last_reading) AS last_reading,
    (SELECT reading FROM latest) AS latest,
    (SELECT count(*) FROM checked WHERE invalid_time) AS excluded
FROM bounds w CROSS JOIN generate_series(0,59) n
LEFT JOIN grouped g ON g.name=w.name AND g.epoch=extract(epoch FROM w.current_start)+(n-59)*w.step
LEFT JOIN temperature_readings last_reading ON last_reading.id=g.last_id
ORDER BY w.step,n
SQL;
    $statement = $pdo->prepare($sql);
    $statement->execute(['now' => $now->format('Y-m-d\TH:i:s.uP'), 'sensor' => $sensor]);
    $result = ['generated_at' => $utc($now->format('Y-m-d\TH:i:s.uP')), 'sensor_id' => $sensor,
        'timezone' => 'America/Sao_Paulo', 'latest' => null, 'excluded_temporal_count' => 0, 'windows' => []];
    $windows = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $name = $row['name'];
        $windows[$name] ??= ['id' => $name, 'bucket_seconds' => (int) $row['step'], 'cells' => []];
        $windows[$name]['cells'][] = ['start' => $utc($row['start']), 'end' => $utc($row['end']),
            'average' => $row['average'] === null ? null : (float) $row['average'],
            'minimum' => $row['minimum'] === null ? null : (float) $row['minimum'],
            'maximum' => $row['maximum'] === null ? null : (float) $row['maximum'],
            'count' => (int) $row['count'], 'received_at_count' => (int) $row['received_count'],
            'last_reading' => $row['last_reading'] === null ? null : dashboardReading(json_decode($row['last_reading'], true, 512, JSON_THROW_ON_ERROR), $now)];
        $result['excluded_temporal_count'] = (int) $row['excluded'];
        if ($result['latest'] === null && $row['latest'] !== null) {
            $result['latest'] = dashboardReading(json_decode($row['latest'], true, 512, JSON_THROW_ON_ERROR), $now);
        }
    }
    $result['windows'] = array_values($windows);
    return $result;
}
