<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/database.php';
require_once dirname(__DIR__) . '/migrations.php';

try {
    $mode = $argv[1] ?? '';
    if (!in_array($mode, ['status', 'migrate', 'baseline'], true)) {
        throw new RuntimeException('Use composer db:status, db:migrate ou db:baseline.');
    }
    $test = false;
    $confirmation = null;
    foreach (array_slice($argv, 2) as $arg) {
        if ($arg === '--test' && !$test) {
            $test = true;
        } elseif (str_starts_with($arg, '--confirm=') && $confirmation === null) {
            $confirmation = substr($arg, 10);
        } else {
            throw new RuntimeException('Argumento inválido. Use --test e, no baseline, --confirm=NOME_DO_BANCO.');
        }
    }
    $root = dirname(__DIR__);
    // O Compose resolve .env e precedência do terminal. Nunca encaminhar suas saídas.
    $process = proc_open(['docker', 'compose', 'config', '--format', 'json'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    if (!is_resource($process)) {
        throw new RuntimeException('Não foi possível ler Docker Compose.');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('Confira Docker Compose e a configuração local.');
    }
    $compose = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    unset($output);
    $service = $compose['services']['postgres'] ?? [];
    $environment = $service['environment'] ?? [];
    $port = null;
    foreach ($service['ports'] ?? [] as $mapping) {
        if ((string) ($mapping['target'] ?? '') === '5432') {
            $port = (string) ($mapping['published'] ?? '');
            break;
        }
    }
    // DB_* locais podem estar no .env mesmo sem pertencer ao serviço postgres.
    $process = proc_open(['docker', 'compose', 'config', '--environment'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    if (!is_resource($process)) {
        throw new RuntimeException('Não foi possível resolver configuração local.');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('Falha ao resolver configuração local.');
    }
    $resolved = [];
    foreach (explode("\n", $output) as $line) {
        $pair = explode('=', rtrim($line, "\r"), 2);
        if (count($pair) === 2) {
            $resolved[$pair[0]] = $pair[1];
        }
    }
    unset($output);
    $database = $test ? (getenv('TEST_DB_NAME') ?: 'refrigerador_test') : 'refrigerador';
    if ($test && (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,62}$/D', $database)
        || !str_ends_with($database, '_test')
        || strcasecmp($database, $environment['POSTGRES_DB'] ?? 'refrigerador') === 0
        || strcasecmp($database, getenv('DB_NAME') ?: 'refrigerador') === 0)) {
        throw new RuntimeException('Banco de testes deve ser isolado e terminar em _test.');
    }
    if ($mode === 'baseline' && $confirmation !== $database) {
        throw new RuntimeException('Baseline exige confirmação explícita: --confirm=' . $database);
    }
    $defaults = ['HOST' => '127.0.0.1', 'PORT' => $port,
        'USER' => $environment['POSTGRES_USER'] ?? '', 'PASSWORD' => $environment['POSTGRES_PASSWORD'] ?? ''];
    foreach ($defaults as $suffix => $fallback) {
        $value = ($test ? getenv('TEST_DB_' . $suffix) : false)
            ?: ($resolved['DB_' . $suffix] ?? $fallback);
        putenv('MIGRATION_DB_' . $suffix . '=' . (string) $value);
    }
    putenv('MIGRATION_DB_NAME=' . $database);
    $pdo = connectDatabase('MIGRATION_');
    if ($pdo->query('SELECT current_database()')->fetchColumn() !== $database) {
        throw new RuntimeException('Conexão selecionou banco inesperado.');
    }
    $files = migrationFiles($root . '/sql');
    echo 'Banco: ' . $database . PHP_EOL;
    if ($mode === 'status') {
        $pdo->beginTransaction();
        $pdo->exec('SET TRANSACTION READ ONLY');
        $status = migrationStatus($pdo, $files);
        $pdo->rollBack();
    } else {
        $status = runMigrations($pdo, $files, $mode);
    }
    foreach ($status as $row) {
        echo $row['filename'] . ': ' . $row['status']
            . ($row['applied_at'] === null ? '' : ' em ' . $row['applied_at'] . ' (' . $row['method'] . ')') . PHP_EOL;
    }
} catch (PDOException | JsonException) {
    fwrite(STDERR, 'ERRO: Falha ao acessar configuração/PostgreSQL ou executar SQL. Nenhum detalhe sensível foi registrado. Confira o destino e o arquivo pendente.' . PHP_EOL);
    exit(1);
} catch (RuntimeException $exception) {
    fwrite(STDERR, 'ERRO: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
