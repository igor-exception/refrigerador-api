# API Refrigerador IoT

PHP 8.4, Slim 4, PDO PostgreSQL e PHPUnit 13. Execute os comandos PowerShell a partir da raiz do repositório. Nenhuma dependência adicional é necessária.

## Configuração local

O `.env` existente é usado pelo Docker Compose e deve ser preservado. `composer serve` resolve essa configuração pelo Compose e a transmite ao servidor PHP. A execução manual de PHP não carrega `.env` automaticamente. `.env.example` documenta variáveis com valores fictícios; não copie esse arquivo por cima do `.env` existente.

Defina as variáveis no terminal que iniciará o PHP. Use a senha já configurada no PostgreSQL. Escolha pessoalmente um token longo, aleatório e sem espaços, mantenha-o em local seguro e não o publique. A entrada oculta evita incluí-lo no histórico dos comandos:

```powershell
$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = '5432'
$env:DB_NAME = 'refrigerador'
$env:DB_USER = 'refrigerador'
$databaseSecret = Read-Host 'Senha existente do PostgreSQL' -AsSecureString
$env:DB_PASSWORD = [System.Net.NetworkCredential]::new('', $databaseSecret).Password
$writeSecret = Read-Host 'Token de escrita escolhido por você' -AsSecureString
$env:API_WRITE_TOKEN = [System.Net.NetworkCredential]::new('', $writeSecret).Password
```

Essas atribuições são necessárias para a execução manual; confira o ambiente antes de executá-las. Sem `API_WRITE_TOKEN`, o POST retorna 503; `/health` continua público e não abre conexão com o banco. O `.env.example` não contém token utilizável.

## Preparar a tabela

Com Docker disponível e o serviço PostgreSQL em execução, aplique o script versionado no banco de desenvolvimento:

```powershell
Get-Content -Raw sql/001_temperature_readings.sql | docker compose exec -T postgres psql -v ON_ERROR_STOP=1 -U refrigerador -d refrigerador
```

O script cria apenas `temperature_readings`, sem apagar dados. `IF NOT EXISTS` permite reaplicá-lo, mas não corrige uma tabela preexistente com estrutura diferente: confira a estrutura nesse caso. Não aplique diretamente em produção.

## Iniciar e enviar uma medição

Com PostgreSQL em execução (`docker compose up -d postgres`), a partir da raiz do projeto:

```powershell
composer serve
```

O comando funciona com PHP e Composer no host, em Windows, Linux e macOS; a validação local foi realizada no Windows. Usa o usuário, a senha e a porta publicada do Compose como padrões. `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASSWORD` e `API_WRITE_TOKEN` podem ser definidos no `.env` ou no terminal, com precedência do terminal. O banco é sempre `refrigerador`, mesmo se `DB_NAME` ou `TEST_DB_NAME` indicarem outro banco. Nenhuma credencial é impressa ou gravada pelo script.

O servidor fica em primeiro plano em `127.0.0.1:8080`; encerre com Ctrl+C. O comando verifica a conexão e o nome do banco antes de iniciar; não inicia Docker, não cria tabelas e não executa migrações. Se a porta estiver ocupada, o comando termina com erro: encerre o servidor anterior. A senha deve corresponder ao volume PostgreSQL existente. O GET não exige token; para usar o POST, defina seu `API_WRITE_TOKEN` no terminal ou no `.env` antes de iniciar. O arquivo `.api-token.local` não é carregado por esse comando.

Como alternativa, no terminal com todas as variáveis configuradas manualmente, continua disponível `php -S 127.0.0.1:8080 -t public public/index.php`.

Em outro terminal, configure o mesmo token via entrada oculta:

```powershell
$writeSecret = Read-Host 'Mesmo token configurado no servidor' -AsSecureString
$env:API_WRITE_TOKEN = [System.Net.NetworkCredential]::new('', $writeSecret).Password
$headers = @{ Authorization = "Bearer $env:API_WRITE_TOKEN" }
$body = @{ sensor_id = 'freezer'; temperature = -18.2 } | ConvertTo-Json -Compress
Invoke-RestMethod -Method Post -Uri http://127.0.0.1:8080/api/v1/temperaturas -Headers $headers -ContentType 'application/json' -Body $body
```

Também é aceito `refrigerator`, independentemente de `freezer`. O retorno 201 contém `id`, `sensor_id`, `temperature` em Celsius e `received_at` em UTC. PostgreSQL gera o ID e registra o horário de recebimento durante o INSERT, com `TIMESTAMPTZ`. Esse ID não deduplica retransmissões.

Confirme a gravação diretamente no banco:

```powershell
docker compose exec -T postgres psql -U refrigerador -d refrigerador -c 'SELECT id, sensor_id, temperature, received_at FROM temperature_readings ORDER BY id DESC LIMIT 10;'
Invoke-RestMethod -Uri http://127.0.0.1:8080/health
```

O servidor usa somente `public` como raiz e encaminha todas as requisições ao Slim. Em produção, o provedor ou proxy deve exigir HTTPS; este comando local não configura TLS e deve continuar limitado a `127.0.0.1`.

## Validação e segurança

O fluxo do POST é: comparação do Bearer token com `hash_equals` → decodificação JSON → validação → conexão PDO → INSERT parametrizado → resposta JSON.

