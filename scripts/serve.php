<?php

declare(strict_types=1);

// Somente inicialização local: não cria bancos, tabelas nem executa migrações.
function serveFail(string $message): never
{
    fwrite(STDERR, 'ERRO: ' . $message . PHP_EOL);
    exit(1);
}

function composeOutput(array $arguments, string $root): string
{
    // Ambas as saídas podem conter segredos; nunca encaminhá-las ao terminal.
    $process = proc_open(
        ['docker', 'compose', 'config', ...$arguments],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
    );
    if (!is_resource($process)) {
        serveFail('Não foi possível iniciar Docker Compose.');
    }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0 || $output === false) {
        serveFail('Não foi possível ler a configuração local. Confira Docker Compose e .env.');
    }
    return $output;
}

if (PHP_SAPI !== 'cli' || count($argv) !== 1) {
    serveFail('Use composer serve sem argumentos.');
}
if (!extension_loaded('pdo_pgsql')) {
    serveFail('Habilite a extensão PHP pdo_pgsql.');
}
$existingServer = @stream_socket_client('tcp://127.0.0.1:8080', $socketError, $socketMessage, 1);
if (is_resource($existingServer)) {
    fclose($existingServer);
    serveFail('A porta 8080 já está em uso. Encerre o servidor anterior antes de executar composer serve.');
}
$root = dirname(__DIR__);
if (!is_file($root . '/vendor/autoload.php')) {
    serveFail('Execute composer install antes de iniciar a API.');
}

// O próprio Compose interpreta .env, aspas e interpolação, como nos testes.
$compose = json_decode(composeOutput(['--format', 'json'], $root), true);
$service = $compose['services']['postgres'] ?? [];
$local = $service['environment'] ?? [];
if (($local['POSTGRES_DB'] ?? null) !== 'refrigerador') {
    serveFail('O serviço postgres deve configurar o banco principal refrigerador.');
}
$port = null;
foreach ($service['ports'] ?? [] as $mapping) {
    if ((string) ($mapping['target'] ?? '') === '5432') {
        $port = (string) ($mapping['published'] ?? '');
        break;
    }
}
if ($port === null || !ctype_digit($port)) {
    serveFail('Porta publicada do PostgreSQL não configurada.');
}

$environment = getenv();
$environment = is_array($environment) ? $environment : [];
// --environment fornece os valores resolvidos do .env e do terminal.
// Carregar apenas as configurações usadas pela API, nunca TEST_DB_*.
foreach (explode("\n", composeOutput(['--environment'], $root)) as $line) {
    $pair = explode('=', rtrim($line, "\r"), 2);
    if (count($pair) === 2 && in_array($pair[0],
        ['DB_HOST', 'DB_PORT', 'DB_USER', 'DB_PASSWORD', 'API_WRITE_TOKEN'], true)) {
        $environment[$pair[0]] = $pair[1];
    }
}
foreach (['DB_HOST' => '127.0.0.1', 'DB_PORT' => $port,
    'DB_USER' => $local['POSTGRES_USER'] ?? '',
    'DB_PASSWORD' => $local['POSTGRES_PASSWORD'] ?? ''] as $key => $fallback) {
    if (($environment[$key] ?? '') === '') {
        $environment[$key] = $fallback;
    }
    if ($environment[$key] === '') {
        serveFail('Configuração local de banco incompleta.');
    }
}
// Mesmo com DB_NAME/TEST_DB_NAME herdados, o servidor usa apenas refrigerador.
$environment['DB_NAME'] = 'refrigerador';
unset($environment['PHP_CLI_SERVER_WORKERS']);

// Confirmar a conexão de desenvolvimento antes de iniciar o servidor.
foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD'] as $key) {
    putenv($key . '=' . $environment[$key]);
}
require_once $root . '/database.php';
try {
    $pdo = connectDatabase();
    if ($pdo->query('SELECT current_database()')->fetchColumn() !== 'refrigerador') {
        serveFail('A conexão não selecionou o banco principal refrigerador.');
    }
    $pdo = null;
} catch (PDOException | RuntimeException) {
    serveFail('Não foi possível conectar ao banco refrigerador. Confira PostgreSQL e a configuração local.');
}

echo 'API local: http://127.0.0.1:8080 (banco refrigerador). Encerre com Ctrl+C.' . PHP_EOL;
$process = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:8080', '-t', 'public', 'public/index.php'],
    [0 => STDIN, 1 => STDOUT, 2 => STDERR],
    $pipes,
    $root,
    $environment,
);
if (!is_resource($process)) {
    serveFail('Não foi possível iniciar o servidor PHP.');
}
exit(proc_close($process));
