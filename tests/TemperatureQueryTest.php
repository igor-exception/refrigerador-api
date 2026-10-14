<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

require_once dirname(__DIR__) . '/application.php';

final class TemperatureQueryTest extends TestCase
{
    public function testQueryIsPublicAndPostStillRequiresToken(): void
    {
        $readings = [['id' => 1, 'sensor_id' => 'freezer', 'temperature' => -18.2,
            'received_at' => '2026-10-14T12:00:00.000000Z']];
        $app = createApplication('test-only-token', function (): never {
            self::fail('POST sem token tentou gravar.');
        }, static fn (): array => $readings);
        $factory = new ServerRequestFactory();
        $response = $app->handle($factory->createServerRequest('GET', '/api/v1/temperaturas'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(['data' => $readings], json_decode((string) $response->getBody(), true));
        self::assertSame(401, $app->handle($factory->createServerRequest('POST', '/api/v1/temperaturas'))->getStatusCode());
    }

    public function testEmptyQueryWorksWithoutServerToken(): void
    {
        $app = createApplication('', static fn (): array => [], static fn (): array => []);
        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/api/v1/temperaturas'));
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"data":[]}', (string) $response->getBody());
    }

    public function testConfigurationFailureReturnsSafeResponse(): void
    {
        $app = createApplication('', static fn (): array => [], static function (): never {
            throw new RuntimeException('password=secret SELECT internal configuration');
        });
        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/api/v1/temperaturas'));
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(['error' => 'Serviço de consulta indisponível.'], json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testRealConnectionFailureReturnsSafeResponse(): void
    {
        $app = createApplication('', static fn (): array => [], static function (): never {
            new PDO('pgsql:host=127.0.0.1;port=0;dbname=unavailable;connect_timeout=1', 'test-user', 'test-secret');
            throw new RuntimeException('Conexão inesperada.');
        });
        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/api/v1/temperaturas'));
        self::assertSame(503, $response->getStatusCode());
        self::assertSame(['error' => 'Serviço de consulta indisponível.'], json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR));
    }
}
