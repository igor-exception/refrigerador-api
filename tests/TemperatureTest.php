<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/application.php';
require_once dirname(__DIR__) . '/database.php';

final class TemperatureTest extends TestCase
{
    private const TOKEN = 'test-only-token';

    public static function sensors(): array
    {
        return [['freezer', -18.2], ['refrigerator', 4], ['freezer', 0]];
    }

    #[DataProvider('sensors')]
    public function testValidReading(string $sensor, int|float $temperature): void
    {
        $calls = [];
        $app = createApplication(self::TOKEN, static function ($id, $value) use (&$calls): array {
            $calls[] = [$id, $value];
            return ['id' => 1, 'sensor_id' => $id, 'temperature' => $value, 'received_at' => '2026-10-14T12:00:00Z'];
        });
        $response = $app->handle(self::request(json_encode(
            ['sensor_id' => $sensor, 'temperature' => $temperature], JSON_THROW_ON_ERROR,
        )));
        self::assertSame(201, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($sensor, $data['sensor_id']);
        self::assertEquals($temperature, $data['temperature']);
        self::assertSame(1, $data['id']);
        self::assertArrayHasKey('received_at', $data);
        self::assertSame([[$sensor, $temperature]], $calls);
    }

    public static function invalidBodies(): array
    {
        return [
            ['{', 400], ['', 400], ['[]', 422], ['null', 422], ['true', 422], ['12', 422],
            ['"text"', 422], ['{}', 422], ['{"sensor_id":"freezer"}', 422],
            ['{"temperature":4}', 422], ['{"sensor_id":"unknown","temperature":4}', 422],
            ['{"sensor_id":null,"temperature":4}', 422],
            ['{"sensor_id":["freezer"],"temperature":4}', 422],
            ['{"sensor_id":"freezer","temperature":"4"}', 422],
            ['{"sensor_id":"freezer","temperature":true}', 422],
            ['{"sensor_id":"freezer","temperature":null}', 422],
            ['{"sensor_id":"freezer","temperature":[]}', 422],
            ['{"sensor_id":"freezer","temperature":{}}', 422],
            ['{"sensor_id":"freezer","temperature":1e400}', 422],
            ['{"sensor_id":"freezer","temperature":-1e400}', 422],
        ];
    }

    #[DataProvider('invalidBodies')]
    public function testInvalidInputNeverCallsPersistence(string $body, int $status): void
    {
        $app = createApplication(self::TOKEN, function (): never {
            self::fail('Entrada inválida tentou persistir.');
        });
        $response = $app->handle(self::request($body));
        self::assertSame($status, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertArrayHasKey('error', json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR));
    }

    public static function invalidTokens(): array
    {
        return [[''], ['Bearer wrong-token'], ['Basic test-only-token'], ['Bearer'], ['Bearer test-only-token extra']];
    }

    #[DataProvider('invalidTokens')]
    public function testAuthenticationPrecedesPersistence(string $authorization): void
    {
        $app = createApplication(self::TOKEN, function (): never {
            self::fail('Requisição não autorizada tentou persistir.');
        });
        $response = $app->handle(self::request('{"sensor_id":"freezer","temperature":-18.2}', $authorization));
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Bearer', $response->getHeaderLine('WWW-Authenticate'));
        self::assertStringNotContainsString(self::TOKEN, (string) $response->getBody());
    }

    public function testMissingServerTokenDisablesWrites(): void
    {
        $app = createApplication('', function (): never { self::fail('Token não configurado.'); });
        self::assertSame(503, $app->handle(self::request('{}'))->getStatusCode());
        self::assertSame(200, $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/health'))->getStatusCode());
    }

    public function testRealConnectionFailureReturnsSafeResponse(): void
    {
        $app = createApplication(self::TOKEN, static function (): never {
            // Porta inválida conhecida: não depende de um servidor disponível.
            new PDO('pgsql:host=127.0.0.1;port=0;dbname=unavailable;connect_timeout=1', 'test-user', 'test-secret');
            throw new RuntimeException('Conexão inesperada.');
        });
        $response = $app->handle(self::request('{"sensor_id":"freezer","temperature":-18.2}'));
        self::assertSame(503, $response->getStatusCode());
        self::assertSame(['error' => 'Serviço de gravação indisponível.'],
            json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR));
    }

    public static function request(string $body, string $authorization = 'Bearer test-only-token'): Psr\Http\Message\ServerRequestInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/api/v1/temperaturas')
            ->withHeader('Content-Type', 'application/json');
        $request->getBody()->write($body);
        return $request->withHeader('Authorization', $authorization);
    }
}