- 401: token ausente, inválido ou cabeçalho incompatível.
- 400: JSON malformado.
- 422: corpo diferente de objeto, campos obrigatórios ausentes, sensor desconhecido ou temperatura que não seja número JSON finito.
- 503: token do servidor ausente, conexão/configuração/persistência indisponível, com mensagem pública genérica e log sem detalhes de credenciais.

Não há classificação de temperaturas nem limites relativos a alimentos. `DOUBLE PRECISION` suporta números finitos do PHP; a restrição SQL apenas exclui infinitos e NaN. Campos adicionais são ignorados, não armazenados. Não há lotes, horários do dispositivo ou deduplicação.

## Consultar medições

`GET /api/v1/temperaturas` é público e não exige Bearer token. Com o servidor iniciado no terminal configurado com `DB_*`, execute em outro PowerShell:

```powershell
Invoke-RestMethod -Uri http://127.0.0.1:8080/api/v1/temperaturas | ConvertTo-Json -Depth 4
```

A rota abre a conexão PDO e consulta até 50 medições, ordenadas por `received_at DESC, id DESC`. Retorna HTTP 200 com `data`, uma lista contendo `id`, `sensor_id`, `temperature` numérica e `received_at` em UTC, no mesmo formato do POST. Sem registros, retorna `{"data":[]}`. Falhas de configuração, conexão ou consulta retornam HTTP 503 com mensagem genérica, sem credenciais ou SQL. O POST continua exigindo Bearer token. Não há filtros ou paginação.

## Testes

Os comandos abaixo funcionam em PowerShell, Linux e macOS, a partir da raiz do repositório. Requerem PHP 8.4 CLI com `pdo_pgsql` e as extensões exigidas pelo Composer, Composer e Docker com Compose instalado e em execução.

Após clonar ou formatar o computador:

1. Crie um arquivo `.env` local contendo `POSTGRES_PASSWORD=seu-segredo-local`, escolhendo uma senha própria. Esse arquivo é ignorado pelo Git. Se o volume PostgreSQL já existir, use a senha com que ele foi inicializado: editar `.env` não muda a senha do banco existente. Não sobrescreva seu `.env` atual.
2. Instale as dependências já declaradas e inicie o serviço:

```sh
composer install
docker compose up -d postgres
```

3. Aguarde o PostgreSQL ficar pronto e execute:

```sh
composer setup-tests
composer test
```

`setup-tests` consulta a configuração resolvida por `docker compose config --format json`, sem exibi-la. Usa o usuário e a senha do serviço `postgres`, o host `127.0.0.1` e a porta publicada pelo Compose. Não solicita senha e não grava credenciais em arquivos adicionais. Conecta ao banco administrativo `postgres` apenas para consultar a existência do banco de testes e criá-lo quando necessário; aplica `sql/001_temperature_readings.sql` somente em `refrigerador_test`. O usuário precisa de permissão CREATE DATABASE apenas se esse banco ainda não existir.

`composer test` repete a preparação e inicia o PHPUnit, transmitindo `TEST_DB_HOST`, `TEST_DB_PORT`, `TEST_DB_NAME`, `TEST_DB_USER` e `TEST_DB_PASSWORD` ao processo filho. Não é necessário exportar essas variáveis manualmente. As variáveis não permanecem no terminal depois do comando. A suíte inclui testes HTTP e de integração da gravação e da consulta; os testes da consulta usam tabelas temporárias na conexão isolada para preservar registros existentes.

Overrides no ambiente do terminal têm precedência: `TEST_DB_*` → `DB_*` → configuração do Compose. O nome de testes usa `TEST_DB_NAME` ou `refrigerador_test`, nunca `DB_NAME`. Deve terminar em `_test` e ser diferente do banco principal configurado em `DB_NAME`, do banco principal do Compose e de `refrigerador`. O `.env` é interpretado pelo Compose; variáveis `TEST_DB_*` escritas nele não são exportadas ao PHP automaticamente.

A preparação pode ser repetida: não apaga bancos, tabelas ou dados. O SQL usa `IF NOT EXISTS`, portanto não migra nem corrige estruturas preexistentes incompatíveis. A aplicação do esquema ocorre em transação. Os testes de persistência usam transações com rollback; sequências de IDs podem avançar, comportamento normal do PostgreSQL. Use o banco de testes exclusivamente durante a suíte para evitar interferência nas contagens.

Se a conexão, configuração ou preparação falhar, o comando termina com erro antes de iniciar a suíte; não transforma indisponibilidade em sucesso com integração ignorada. Confira se Docker está ativo, se o PostgreSQL está pronto, se `pdo_pgsql` está habilitado e se as credenciais correspondem ao volume existente. Não use `docker compose down -v` para resolver problemas: ele pode excluir dados.

Para executar apenas a suíte sem preparação automática:

```sh
php vendor/bin/phpunit
```

Nesse comando direto, os testes de integração continuam sendo ignorados se as cinco variáveis `TEST_DB_*` não estiverem definidas. Os testes HTTP usam Slim diretamente e não precisam de servidor HTTP nem do ESP32. Nenhum comando de testes modifica funcionalidades da API ou dados do banco principal.
