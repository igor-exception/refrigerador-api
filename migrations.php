<?php

declare(strict_types=1);

// SQL versionado é a fonte da verdade; nenhuma dependência adicional.
function migrationFiles(string $directory): array
{
    $files = [];
    foreach (glob($directory . '/*.sql') ?: [] as $path) {
        $name = basename($path);
        if (!preg_match('/^([0-9]{3})_[a-z0-9_]+\.sql$/D', $name, $match) || isset($files[$match[1]])) {
            throw new RuntimeException('Nome ou versão de migração inválido/duplicado.');
        }
        $sql = file_get_contents($path);
        if ($sql === false || trim($sql) === '') {
            throw new RuntimeException('Migração vazia ou ilegível.');
        }
        // Normalizar apenas finais de linha para checkout Windows/Linux.
        $sql = str_replace("\r\n", "\n", $sql);
        $files[$match[1]] = ['version' => $match[1], 'filename' => $name,
            'checksum' => hash('sha256', $sql), 'sql' => $sql];
    }
    ksort($files, SORT_STRING);
    if (!$files) {
        throw new RuntimeException('Nenhuma migração encontrada.');
    }
    return $files;
}

function migrationIdentifier(string $name): string
{
    if (!preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $name)) {
        throw new RuntimeException('Identificador de esquema inválido.');
    }
    return '"' . $name . '"';
}

function migrationHistory(PDO $pdo, string $schema): array
{
    $table = migrationIdentifier($schema) . '.schema_migrations';
    $exists = $pdo->prepare('SELECT to_regclass(:name)');
    $exists->execute(['name' => $table]);
    if ($exists->fetchColumn() === null) {
        return [];
    }
    return $pdo->query("SELECT version, filename, checksum, applied_at, method FROM $table ORDER BY version")
        ->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
}

function migrationStatus(PDO $pdo, array $files, string $schema = 'public'): array
{
    $history = migrationHistory($pdo, $schema);
    $pendingSeen = false;
    $status = [];
    foreach ($files as $version => $file) {
        $row = $history[$version] ?? null;
        if ($row !== null && ($row['filename'] !== $file['filename'] || $row['checksum'] !== $file['checksum'] || $pendingSeen)) {
            throw new RuntimeException('Histórico divergente: arquivo alterado ou versões fora de ordem.');
        }
        $pendingSeen = $pendingSeen || $row === null;
        $status[] = ['version' => $file['version'], 'filename' => $file['filename'],
            'status' => $row === null ? 'pendente' : 'aplicada', 'applied_at' => $row['applied_at'] ?? null,
            'method' => $row['method'] ?? null];
        unset($history[$version]);
    }
    if ($history) {
        throw new RuntimeException('Histórico contém migração ausente no diretório SQL.');
    }
    return $status;
}

function migrationCreateHistory(PDO $pdo, string $schema): void
{
    $table = migrationIdentifier($schema) . '.schema_migrations';
    $pdo->exec("CREATE TABLE IF NOT EXISTS $table (
        version VARCHAR(3) PRIMARY KEY, filename TEXT NOT NULL UNIQUE,
        checksum CHAR(64) NOT NULL, applied_at TIMESTAMPTZ NOT NULL DEFAULT clock_timestamp(),
        method TEXT NOT NULL CHECK (method IN ('migration', 'baseline'))
    )");
}

function migrationRecord(PDO $pdo, string $schema, array $file, string $method): void
{
    $statement = $pdo->prepare('INSERT INTO ' . migrationIdentifier($schema)
        . '.schema_migrations (version, filename, checksum, method) VALUES (:version, :filename, :checksum, :method)');
    $statement->execute(['version' => $file['version'], 'filename' => $file['filename'],
        'checksum' => $file['checksum'], 'method' => $method]);
}

