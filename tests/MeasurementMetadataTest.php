<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/application.php';

final class MeasurementMetadataTest extends TestCase
{
    public static function reading(): array
    {
        return ['sensor_id' => 'refrigerator', 'temperature' => 4.8, 'device_id' => 'geladeira_01',
            'measurement_id' => 'test-boot-1', 'measured_at' => '2026-10-14T12:00:00Z',
            'time_quality' => 'synced', 'wifi_rssi' => -65, 'uptime_ms' => 10000, 'observation' => null];
    }

    public static function validMetadata(): array
    {
        return [
            'synced' => [[]],
            'estimated' => [['time_quality' => 'estimated', 'measured_at' => '2026-10-14T12:00:00.123456+00:00']],
            'unknown' => [['time_quality' => 'unknown', 'measured_at' => null]],
            'rssi absent' => [['wifi_rssi' => null]],
            'rssi lower bound' => [['wifi_rssi' => -127]],
            'rssi upper bound' => [['wifi_rssi' => 0]],
            'uptime zero' => [['uptime_ms' => 0]],
            'uptime max' => [['uptime_ms' => 9007199254740991]],
            'observation' => [['observation' => "Porta aberta\npor 5 minutos"]],
            'empty observation' => [['observation' => '']],
            'unicode limit' => [['observation' => str_repeat('ç', 1024)]],
        ];
    }

    #[DataProvider('validMetadata')]
    public function testEnrichedRequestPassesNormalizedMetadata(array $override): void
    {
        $input = array_replace(self::reading(), $override);
        $metadata = null;
        $app = createApplication('test-only-token', static function ($sensor, $temperature, $values) use (&$metadata): array {
            $metadata = $values;
            return ['reading' => ['id' => 1, 'sensor_id' => $sensor, 'temperature' => $temperature, ...$values], 'created' => true];
        });
        $response = $app->handle(self::request($input));
        self::assertSame(201, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame($input['device_id'], $metadata['device_id']);
        self::assertSame($input['time_quality'], $metadata['time_quality']);
        self::assertSame($input['wifi_rssi'], $metadata['wifi_rssi']);
        self::assertSame($input['uptime_ms'], $metadata['uptime_ms']);
        self::assertSame($input['observation'], $metadata['observation']);
        self::assertSame($input['measured_at'] === null ? null : normalizeMeasurementTime($input['measured_at']), $metadata['measured_at']);
    }

    public function testObservationCanBeOmitted(): void
    {
        $input = self::reading();
        unset($input['observation']);
        self::assertNull(measurementMetadata((object) $input)['observation']);
    }

    public static function invalidMetadata(): array
    {
        $cases = [
            ['device_id', null], ['device_id', ''], ['device_id', 'a;b'], ['device_id', str_repeat('a', 65)],
            ['device_id', 'á'], ['device_id', "bad\n"], ['measurement_id', ''], ['measurement_id', 1],
            ['measurement_id', str_repeat('x', 129)], ['measurement_id', "foo\0bar"],
            ['time_quality', 'invalid'], ['time_quality', null], ['time_quality', []],
            ['measured_at', null], ['measured_at', 123], ['measured_at', '2026-02-30T12:00:00Z'],
            ['measured_at', '2026-10-14'], ['measured_at', '2026-10-14T12:00:00-03:00'],
            ['measured_at', '2026-10-14T24:00:00Z'], ['measured_at', '2026-10-14T12:00:60Z'],
            ['measured_at', '2026-10-14T12:00:00.1234567Z'], ['measured_at', '0000-01-01T00:00:00Z'],
            ['wifi_rssi', -128], ['wifi_rssi', 1], ['wifi_rssi', -65.5], ['wifi_rssi', '-65'], ['wifi_rssi', true],
            ['uptime_ms', -1], ['uptime_ms', null], ['uptime_ms', 1.5], ['uptime_ms', '10000'],
            ['uptime_ms', 9007199254740992], ['uptime_ms', true],
            ['observation', 12], ['observation', []], ['observation', str_repeat('é', 1025)],
            ['observation', "abc\0def"], ['temperature', '4.8'], ['temperature', null],
        ];
        return array_map(static fn ($case) => [array_replace(self::reading(), [$case[0] => $case[1]])], $cases);
    }

    #[DataProvider('invalidMetadata')]
    public function testInvalidEnrichedRequestNeverCallsPersistence(array $input): void
    {
        $app = createApplication('test-only-token', function (): never { self::fail('Dados inválidos chegaram à persistência.'); });
        $response = $app->handle(self::request($input));
        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('error', json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR));
    }

    public function testUnknownCannotContainAbsoluteTime(): void
    {
        $input = array_replace(self::reading(), ['time_quality' => 'unknown']);
        $app = createApplication('test-only-token', function (): never { self::fail('Timestamp fictício aceito.'); });
        self::assertSame(422, $app->handle(self::request($input))->getStatusCode());
    }

    public static function requiredFields(): array
    {
        return array_map(static fn ($field) => [$field], ['device_id', 'measurement_id', 'measured_at', 'time_quality', 'wifi_rssi', 'uptime_ms']);
    }

    #[DataProvider('requiredFields')]
    public function testPartialMetadataIsRejected(string $missing): void
    {
        $input = self::reading();
        unset($input[$missing]);
        $app = createApplication('test-only-token', function (): never { self::fail('Metadados parciais aceitos.'); });
        self::assertSame(422, $app->handle(self::request($input))->getStatusCode());
    }

    public function testServerTimestampAndUnknownFieldsAreRejectedInNewContract(): void
    {
        $app = createApplication('test-only-token', function (): never { self::fail('Campo desconhecido aceito.'); });
        foreach (['received_at', 'unexpected'] as $field) {
            self::assertSame(422, $app->handle(self::request([...self::reading(), $field => 'value']))->getStatusCode());
        }
    }

    public function testNewContractStillRequiresBearerToken(): void
    {
        $app = createApplication('test-only-token', function (): never { self::fail('Escrita pública.'); });
        $response = $app->handle(self::request(self::reading())->withoutHeader('Authorization'));
        self::assertSame(401, $response->getStatusCode());
    }

    public static function request(array $body): Psr\Http\Message\ServerRequestInterface
    {
        $request = (new Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('POST', '/api/v1/temperaturas')
            ->withHeader('Authorization', 'Bearer test-only-token')->withHeader('Content-Type', 'application/json');
        $request->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));
        return $request;
    }
}
