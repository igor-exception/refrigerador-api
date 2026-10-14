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

    private function queryResponse(): Psr\Http\Message\ResponseInterface
    {
        $app = createApplication('', static fn (): array => [], fn (): array => listTemperatures($this->pdo));
        return $app->handle((new Slim\Psr7\Factory\ServerRequestFactory())
            ->createServerRequest('GET', '/api/v1/temperaturas'));
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
        $reading = storeTemperature($this->pdo, 'refrigerator', 4.8);
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
    }

    public function testQuerySqlFailureReturnsSafeResponse(): void
    {
        // Simula estrutura indisponível somente na tabela temporária desta conexão.
        $this->pdo->exec('CREATE TEMP TABLE temperature_readings (id BIGINT) ON COMMIT DROP');
        $response = $this->queryResponse();
        self::assertSame(503, $response->getStatusCode());
        self::assertSame(['error' => 'Serviço de consulta indisponível.'], json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR));
    }

    private function request(string $body): Psr\Http\Message\ServerRequestInterface
    {
        $request = (new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/api/v1/temperaturas')
            ->withHeader('Content-Type', 'application/json')->withHeader('Authorization', 'Bearer test-only-token');
        $request->getBody()->write($body);
        return $request;
    }
}
