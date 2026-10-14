<?php

declare(strict_types=1);

/**
 * Prepara o PostgreSQL de testes sem alterar o banco principal.
 *
 * Uso: php scripts/setup-tests.php
 *      php scripts/setup-tests.php --run-tests
 *
 * Variáveis aceitas: DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD;
 * TEST_DB_HOST, TEST_DB_PORT, TEST_DB_NAME, TEST_DB_USER, TEST_DB_PASSWORD.
 * TEST_DB_* têm precedência quando definidos.
 *
 * Requer: PHP CLI, extensão pdo_pgsql, PostgreSQL acessível e privilégios
 * CREATE DATABASE no usuário informado se o banco não existir.
 * Usa docker compose config para resolver a configuração local sem exibi-la.
 */

function envValue(string $name, ?string $fallback = null): ?string
{
    $value = getenv($name);
    return $value === false || $value === '' ? $fallback : $value;
}

function fail(string $message): never
{
    fwrite(STDERR, "ERRO: {$message}" . PHP_EOL);
    exit(1);
}

function connectTo(string $database, array $config): PDO
{
    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s;connect_timeout=5',
        $config['host'],
        $config['port'],
        $database
    );

    return new PDO($dsn, $config['user'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);
}

function quoteIdentifier(string $identifier): string
{
    return '"' . str_replace('"', '""', $identifier) . '"';
}

if (PHP_SAPI !== 'cli') {
    fail('Execute este arquivo em um terminal (PHP CLI).');
}

$allowedArguments = ['--run-tests'];
$args = array_slice($argv, 1);
foreach ($args as $arg) {
    if (!in_array($arg, $allowedArguments, true)) {
        fail("Argumento desconhecido: {$arg}. Use --run-tests opcionalmente.");
    }
}

if (!extension_loaded('pdo_pgsql')) {
    fail('A extensão PHP pdo_pgsql não está habilitada.');
}

$root = dirname(__DIR__);
$sqlPath = $root . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . '001_temperature_readings.sql';

// Compose resolve o .env com suas próprias regras; não duplicar seu parser em PHP.
// Capturar toda a saída: ela contém a senha e nunca deve aparecer no terminal.
$process = proc_open(
    ['docker', 'compose', 'config', '--format', 'json'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $root,
);
if (!is_resource($process)) {
    fail('Não foi possível iniciar Docker Compose. Confira sua instalação.');
}
fclose($pipes[0]);
$output = stream_get_contents($pipes[1]);
fclose($pipes[1]);
stream_get_contents($pipes[2]);
fclose($pipes[2]);
if (proc_close($process) !== 0) {
    fail('Não foi possível ler a configuração do Compose. Confira Docker Compose e o .env local.');
}
$compose = json_decode($output, true);
unset($output);
$service = $compose['services']['postgres'] ?? [];
$local = $service['environment'] ?? [];
$publishedPort = null;
foreach ($service['ports'] ?? [] as $mapping) {
    if ((string) ($mapping['target'] ?? '') === '5432') {
        $publishedPort = (string) $mapping['published'];
        break;
    }
}
if (empty($local['POSTGRES_DB']) || empty($local['POSTGRES_USER']) || $publishedPort === null) {
    fail('Configuração do serviço postgres incompleta no Compose.');
}

$primaryDatabase = envValue('DB_NAME', $local['POSTGRES_DB']);
$testDatabase = envValue('TEST_DB_NAME', 'refrigerador_test');

if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,62}$/D', $testDatabase)) {
    fail('TEST_DB_NAME deve conter somente letras, números e underscore e ter até 63 caracteres.');
}
if (!str_ends_with($testDatabase, '_test')) {
    fail('Por segurança, o nome do banco de testes deve terminar em _test.');
}
if (strcasecmp($testDatabase, $primaryDatabase) === 0 || strcasecmp($testDatabase, $local['POSTGRES_DB']) === 0 || strcasecmp($testDatabase, 'refrigerador') === 0) {
    fail('O banco de testes não pode ser o banco principal ou um banco reservado do PostgreSQL.');
}

