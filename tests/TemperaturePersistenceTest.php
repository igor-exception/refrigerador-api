<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/application.php';
require_once dirname(__DIR__) . '/database.php';

final class TemperaturePersistenceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        foreach (['HOST', 'PORT', 'NAME', 'USER', 'PASSWORD'] as $key) {
            if (!getenv('TEST_DB_' . $key)) {
                self::markTestSkipped('Configure TEST_DB_* e prepare um banco de testes isolado.');
            }
        }
        $name = getenv('TEST_DB_NAME');
        if (!str_ends_with($name, '_test') || $name === (getenv('DB_NAME') ?: 'refrigerador')) {
            self::fail('Banco de integração deve terminar em _test e ser diferente do desenvolvimento.');
        }
        $this->pdo = connectDatabase('TEST_');
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public static function sensors(): array
    {
        return [['freezer', -18.2], ['refrigerator', 4.8]];
    }

    #[DataProvider('sensors')]
    public function testReadingIsStored(string $sensor, float $temperature): void
    {
        $before = (int) $this->pdo->query('SELECT count(*) FROM temperature_readings')->fetchColumn();
        $app = createApplication('test-only-token', fn ($id, $value) => storeTemperature($this->pdo, $id, $value));
        $response = $app->handle($this->request(json_encode(
            ['sensor_id' => $sensor, 'temperature' => $temperature], JSON_THROW_ON_ERROR,
        )));
        self::assertSame(201, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $statement = $this->pdo->prepare('SELECT sensor_id, temperature, received_at FROM temperature_readings WHERE id = :id');
        $statement->execute(['id' => $data['id']]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertSame($sensor, $row['sensor_id']);
        self::assertSame($temperature, (float) $row['temperature']);
        self::assertEquals(new DateTimeImmutable($row['received_at']), new DateTimeImmutable($data['received_at']));
        self::assertLessThan(10, abs(time() - (new DateTimeImmutable($data['received_at']))->getTimestamp()));
        self::assertSame($before + 1, (int) $this->pdo->query('SELECT count(*) FROM temperature_readings')->fetchColumn());
    }

    public function testInvalidReadingDoesNotInsert(): void
    {
        $before = (int) $this->pdo->query('SELECT count(*) FROM temperature_readings')->fetchColumn();
        $app = createApplication('test-only-token', fn ($id, $value) => storeTemperature($this->pdo, $id, $value));
        $response = $app->handle($this->request('{"sensor_id":"freezer","temperature":"invalid"}'));
        self::assertSame(422, $response->getStatusCode());
        self::assertSame($before, (int) $this->pdo->query('SELECT count(*) FROM temperature_readings')->fetchColumn());
    }

    private function queryResponse(?int $beforeId = null): Psr\Http\Message\ResponseInterface
    {
        $app = createApplication('', static fn (): array => [], fn ($cursor): array => listTemperatures($this->pdo, $cursor));
        return $app->handle((new Slim\Psr7\Factory\ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/temperaturas')->withQueryParams($beforeId === null ? [] : ['before_id' => (string) $beforeId]));
    }

    private function isolateQueryTable(): void
    {
        // Tabela temporária da conexão: preserva todos os registros existentes.
        // O rollback remove a tabela; nenhuma alteração no esquema persistente.
        $this->pdo->exec('CREATE TEMP TABLE temperature_readings
            (LIKE public.temperature_readings INCLUDING ALL) ON COMMIT DROP');
    }

    public function testQueryWithoutRecords(): void
    {
        $this->isolateQueryTable();
        $response = $this->queryResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"data":[]}', (string) $response->getBody());
    }

    public function testQueryReturnsStoredReading(): void
    {
        $this->isolateQueryTable();
        $reading = storeTemperature($this->pdo, 'refrigerator', 4.8)['reading'];
        $response = $this->queryResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['data' => [$reading]], json_decode((string) $response->getBody(), true));
    }

    public function testQueryLimitsAndOrdersByTimestampThenId(): void
    {
        $this->isolateQueryTable();
        $insert = $this->pdo->prepare('INSERT INTO temperature_readings (sensor_id, temperature, received_at)
            VALUES (:sensor, :temperature, :received_at) RETURNING id');
        $expected = [];
        for ($i = 0; $i < 55; $i++) {
            // Inserções alternadas: ordenar somente por ID não pode passar.
            $timestamp = $i % 2 === 0 ? '2026-10-14T12:00:00Z' : '2026-10-14T11:00:00Z';
            $insert->execute(['sensor' => 'freezer', 'temperature' => -18.2, 'received_at' => $timestamp]);
            $expected[] = ['id' => (int) $insert->fetchColumn(), 'timestamp' => $timestamp];
        }
        usort($expected, static fn (array $a, array $b): int =>
            strcmp($b['timestamp'], $a['timestamp']) ?: $b['id'] <=> $a['id']);
        $response = $this->queryResponse();
        $data = json_decode((string) $response->getBody(), true)['data'];
        self::assertSame(200, $response->getStatusCode());
        self::assertCount(50, $data);
        self::assertSame(array_column(array_slice($expected, 0, 50), 'id'), array_column($data, 'id'));
        $secondPage = json_decode((string) $this->queryResponse($data[49]['id'])->getBody(), true)['data'];
        self::assertCount(5, $secondPage);
        self::assertSame(array_column(array_slice($expected, 50), 'id'), array_column($secondPage, 'id'));
        self::assertSame([], json_decode((string) $this->queryResponse($secondPage[4]['id'])->getBody(), true)['data']);
    }

    public function testQuerySqlFailureReturnsSafeResponse(): void
    {
        // Simula estrutura indisponível somente na tabela temporária desta conexão.
        $this->pdo->exec('CREATE TEMP TABLE temperature_readings (id BIGINT) ON COMMIT DROP');
        $response = $this->queryResponse();
        self::assertSame(503, $response->getStatusCode());
        self::assertSame(['error' => 'Serviço de consulta indisponível.'], json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR));
    }

    private function enrichedReading(): array
    {
        return ['device_id' => 'geladeira_01', 'sensor_id' => 'refrigerator', 'measurement_id' => 'boot-1',
            'temperature' => 4.8, 'measured_at' => '2026-10-14T12:00:00Z', 'time_quality' => 'synced',
            'wifi_rssi' => -65, 'uptime_ms' => 10000, 'observation' => null];
    }

    private function postReading(array $input): Psr\Http\Message\ResponseInterface
    {
        $app = createApplication('test-only-token', fn ($sensor, $value, $metadata) =>
            storeTemperature($this->pdo, $sensor, $value, $metadata));
        return $app->handle($this->request(json_encode($input, JSON_THROW_ON_ERROR)));
    }

    public static function qualities(): array
    {
        return [['synced', '2026-10-14T12:00:00Z'], ['estimated', '2026-10-14T12:00:00.123456Z'], ['unknown', null]];
    }

    #[DataProvider('qualities')]
    public function testAllMetadataPersistsAndAppearsInGet(string $quality, ?string $measuredAt): void
    {
        $this->isolateQueryTable();
        $input = array_replace($this->enrichedReading(), ['time_quality' => $quality, 'measured_at' => $measuredAt,
            'observation' => "Porta aberta\npor 5 minutos"]);
        $response = $this->postReading($input);
        self::assertSame(201, $response->getStatusCode());
        $reading = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        foreach ($input as $field => $value) {
            self::assertSame($field === 'measured_at' && $value !== null ? normalizeMeasurementTime($value) : $value, $reading[$field]);
        }
        self::assertIsInt($reading['id']);
        self::assertIsFloat($reading['temperature']);
        self::assertLessThan(10, abs(time() - (new DateTimeImmutable($reading['received_at']))->getTimestamp()));
        self::assertSame([$reading], json_decode((string) $this->queryResponse()->getBody(), true)['data']);
    }

    #[DataProvider('qualities')]
    public function testDuplicateReturnsOriginalRowWithoutAnotherInsert(string $quality, ?string $measuredAt): void
    {
        $this->isolateQueryTable();
        $input = array_replace($this->enrichedReading(), ['time_quality' => $quality, 'measured_at' => $measuredAt]);
        $first = $this->postReading($input);
        $second = $this->postReading([...$input, 'measured_at' => $measuredAt === null ? null : str_replace('Z', '+00:00', $measuredAt)]);
        self::assertSame(201, $first->getStatusCode());
        self::assertSame(200, $second->getStatusCode());
        self::assertSame((string) $first->getBody(), (string) $second->getBody());
        self::assertSame(1, (int) $this->pdo->query('SELECT count(*) FROM temperature_readings')->fetchColumn());
    }

    public function testBoundaryMetadataSurvivesDatabaseRoundTrip(): void
    {
        $this->isolateQueryTable();
        $input = [...$this->enrichedReading(), 'uptime_ms' => 9007199254740991,
            'wifi_rssi' => null, 'observation' => str_repeat('ç', 1024)];
        $response = $this->postReading($input);
        self::assertSame(201, $response->getStatusCode());
        $reading = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($input['uptime_ms'], $reading['uptime_ms']);
        self::assertSame($input['observation'], $reading['observation']);
        self::assertNull($reading['wifi_rssi']);
    }

    public static function conflictingFields(): array
    {
        return [['sensor_id', 'freezer'], ['temperature', 5.1], ['measured_at', '2026-10-14T12:00:01Z'],
            ['time_quality', 'estimated'], ['wifi_rssi', null], ['uptime_ms', 10001], ['observation', 'alterada']];
    }

    #[DataProvider('conflictingFields')]
    public function testConflictingReplayDoesNotOverwrite(string $field, mixed $value): void
    {
        $this->isolateQueryTable();
        $input = $this->enrichedReading();
        $first = $this->postReading($input);
        $conflict = $this->postReading([...$input, $field => $value]);
        self::assertSame(409, $conflict->getStatusCode());
        self::assertSame(1, (int) $this->pdo->query('SELECT count(*) FROM temperature_readings')->fetchColumn());
        self::assertSame([json_decode((string) $first->getBody(), true)], json_decode((string) $this->queryResponse()->getBody(), true)['data']);
    }

    public function testMeasurementIdentifierIsScopedToDevice(): void
    {
        $this->isolateQueryTable();
        self::assertSame(201, $this->postReading($this->enrichedReading())->getStatusCode());
        self::assertSame(201, $this->postReading([...$this->enrichedReading(), 'device_id' => 'geladeira_02'])->getStatusCode());
        self::assertSame(2, (int) $this->pdo->query('SELECT count(*) FROM temperature_readings')->fetchColumn());
    }

    public function testLegacyHasUnknownMetadataAndKeepsPreviousNonIdempotentBehavior(): void
    {
        $this->isolateQueryTable();
        foreach ([1, 2] as $iteration) {
            $response = $this->postReading(['sensor_id' => 'freezer', 'temperature' => -18.2]);
            self::assertSame(201, $response->getStatusCode());
            $reading = json_decode((string) $response->getBody(), true);
            foreach (['device_id', 'measurement_id', 'measured_at', 'time_quality', 'wifi_rssi', 'uptime_ms', 'observation'] as $field) {
                self::assertNull($reading[$field]);
            }
            self::assertSame($iteration, (int) $this->pdo->query('SELECT count(*) FROM temperature_readings')->fetchColumn());
        }
    }

    public function testMigrationPreservesHistoryAndCanRunTwice(): void
    {
        $schema = file_get_contents(dirname(__DIR__) . '/sql/001_temperature_readings.sql');
        $this->pdo->exec(str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TEMP TABLE', $schema));
        $this->pdo->exec("INSERT INTO temperature_readings (sensor_id, temperature, received_at)
            VALUES ('freezer', -18.2, '2026-01-01T12:00:00Z'), ('refrigerator', 4.8, '2026-01-02T12:00:00Z')");
        $before = $this->pdo->query('SELECT * FROM temperature_readings ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $migration = file_get_contents(dirname(__DIR__) . '/sql/002_measurement_metadata.sql');
        $this->pdo->exec($migration);
        $this->pdo->exec($migration);
        self::assertSame($before, $this->pdo->query('SELECT id, sensor_id, temperature, received_at FROM temperature_readings ORDER BY id')->fetchAll(PDO::FETCH_ASSOC));
        foreach (listTemperatures($this->pdo) as $row) {
            foreach (['device_id', 'measurement_id', 'measured_at', 'time_quality', 'wifi_rssi', 'uptime_ms', 'observation'] as $field) {
                self::assertNull($row[$field]);
            }
        }
        $newReading = $this->postReading($this->enrichedReading());
        self::assertSame(201, $newReading->getStatusCode());
        self::assertGreaterThan((int) $before[1]['id'], json_decode((string) $newReading->getBody(), true)['id']);
        self::assertSame(3, (int) $this->pdo->query('SELECT count(*) FROM temperature_readings')->fetchColumn());
    }

    public function testDatabaseRejectsInconsistentMetadata(): void
    {
        $this->isolateQueryTable();
        $this->expectException(PDOException::class);
        $this->pdo->exec("INSERT INTO temperature_readings (sensor_id, temperature, device_id)
            VALUES ('freezer', -18.2, 'geladeira_01')");
    }

    public function testUnknownHistoryCursorReturnsEmptyList(): void
    {
        $this->isolateQueryTable();
        $response = $this->queryResponse(PHP_INT_MAX);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"data":[]}', (string) $response->getBody());
    }

    private function request(string $body): Psr\Http\Message\ServerRequestInterface
    {
        $request = (new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/api/v1/temperaturas')
            ->withHeader('Content-Type', 'application/json')->withHeader('Authorization', 'Bearer test-only-token');
        $request->getBody()->write($body);
        return $request;
    }
}