// Assinatura estrutural conservadora: divergência, mesmo equivalente, exige revisão humana.
function migrationSchemaSignature(PDO $pdo, string $schema): array
{
    $statement = $pdo->prepare("SELECT c.oid, c.relname, c.relkind, c.relpersistence, c.relrowsecurity, c.relforcerowsecurity
        FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
        WHERE n.nspname = :schema AND c.relkind IN ('r','p','v','m','f') AND c.relname <> 'schema_migrations'
        ORDER BY c.relname");
    $statement->execute(['schema' => $schema]);
    $result = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $table) {
        $oid = (int) $table['oid'];
        unset($table['oid']);
        $table['columns'] = $pdo->query("SELECT a.attname, format_type(a.atttypid,a.atttypmod) AS type,
            a.attnotnull, a.attidentity, a.attgenerated, a.attcollation,
            pg_get_expr(d.adbin,d.adrelid) AS default_value
            FROM pg_attribute a LEFT JOIN pg_attrdef d ON d.adrelid=a.attrelid AND d.adnum=a.attnum
            WHERE a.attrelid=$oid AND a.attnum>0 AND NOT a.attisdropped ORDER BY a.attnum")->fetchAll(PDO::FETCH_ASSOC);
        $table['constraints'] = $pdo->query("SELECT conname, contype, convalidated, condeferrable, condeferred,
            pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conrelid=$oid ORDER BY conname")
            ->fetchAll(PDO::FETCH_ASSOC);
        $table['indexes'] = $pdo->query("SELECT ci.relname, am.amname, i.indisunique, i.indisprimary,
            i.indisvalid, i.indisready, i.indnullsnotdistinct, i.indnkeyatts,
            i.indkey::text, i.indoption::text, i.indclass::text, i.indcollation::text,
            ARRAY(SELECT pg_get_indexdef(i.indexrelid,k,true) FROM generate_series(1,i.indnatts) k) AS keys,
            pg_get_expr(i.indpred,i.indrelid) AS predicate
            FROM pg_index i JOIN pg_class ci ON ci.oid=i.indexrelid JOIN pg_am am ON am.oid=ci.relam
            WHERE i.indrelid=$oid ORDER BY ci.relname")->fetchAll(PDO::FETCH_ASSOC);
        $table['sequence'] = $pdo->query("SELECT s.seqtypid, s.seqstart, s.seqincrement, s.seqmax, s.seqmin, s.seqcache, s.seqcycle
            FROM pg_sequence s JOIN pg_depend d ON d.objid=s.seqrelid AND d.classid='pg_class'::regclass
            WHERE d.refobjid=$oid AND d.deptype='i'")->fetchAll(PDO::FETCH_ASSOC);
        $table['extras'] = (int) $pdo->query("SELECT
            (SELECT count(*) FROM pg_trigger WHERE tgrelid=$oid AND NOT tgisinternal)
            + (SELECT count(*) FROM pg_policy WHERE polrelid=$oid)
            + (SELECT count(*) FROM pg_inherits WHERE inhrelid=$oid OR inhparent=$oid)
            + (SELECT count(*) FROM pg_rewrite WHERE ev_class=$oid)")->fetchColumn();
        $result[] = $table;
    }
    return $result;
}

function migrationVerifyBaseline(PDO $pdo, array $files, string $schema): void
{
    // Esta adoção reconhece somente o histórico manual conhecido 001+002.
    $baseline = array_intersect_key($files, array_flip(['001', '002']));
    if (count($baseline) !== 2) {
        throw new RuntimeException('Baseline exige os arquivos históricos 001 e 002.');
    }
    $pdo->exec('LOCK TABLE ' . migrationIdentifier($schema) . '.temperature_readings IN ACCESS EXCLUSIVE MODE');
    $actual = migrationSchemaSignature($pdo, $schema);
    $reference = 'migration_reference_' . bin2hex(random_bytes(8));
    $pdo->exec('SAVEPOINT baseline_reference');
    $pdo->exec('CREATE SCHEMA ' . migrationIdentifier($reference));
    $pdo->exec('SET LOCAL search_path TO ' . migrationIdentifier($reference) . ', pg_catalog');
    foreach ($baseline as $file) {
        $pdo->exec($file['sql']);
    }
    $expected = migrationSchemaSignature($pdo, $reference);
    $pdo->exec('ROLLBACK TO SAVEPOINT baseline_reference');
    $pdo->exec('RELEASE SAVEPOINT baseline_reference');
    if ($actual !== $expected) {
        throw new RuntimeException('Baseline recusado: esquema divergente de 001+002. Revisão humana necessária.');
    }
}

function runMigrations(PDO $pdo, array $files, string $mode = 'migrate', string $schema = 'public'): array
{
    if (!in_array($mode, ['migrate', 'baseline'], true) || $pdo->inTransaction()) {
        throw new RuntimeException('Modo ou transação do migrador inválido.');
    }
    $quotedSchema = migrationIdentifier($schema);
    // Trava de sessão cobre todas as transações individuais e é liberada ao desconectar.
    $lock = $pdo->query('SELECT pg_try_advisory_lock(724193, 1)')->fetchColumn();
    if (!$lock) {
        throw new RuntimeException('Outro migrador está em execução neste banco. Tente novamente depois.');
    }
    try {
        $pdo->beginTransaction();
        $pdo->exec("SET LOCAL search_path TO $quotedSchema, pg_catalog");
        $pdo->exec("SET LOCAL lock_timeout = '5s'");
        $status = migrationStatus($pdo, $files, $schema);
        $history = migrationHistory($pdo, $schema);
        $existing = migrationSchemaSignature($pdo, $schema);
        if ($mode === 'baseline') {
            if ($history) {
                throw new RuntimeException('Baseline recusado: já existe histórico de versões.');
            }
            migrationVerifyBaseline($pdo, $files, $schema);
            migrationCreateHistory($pdo, $schema);
            foreach ($files as $file) {
                if (in_array($file['version'], ['001', '002'], true)) {
                    migrationRecord($pdo, $schema, $file, 'baseline');
                }
            }
            $pdo->commit();
            return migrationStatus($pdo, $files, $schema);
        }
        if (!$history && $existing) {
            throw new RuntimeException('Esquema existente sem histórico. Execute baseline deliberadamente após revisão e backup.');
        }
        migrationCreateHistory($pdo, $schema);
        $pdo->commit();
        foreach ($status as $row) {
            if ($row['status'] === 'aplicada') {
                continue;
            }
            $file = $files[$row['version']];
            $pdo->beginTransaction();
            $pdo->exec("SET LOCAL search_path TO $quotedSchema, pg_catalog");
            $pdo->exec("SET LOCAL lock_timeout = '5s'");
            $pdo->exec($file['sql']);
            migrationRecord($pdo, $schema, $file, 'migration');
            $pdo->commit();
        }
        return migrationStatus($pdo, $files, $schema);
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $pdo->query('SELECT pg_advisory_unlock(724193, 1)');
    }
}