$port = envValue('TEST_DB_PORT', envValue('DB_PORT', $publishedPort));
if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
    fail('TEST_DB_PORT/DB_PORT inválida.');
}
$config = [
    'host' => envValue('TEST_DB_HOST', envValue('DB_HOST', '127.0.0.1')),
    'port' => $port,
    'user' => envValue('TEST_DB_USER', envValue('DB_USER', $local['POSTGRES_USER'])),
    'password' => envValue('TEST_DB_PASSWORD', envValue('DB_PASSWORD', $local['POSTGRES_PASSWORD'] ?? null)),
];
if ($config['password'] === null) {
    fail('Defina TEST_DB_PASSWORD ou DB_PASSWORD no ambiente. Não coloque senhas dentro do script.');
}
if (preg_match('/[;\s]/', $config['host'])) {
    fail('TEST_DB_HOST/DB_HOST inválido.');
}
if (!is_file($sqlPath)) {
    fail('Arquivo SQL não encontrado em sql/001_temperature_readings.sql.');
}
$sql = file_get_contents($sqlPath);
if ($sql === false || trim($sql) === '') {
    fail('O arquivo SQL está vazio ou não pode ser lido.');
}

try {
    echo "Conectando ao PostgreSQL..." . PHP_EOL;
    $admin = connectTo('postgres', $config);
    $statement = $admin->prepare('SELECT 1 FROM pg_database WHERE datname = :name');
    $statement->execute(['name' => $testDatabase]);
    $exists = $statement->fetchColumn() !== false;

    if (!$exists) {
        echo "Criando banco de testes {$testDatabase}..." . PHP_EOL;
        // CREATE DATABASE não admite parâmetros bind para identificadores.
        // Nome validado acima e escapado como identificador PostgreSQL.
        $admin->exec('CREATE DATABASE ' . quoteIdentifier($testDatabase));
    } else {
        echo "Banco de testes {$testDatabase} já existe." . PHP_EOL;
    }
    $admin = null;

    $testDb = connectTo($testDatabase, $config);
    $actual = $testDb->query('SELECT current_database()')->fetchColumn();
    if ($actual !== $testDatabase) {
        fail('Conexão abriu um banco inesperado; operação interrompida.');
    }
    echo 'Aplicando esquema SQL versionado ao banco de testes...' . PHP_EOL;
    $testDb->beginTransaction();
    $testDb->exec($sql);
    $check = $testDb->query("SELECT to_regclass('public.temperature_readings')")->fetchColumn();
    if ($check === null || $check === false) {
        fail('A tabela public.temperature_readings não foi encontrada após aplicar o SQL.');
    }
    $testDb->commit();
    echo "OK: {$testDatabase} pronto; banco principal não foi selecionado para alterações." . PHP_EOL;
    $testDb = null;
} catch (PDOException $exception) {
    // Não imprimir DSN nem credenciais em erros de conexão.
    $state = (string) $exception->getCode();
    fail("Falha no PostgreSQL (SQLSTATE {$state}). Confira se o Docker está ativo, as variáveis de ambiente e as permissões do usuário.");
}

if (in_array('--run-tests', $args, true)) {
    $phpunit = $root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpunit';
    if (!is_file($phpunit)) {
        fail('PHPUnit não encontrado. Execute composer install antes.');
    }
    // Testes herdam credenciais TEST_DB_* para impedir que usem o banco principal.
    $testEnvironment = getenv();
    if (!is_array($testEnvironment)) {
        $testEnvironment = [];
    }
    $testEnvironment['TEST_DB_HOST'] = $config['host'];
    $testEnvironment['TEST_DB_PORT'] = $config['port'];
    $testEnvironment['TEST_DB_NAME'] = $testDatabase;
    $testEnvironment['TEST_DB_USER'] = $config['user'];
    $testEnvironment['TEST_DB_PASSWORD'] = $config['password'];

    echo 'Executando PHPUnit com banco de testes separado...' . PHP_EOL;
    $process = proc_open(
        [PHP_BINARY, $phpunit],
        [0 => STDIN, 1 => STDOUT, 2 => STDERR],
        $pipes,
        $root,
        $testEnvironment
    );
    if (!is_resource($process)) {
        fail('Não foi possível iniciar PHPUnit.');
    }
    exit(proc_close($process));
}
