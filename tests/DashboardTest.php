<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

require_once dirname(__DIR__) . '/application.php';
require_once dirname(__DIR__) . '/dashboard.php';

final class DashboardTest extends TestCase
{
    private ?PDO $pdo = null;

    private function database(): PDO
    {
        if (getenv('TEST_DB_NAME') !== 'refrigerador_test') {
            self::markTestSkipped('Dashboard exige refrigerador_test.');
        }
        $this->pdo = connectDatabase('TEST_');
        self::assertSame('refrigerador_test', $this->pdo->query('SELECT current_database()')->fetchColumn());
        $this->pdo->beginTransaction();
        $this->pdo->exec('CREATE TEMP TABLE temperature_readings (LIKE public.temperature_readings INCLUDING ALL) ON COMMIT DROP');
        return $this->pdo;
    }

    protected function tearDown(): void
    {
        if ($this->pdo?->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-10-15T12:00:05Z');
    }

    private function reading(float $temperature, ?string $measured, string $received, ?string $quality = 'synced', string $sensor = 'refrigerator'): int
    {
        $metadata = $quality === null ? null : ['device_id' => 'test-device', 'measurement_id' => bin2hex(random_bytes(8)),
            'measured_at' => $measured, 'time_quality' => $quality, 'wifi_rssi' => -70, 'uptime_ms' => 10000, 'observation' => null];
        $row = storeTemperature($this->pdo, $sensor, $temperature, $metadata)['reading'];
        $statement = $this->pdo->prepare('UPDATE temperature_readings SET received_at=:received WHERE id=:id');
        $statement->execute(['received' => $received, 'id' => $row['id']]);
        return $row['id'];
    }

    public function testEmptyHasFourChronologicalWindowsOfSixtyCells(): void
    {
        $data = dashboardHeatmap($this->database(), now: $this->now());
        self::assertNull($data['latest']);
        self::assertSame(['10m','30m','1h','5h'], array_column($data['windows'], 'id'));
        self::assertSame([10,30,60,300], array_column($data['windows'], 'bucket_seconds'));
        foreach ($data['windows'] as $window) {
            self::assertCount(60, $window['cells']);
            $previous = null;
            foreach ($window['cells'] as $cell) {
                self::assertSame(0, $cell['count']);
                self::assertNull($cell['average']);
                self::assertNull($cell['last_reading']);
                self::assertSame($window['bucket_seconds'], strtotime($cell['end']) - strtotime($cell['start']));
                if ($previous !== null) self::assertSame($previous, $cell['start']);
                $previous = $cell['end'];
            }
        }
    }

    public function testAggregatesSyncedEstimatedAndUsesLatestIndividualMetadata(): void
    {
        $pdo = $this->database();
        $this->reading(20,'2026-10-15T11:59:41Z','2026-10-15T11:59:43Z');
        $last = $this->reading(22,'2026-10-15T11:59:48Z','2026-10-15T11:59:49Z','estimated');
        $data = dashboardHeatmap($pdo, now: $this->now());
        $cell = $data['windows'][0]['cells'][57];
        self::assertSame(2,$cell['count']);
        self::assertSame(21.0,$cell['average']);
        self::assertSame(20.0,$cell['minimum']);
        self::assertSame(22.0,$cell['maximum']);
        self::assertSame($last,$cell['last_reading']['id']);
        self::assertSame(22.0,$cell['last_reading']['temperature']);
        self::assertSame('estimated',$cell['last_reading']['time_quality']);
        self::assertSame('measured_at',$cell['last_reading']['time_source']);
        self::assertSame(0,$cell['received_at_count']);
        $app = createApplication('',static fn()=>[],heatmap:fn($sensor)=>dashboardHeatmap($pdo,$sensor,$this->now()));
        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET','/api/v1/dashboard/heatmap'));
        self::assertSame(200,$response->getStatusCode());
        self::assertSame(json_encode($data,JSON_THROW_ON_ERROR),(string)$response->getBody());
    }

    public function testUnknownLegacyDelayedAndBoundariesAreNeverDoubleCounted(): void
    {
        $pdo = $this->database();
        $this->reading(18,'2026-10-15T11:59:40Z','2026-10-15T12:00:02Z');
        $this->reading(19,null,'2026-10-15T11:59:50Z','unknown');
        $legacy = $this->reading(20,null,'2026-10-15T12:00:00Z',null);
        $data = dashboardHeatmap($pdo, now: $this->now());
        foreach ($data['windows'] as $window) self::assertSame(3,array_sum(array_column($window['cells'],'count')));
        $cells = $data['windows'][0]['cells'];
        self::assertSame(1,$cells[57]['count']);
        self::assertSame(1,$cells[58]['count']);
        self::assertSame(1,$cells[59]['count']);
        self::assertSame('received_at',$cells[58]['last_reading']['time_source']);
        self::assertSame('unknown',$cells[58]['last_reading']['time_quality']);
        self::assertSame($legacy,$cells[59]['last_reading']['id']);
        self::assertNull($cells[59]['last_reading']['device_id']);
        self::assertNull($cells[59]['last_reading']['measured_at']);
        self::assertSame(1,$cells[59]['received_at_count']);
        self::assertSame('2026-10-15T12:00:02.000000Z',$data['latest']['received_at']);
        self::assertSame('2026-10-15T11:59:40.000000Z',$data['latest']['measured_at']);
    }

    public function testFutureInconsistentAndOldTimesDoNotPolluteHeatmap(): void
    {
        $pdo = $this->database();
        $id = $this->reading(24,'2026-10-15T12:05:00Z','2026-10-15T12:00:04Z');
        $this->reading(25,'2026-10-15T11:59:55Z','2026-10-15T11:00:00Z');
        $this->reading(26,'2026-10-15T01:00:00Z','2026-10-15T12:00:03Z');
        $this->reading(27,null,'2026-10-15T12:01:00Z','unknown');
        $data = dashboardHeatmap($pdo, now: $this->now());
        self::assertSame(3,$data['excluded_temporal_count']);
        foreach ($data['windows'] as $window) self::assertSame(0,array_sum(array_column($window['cells'],'count')));
        self::assertTrue($data['latest']['temporal_warning']);
        self::assertSame(4,(int)$pdo->query('SELECT count(*) FROM temperature_readings')->fetchColumn());
        self::assertSame($id,(int)$pdo->query('SELECT id FROM temperature_readings WHERE temperature=24')->fetchColumn());
    }

    public function testSensorIsolationAndStaleLatestIsStillReturned(): void
    {
        $pdo = $this->database();
        $this->reading(21,null,'2026-10-14T12:00:00Z',null);
        $this->reading(-18,null,'2026-10-15T12:00:00Z',null,'freezer');
        $data = dashboardHeatmap($pdo, now: $this->now());
        self::assertSame(21.0,$data['latest']['temperature']);
        self::assertSame(0,array_sum(array_column($data['windows'][3]['cells'],'count')));
        $freezer = dashboardHeatmap($pdo,'freezer',$this->now());
        self::assertSame(-18.0,$freezer['latest']['temperature']);
        self::assertSame(1,array_sum(array_column($freezer['windows'][0]['cells'],'count')));
    }

    public function testOldestBoundaryIncludedAndJustBeforeBoundaryExcluded(): void
    {
        $pdo = $this->database();
        $this->reading(19,'2026-10-15T11:50:09.999999Z','2026-10-15T11:50:10Z');
        $this->reading(20,'2026-10-15T11:50:10Z','2026-10-15T11:50:11Z');
        $this->reading(21,'2026-10-15T12:00:05Z','2026-10-15T12:00:05Z');
        $data = dashboardHeatmap($pdo,now:$this->now());
        self::assertSame(1,$data['windows'][0]['cells'][0]['count']);
        self::assertSame(20.0,$data['windows'][0]['cells'][0]['average']);
        self::assertSame(2,array_sum(array_column($data['windows'][0]['cells'],'count')));
        self::assertSame(3,array_sum(array_column($data['windows'][1]['cells'],'count')));
    }

    public function testHttpIsPublicAndExistingRoutesRemainAvailable(): void
    {
        $app = createApplication('test-only-token',static fn()=>[],static fn()=>[],static fn($sensor)=>['sensor_id'=>$sensor,'windows'=>[]]);
        $request = new ServerRequestFactory();
        foreach (['/dashboard','/assets/dashboard.css','/assets/dashboard.js','/health','/api/v1/temperaturas','/api/v1/dashboard/heatmap'] as $path) {
            self::assertSame(200,$app->handle($request->createServerRequest('GET',$path))->getStatusCode());
        }
        self::assertSame(401,$app->handle($request->createServerRequest('POST','/api/v1/temperaturas'))->getStatusCode());
        $response = $app->handle($request->createServerRequest('GET','/api/v1/dashboard/heatmap')->withQueryParams(['sensor_id'=>'freezer']));
        self::assertSame('application/json',$response->getHeaderLine('Content-Type'));
        self::assertSame('no-store',$response->getHeaderLine('Cache-Control'));
        self::assertSame('freezer',json_decode((string)$response->getBody(),true)['sensor_id']);
    }

    public function testInvalidSensorAndDatabaseErrorsNeverReturnFakeCellsOrSecrets(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET','/api/v1/dashboard/heatmap');
        $app = createApplication('',static fn()=>[],heatmap:static function():never { throw new PDOException('private SQL and credentials'); });
        foreach (['invalid',['refrigerator']] as $sensor) {
            self::assertSame(422,$app->handle($request->withQueryParams(['sensor_id'=>$sensor]))->getStatusCode());
        }
        $response = $app->handle($request);
        self::assertSame(503,$response->getStatusCode());
        self::assertSame(['error'=>'Consulta do dashboard indisponível.'],json_decode((string)$response->getBody(),true));
    }
}
