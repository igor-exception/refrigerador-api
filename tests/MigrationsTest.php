<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/database.php';
require_once dirname(__DIR__) . '/migrations.php';

final class MigrationsTest extends TestCase
{
    private PDO $pdo;
    private string $schema;
    private array $files;

    protected function setUp(): void
    {
        if (getenv('TEST_DB_NAME') !== 'refrigerador_test') {
            self::markTestSkipped('Migrações exigem exclusivamente refrigerador_test.');
        }
        $this->pdo = connectDatabase('TEST_');
        self::assertSame('refrigerador_test', $this->pdo->query('SELECT current_database()')->fetchColumn());
        $this->schema = 'migration_test_' . bin2hex(random_bytes(8));
        $this->pdo->exec('CREATE SCHEMA ' . migrationIdentifier($this->schema));
        $this->files = migrationFiles(dirname(__DIR__) . '/sql');
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo, $this->schema)) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            // Somente o esquema exclusivo criado por este teste, no banco isolado.
            $this->pdo->exec('DROP SCHEMA ' . migrationIdentifier($this->schema) . ' CASCADE');
        }
    }

    private function manualSchema(): void
    {
        $this->pdo->exec('SET search_path TO ' . migrationIdentifier($this->schema) . ', pg_catalog');
        foreach ($this->files as $file) {
            $this->pdo->exec($file['sql']);
        }
    }

    private function third(string $sql): array
    {
        return [...$this->files, '003' => ['version' => '003', 'filename' => '003_test.sql',
            'checksum' => hash('sha256', $sql), 'sql' => $sql]];
    }

    public function testEmptyInstallOrderAndSecondExecution(): void
    {
        $before = migrationStatus($this->pdo, $this->files, $this->schema);
        self::assertSame(['pendente', 'pendente'], array_column($before, 'status'));
        self::assertSame([], migrationSchemaSignature($this->pdo, $this->schema));
        self::assertNull($this->pdo->query("SELECT to_regclass('{$this->schema}.schema_migrations')")->fetchColumn());
        $first = runMigrations($this->pdo, $this->files, schema: $this->schema);
        self::assertSame(['001', '002'], array_column($first, 'version'));
        self::assertSame(['aplicada', 'aplicada'], array_column($first, 'status'));
        self::assertSame($first, runMigrations($this->pdo, $this->files, schema: $this->schema));
        self::assertCount(11, migrationSchemaSignature($this->pdo, $this->schema)[0]['columns']);
        self::assertSame('temperature_readings', migrationSchemaSignature($this->pdo, $this->schema)[0]['relname']);
        self::assertCount(3, migrationSchemaSignature($this->pdo, $this->schema)[0]['indexes']);
    }

    public function testPendingMigrationAndSafeRecovery(): void
    {
        runMigrations($this->pdo, $this->files, schema: $this->schema);
        $broken = $this->third('CREATE TABLE attempt (id INT); SELECT missing_column FROM attempt;');
        self::assertSame('pendente', migrationStatus($this->pdo, $broken, $this->schema)[2]['status']);
        try {
            runMigrations($this->pdo, $broken, schema: $this->schema);
            self::fail('SQL inválido foi aceito.');
        } catch (PDOException) {
            self::assertSame('pendente', migrationStatus($this->pdo, $broken, $this->schema)[2]['status']);
            self::assertNull($this->pdo->query("SELECT to_regclass('{$this->schema}.attempt')")->fetchColumn());
        }
        $fixed = $this->third('CREATE TABLE attempt (id INT)');
        self::assertSame('aplicada', runMigrations($this->pdo, $fixed, schema: $this->schema)[2]['status']);
    }

    public function testChangedAppliedFileIsRejected(): void
    {
        runMigrations($this->pdo, $this->files, schema: $this->schema);
        $this->files['001']['checksum'] = str_repeat('0', 64);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Histórico divergente');
        runMigrations($this->pdo, $this->files, schema: $this->schema);
    }

    public function testRemovedAppliedFileIsRejected(): void
    {
        runMigrations($this->pdo, $this->files, schema: $this->schema);
        unset($this->files['002']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ausente');
        migrationStatus($this->pdo, $this->files, $this->schema);
    }

    public function testBaselinePreservesHistoryAndEquivalentSchema(): void
    {
        $this->manualSchema();
        $this->pdo->exec("INSERT INTO temperature_readings (sensor_id,temperature,received_at) VALUES ('freezer',-18.2,'2026-01-01T00:00:00Z')");
        $before = $this->pdo->query('SELECT * FROM temperature_readings')->fetchAll(PDO::FETCH_ASSOC);
        $signature = migrationSchemaSignature($this->pdo, $this->schema);
        $status = runMigrations($this->pdo, $this->files, 'baseline', $this->schema);
        self::assertSame(['baseline', 'baseline'], array_column($status, 'method'));
        self::assertSame($before, $this->pdo->query('SELECT * FROM temperature_readings')->fetchAll(PDO::FETCH_ASSOC));
        self::assertSame($signature, migrationSchemaSignature($this->pdo, $this->schema));
        self::assertSame($status, runMigrations($this->pdo, $this->files, schema: $this->schema));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('já existe histórico');
        runMigrations($this->pdo, $this->files, 'baseline', $this->schema);
    }

    public static function divergences(): array
    {
        return [
            ['ALTER TABLE temperature_readings DROP CONSTRAINT measurement_metadata_valid'],
            ['DROP INDEX temperature_readings_device_measurement_uidx'],
            ['DROP INDEX temperature_readings_received_at_id_idx'],
            ['DROP INDEX temperature_readings_received_at_id_idx; CREATE INDEX temperature_readings_received_at_id_idx ON temperature_readings (received_at, id)'],
            ['ALTER TABLE temperature_readings ALTER COLUMN received_at DROP DEFAULT'],
            ['ALTER TABLE temperature_readings ALTER COLUMN sensor_id DROP NOT NULL'],
            ['ALTER TABLE temperature_readings ADD COLUMN unexpected TEXT'],
            ['ALTER TABLE temperature_readings ALTER COLUMN device_id TYPE VARCHAR(65)'],
            ['ALTER TABLE temperature_readings ENABLE ROW LEVEL SECURITY'],
            ['ALTER SEQUENCE temperature_readings_id_seq INCREMENT BY 2'],
        ];
    }

    #[DataProvider('divergences')]
    public function testDivergentBaselineIsRejectedWithoutHistory(string $sql): void
    {
        $this->manualSchema();
        $this->pdo->exec($sql);
        try {
            runMigrations($this->pdo, $this->files, 'baseline', $this->schema);
            self::fail('Baseline divergente foi aceito.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('esquema divergente', $exception->getMessage());
            self::assertSame([], migrationHistory($this->pdo, $this->schema));
        }
    }

    public function testExistingSchemaIsNeverSilentlyAdopted(): void
    {
        $this->manualSchema();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Esquema existente sem histórico');
        runMigrations($this->pdo, $this->files, schema: $this->schema);
    }

    public function testConcurrentRunnerIsRefusedAndLockReleased(): void
    {
        $other = connectDatabase('TEST_');
        $other->query('SELECT pg_advisory_lock(724193,1)');
        try {
            runMigrations($this->pdo, $this->files, schema: $this->schema);
            self::fail('Execução concorrente aceita.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Outro migrador', $exception->getMessage());
        } finally {
            $other->query('SELECT pg_advisory_unlock(724193,1)');
        }
        self::assertSame('aplicada', runMigrations($this->pdo, $this->files, schema: $this->schema)[1]['status']);
    }

    public function testOnlyInitialMigrationCannotBeBaselinedAsComplete(): void
    {
        $this->pdo->exec('SET search_path TO ' . migrationIdentifier($this->schema) . ', pg_catalog');
        $this->pdo->exec($this->files['001']['sql']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('esquema divergente');
        runMigrations($this->pdo, $this->files, 'baseline', $this->schema);
    }

    public function testBaselineDoesNotMarkFutureMigrationAsApplied(): void
    {
        $this->manualSchema();
        $files = $this->third('CREATE TABLE future (id INT)');
        $status = runMigrations($this->pdo, $files, 'baseline', $this->schema);
        self::assertSame(['aplicada', 'aplicada', 'pendente'], array_column($status, 'status'));
        self::assertSame('aplicada', runMigrations($this->pdo, $files, schema: $this->schema)[2]['status']);
    }

    public function testLineEndingsDoNotChangeChecksumAndDuplicateVersionIsRejected(): void
    {
        $directory = sys_get_temp_dir() . '/refrigerador_migrations_' . bin2hex(random_bytes(8)) . '.tmp';
        mkdir($directory);
        try {
            file_put_contents($directory . '/002_second.sql', "SELECT 2;\n");
            file_put_contents($directory . '/001_first.sql', "SELECT 1;\r\n");
            $files = migrationFiles($directory);
            self::assertSame(['001', '002'], array_keys($files));
            file_put_contents($directory . '/001_first.sql', "SELECT 1;\n");
            self::assertSame($files, migrationFiles($directory));
            file_put_contents($directory . '/001_duplicate.sql', 'SELECT 3;');
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('duplicado');
            migrationFiles($directory);
        } finally {
            foreach (['001_first.sql', '001_duplicate.sql', '002_second.sql'] as $name) {
                if (is_file($directory . '/' . $name)) {
                    unlink($directory . '/' . $name);
                }
            }
            rmdir($directory);
        }
    }
}
