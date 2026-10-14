# API Refrigerador IoT

PHP 8.4, Slim 4, PDO PostgreSQL e PHPUnit 13. Execute os comandos PowerShell a partir da raiz do repositório. Nenhuma dependência adicional é necessária.

## Configuração local

O `.env` existente é usado pelo Docker Compose e deve ser preservado. O PHP não o carrega automaticamente. `.env.example` documenta variáveis com valores fictícios; não copie esse arquivo por cima do `.env` existente.

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

Essas atribuições são explícitas: confira o ambiente antes de executá-las para não substituir configurações que deseja manter. Não há carregamento automático nem sobrescrita pelo código. Sem `API_WRITE_TOKEN`, o POST retorna 503; `/health` continua público e não abre conexão com o banco. O `.env.example` não contém token utilizável.

## Preparar a tabela

Com Docker disponível e o serviço PostgreSQL em execução, aplique o script versionado no banco de desenvolvimento:

```powershell
Get-Content -Raw sql/001_temperature_readings.sql | docker compose exec -T postgres psql -v ON_ERROR_STOP=1 -U refrigerador -d refrigerador
```

O script cria apenas `temperature_readings`, sem apagar dados. `IF NOT EXISTS` permite reaplicá-lo, mas não corrige uma tabela preexistente com estrutura diferente: confira a estrutura nesse caso. Não aplique diretamente em produção.

## Iniciar e enviar uma medição

No terminal configurado:

```powershell
php -S 127.0.0.1:8080 -t public public/index.php
```

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

Não há classificação de temperaturas nem limites relativos a alimentos. `DOUBLE PRECISION` suporta números finitos do PHP; a restrição SQL apenas exclui infinitos e NaN. Campos adicionais são ignorados, não armazenados. Não há lotes, horários do dispositivo, consultas HTTP de temperaturas ou deduplicação.

## Testes

```powershell
php vendor/phpunit/phpunit/phpunit
```

Testes HTTP usam o Slim diretamente e uma função de persistência controlada; não precisam de servidor nem de banco. Um teste força uma falha real de conexão PDO em uma porta inválida e verifica a resposta segura. Os testes de integração são ignorados quando `TEST_DB_*` não está configurado.

Para integração, prepare previamente e por decisão explícita um banco separado chamado `refrigerador_test`, com usuário e permissões adequados. Os testes não criam bancos nem tabelas. Aplique nele o mesmo script SQL, escolhendo explicitamente o banco de testes:

```powershell
Get-Content -Raw sql/001_temperature_readings.sql | docker compose exec -T postgres psql -v ON_ERROR_STOP=1 -U refrigerador -d refrigerador_test
```

Configure no terminal dos testes `TEST_DB_HOST`, `TEST_DB_PORT`, `TEST_DB_NAME`, `TEST_DB_USER` e `TEST_DB_PASSWORD`, seguindo `.env.example` e usando entrada oculta para a senha. O nome deve terminar em `_test` e ser diferente de `DB_NAME`; nunca aponte essas variáveis para desenvolvimento ou produção. Prefira um usuário autorizado apenas no banco de testes.

Os testes de persistência verificam ambos os sensores, temperatura, horário e ausência de inserção inválida. Cada teste usa transação com rollback, sem truncar ou apagar dados. A sequência dos IDs pode avançar mesmo após rollback, comportamento normal do PostgreSQL.

Depois de configurar, execute novamente o comando de testes. Se o banco ou a tabela estiverem ausentes, a integração falhará explicitamente. Se testes e outra aplicação escreverem simultaneamente no banco de testes, as verificações de contagem podem sofrer interferência; use-o exclusivamente durante a execução.
