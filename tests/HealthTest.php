<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class HealthTest extends TestCase
{
    public function testHealthReturnsOkJson(): void
    {
        $app = require dirname(__DIR__) . '/app.php';
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/health');

        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame(
            ['status' => 'ok'],
            json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testUnknownRouteReturnsNotFoundJson(): void
    {
        $app = require dirname(__DIR__) . '/app.php';
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/missing');

        $response = $app->handle($request);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertJson((string) $response->getBody());
    }

    public function testUnexpectedErrorDoesNotExposeInternalDetails(): void
    {
        $app = require dirname(__DIR__) . '/app.php';
        $app->get('/failure', function (): never {
            throw new RuntimeException('internal-sensitive-marker');
        });
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/failure');

        $response = $app->handle($request);
        $body = (string) $response->getBody();

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertJson($body);
        self::assertStringNotContainsString('internal-sensitive-marker', $body);
        self::assertStringNotContainsString('RuntimeException', $body);
        self::assertStringNotContainsString('trace', strtolower($body));
    }
}
