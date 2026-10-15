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

Para acessar pela rede doméstica confiável, encerre o servidor local e execute:

```powershell
composer serve-lan
```

Esse comando reutiliza a mesma configuração segura e o banco `refrigerador`, escutando em `0.0.0.0:8080` (todas as interfaces IPv4). Para escutar somente em um IP local específico, use, por exemplo:

```powershell
composer serve-lan -- --host=192.168.1.81
```

Substitua o IP pelo endereço atual do PC, que pode mudar. No Windows, consulte-o com `ipconfig`. `composer serve` permanece restrito a `127.0.0.1:8080`. Não execute os dois servidores simultaneamente na porta 8080.

No celular conectado à mesma rede, abra `http://192.168.1.81:8080/health` e `http://192.168.1.81:8080/api/v1/temperaturas`, substituindo o IP conforme necessário. `0.0.0.0` é endereço de escuta, não o endereço para navegar. Para conferir pelo PowerShell, use `Invoke-RestMethod -Uri http://192.168.1.81:8080/health` e a mesma base para `/api/v1/temperaturas`.

O modo LAN é exclusivo para testes em rede doméstica confiável. Teste inicialmente apenas os GETs públicos, sem enviar credenciais reais ou Bearer token por HTTP. O servidor PHP de desenvolvimento não deve ser usado em produção. O script não configura Firewall, roteadores, serviços externos ou encaminhamento de portas. Se outro dispositivo não alcançar o PC, confira o IP, a rede e as permissões de rede existentes; isolamento entre clientes Wi-Fi também pode impedir acesso.

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

O servidor usa somente `public` como raiz e encaminha todas as requisições ao Slim. Os comandos de desenvolvimento não configuram TLS. Em produção, utilize um servidor apropriado e exija HTTPS.

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

## Firmware ESP32 — sensor DS18B20

O sketch `firmware/esp32/esp32.ino` lê o primeiro DS18B20 no GPIO 4, usando OneWire e DallasTemperature, com Monitor Serial a 115200 baud. A medição inicia a cada 10.000 ms, controlada por `millis()`, e a conversão de 12 bits é assíncrona (750 ms), sem `delay(10000)`. Utilize alimentação externa no VDD do sensor; este modo não é destinado à alimentação parasita.

Antes de compilar, copie a configuração de exemplo e edite a cópia privada:

```powershell
Copy-Item firmware/esp32/config.example.h firmware/esp32/config.local.h
```

Não sobrescreva uma configuração local existente. Em `config.local.h`, preencha `WIFI_SSID` (exemplo: TESLA), `WIFI_PASSWORD` e `API_BASE_URL` (exemplo: `http://192.168.1.81:8080`, sem barra final). O arquivo local é ignorado pelo Git; somente o exemplo sem segredos é versionável. As constantes de intervalo também ficam nesse arquivo: medição 10 segundos, GET `/health` 30 segundos e tentativa Wi-Fi 15 segundos. A conexão não aguarda indefinidamente: as leituras continuam sem Wi-Fi e novas tentativas ocorrem periodicamente.

Para diagnosticar a rede TESLA, acrescente ou altere estas opções em `config.local.h`, sem substituir suas credenciais:

```cpp
#define WIFI_DIAGNOSTICS_ENABLED 1
#define WIFI_SCAN_INTERVAL_MS 60000UL
```

Compile e carregue novamente. No Monitor Serial a 115200 baud, observe o status Wi-Fi, o motivo numérico da desconexão e sua descrição quando conhecida. A primeira varredura ocorre após a primeira tentativa de conexão; as seguintes respeitam o intervalo configurado, com mínimo de 60 segundos. A varredura é assíncrona e ocorre somente enquanto desconectado; durante ela, as tentativas de reconexão ficam pausadas, mas as leituras do sensor continuam. Uma varredura que exceder 15 segundos é interrompida. O encerramento local de uma tentativa para varrer ou reconectar também pode gerar evento de desconexão, identificado nas mensagens anteriores ao evento.

O resultado informa se o SSID configurado foi encontrado em 2,4 GHz, RSSI em dBm, canal, BSSID e autenticação (nome e código). Se houver vários pontos de acesso com o mesmo SSID, todos os encontrados são mostrados. A senha nunca é impressa. Um resultado sem TESLA não prova que ela não existe: pode haver SSID oculto, sinal insuficiente ou incompatibilidade de banda; uma falha de varredura é relatada separadamente. Falha de autenticação ou handshake não prova, sozinha, que a senha está errada. Quando conectar, aparece o IP atribuído e as verificações de `/health` continuam normalmente.

Para uso normal, volte `WIFI_DIAGNOSTICS_ENABLED` para `0` e carregue novamente. Configurações locais antigas sem essas opções mantêm o diagnóstico desativado. O modo não altera a senha, o roteador ou o backend. A causa da falha só pode ser avaliada após observar os resultados reais no ESP32.

WiFi, HTTPClient e SNTP fazem parte do ambiente Arduino ESP32, sem bibliotecas adicionais. HTTP usa timeout de conexão e leitura de 1 segundo cada, encerra `HTTPClient` e o cliente TCP após cada tentativa. A chamada HTTP é síncrona e pode causar pequeno atraso nas leituras em caso de falha; os intervalos são independentes, mas não são uma garantia de tempo real. Não há armazenamento offline, RTC externo ou lotes.

Para verificar a API, inicie `composer serve-lan` no PC e mantenha ESP32 e PC na mesma rede doméstica confiável. O ESP32 precisa de uma rede Wi-Fi compatível com sua placa (normalmente 2,4 GHz).

### Transmissão de temperatura física — laboratório

Após cada conversão válida, o firmware tenta um POST `/api/v1/temperaturas`, usando somente `sensor_id` e `temperature` numérica. Configure explicitamente `SENSOR_ID` como `"refrigerator"` ou `"freezer"` e `API_WRITE_TOKEN` em `config.local.h`, conforme os campos vazios do exemplo. Sem token ou sensor válido, o POST fica desativado. Leituras inválidas não são enviadas. Sem Wi-Fi, a leitura é descartada; falhas HTTP não geram reenvio, pois a API atual não deduplica retransmissões. Um timeout pode ocorrer depois de o servidor gravar: o resultado fica desconhecido.

Use uma credencial nova, descartável e exclusiva deste laboratório. HTTP local expõe o Bearer token ao tráfego da rede: nunca reutilize credenciais permanentes ou de produção. Armazene o mesmo token apenas no `config.local.h` ignorado e no `.env` local como `API_WRITE_TOKEN`, reiniciando o servidor após a alteração. Uma variável `API_WRITE_TOKEN` já definida no terminal tem precedência sobre `.env`; remova-a ou substitua-a pelo token de laboratório. O arquivo legado `.api-token.local` não é lido por `composer serve-lan`. Ao terminar os testes, descarte a credencial e remova-a dos arquivos locais; arquivos binários do firmware também contêm o token e devem permanecer privados.

Na Arduino IDE, use ESP32 Dev Module e **Core Debug Level: None**. Logs internos de HTTP podem expor cabeçalhos; o sketch recusa compilar com debug do core ativado. Não publique credenciais, binários, capturas de cabeçalhos ou a configuração local. Redirecionamentos do POST estão desativados para não encaminhar a credencial a outro destino.

Validação manual, após configurar a credencial com orientação de uma ação por vez:

- Carregue o sketch e observe no Monitor Serial a 115200 baud uma temperatura válida seguida de `POST /api/v1/temperaturas: HTTP 201`. GET `/health` com 200 sozinho não comprova gravação.
- Consulte `Invoke-RestMethod -Uri http://192.168.1.81:8080/api/v1/temperaturas | ConvertTo-Json -Depth 4`, ajustando o IP. Compare sensor, temperatura e horário de recebimento com a execução observada; anote o ID retornado na lista.
- Confira a persistência com `docker compose exec -T postgres psql -U refrigerador -d refrigerador -c 'SELECT id, sensor_id, temperature, received_at FROM temperature_readings ORDER BY received_at DESC, id DESC LIMIT 10;'`. Compare o mesmo ID e valores, considerando que `received_at` representa o horário do servidor, não do ESP32.

401 indica falha de autenticação; 422, entrada rejeitada; 503, indisponibilidade do servidor. O Monitor Serial imprime apenas status e mensagens sem token ou corpo da resposta. Compilar não comprova HTTP 201 no dispositivo nem persistência da temperatura física: essas evidências dependem do carregamento e da validação acima.

### Registro de validação física — 14/10/2026

O usuário confirmou o fluxo DS18B20 no GPIO 4 → ESP32 conectado ao Wi-Fi TESLA → POST autenticado → API Slim → PostgreSQL → GET de consulta. O Monitor Serial apresentou HTTP 201 e medições físicas foram recuperadas em `/api/v1/temperaturas`, incluindo registros consecutivos com intervalos próximos de 10 segundos. Foi observada melhora na regularidade das transmissões após aproximar o ESP32 do roteador. Logs do PHP com conexões aceitas, isoladamente, não são prova de gravação.

A validação confirma o funcionamento no período observado, sem comprovar estabilidade contínua por períodos prolongados ou resolução definitiva das falhas de conexão anteriores. Naquela validação ainda não havia NTP; armazenamento offline e reenvio automático continuam ausentes. Uma medição pode ser perdida em uma falha de comunicação; um timeout após possível gravação deixa o resultado desconhecido. O Bearer por HTTP permanece restrito a credencial descartável exclusiva do laboratório em rede confiável; produção exige HTTPS.

A última suíte PHPUnit executada nesta etapa teve **44 testes, 146 asserções, zero falhas e zero ignorados**. Esse é um resultado anterior, não uma nova execução realizada para esta atualização documental.

Os milestones 5 e 6 têm conclusão funcional com ressalvas no recorte validado: transmissão autenticada e leitura física de um DS18B20 integradas. Como o repositório não apresenta todos os critérios de aceite desses milestones, não foi declarado seu encerramento formal nem alterada a contagem de 4/9 concluídos. Dois sensores e operação prolongada não foram comprovados por esse teste.

O planejamento registrado nessa validação previa NTP e `measured_at`. A sincronização agora está implementada apenas no firmware, conforme a seção abaixo; a transmissão de `measured_at` continua pendente de discussão e aprovação do contrato e da evolução do banco. O JSON permanece com `sensor_id` e `temperature`, e `received_at` representa o recebimento no servidor.

### NTP e qualidade temporal interna

O firmware usa `configTime` e o callback SNTP do core ESP32, sem esperar pelo horário no `loop()`. Solicita sincronização ao conectar e ao reconectar o Wi-Fi; o cliente SNTP também faz atualizações periódicas em segundo plano. O relógio do sistema continua andando após perder a rede, enquanto o ESP32 permanecer ligado. Reiniciar a placa começa novamente sem referência conhecida.

Os parâmetros abaixo constam em `config.example.h`; os mesmos padrões são usados automaticamente se sua configuração privada antiga não os definir. Para personalizar, adicione somente as opções desejadas em `config.local.h`, sem sobrescrever senha ou token:

| Parâmetro | Padrão | Finalidade |
|---|---|---|
| `NTP_SERVER_1` | `pool.ntp.org` | Primeiro servidor NTP |
| `NTP_SERVER_2` | `time.cloudflare.com` | Segundo servidor NTP |
| `NTP_SYNC_INTERVAL_MS` | `3600000UL` | Atualização periódica a cada 1 hora; mínimo de 15 segundos |
| `TIME_REFERENCE_MAX_AGE_MS` | `21600000ULL` | Referência recente por até 6 horas |

Esses servidores públicos exigem DNS e acesso NTP (UDP 123); alcançar a API LAN não garante acesso a NTP. Pode-se configurar um servidor NTP acessível na rede local. Não são necessárias credenciais NTP.

| `time_quality` | Significado |
|---|---|
| `unknown` | Nenhuma referência NTP válida desde a inicialização; horário absoluto indisponível |
| `synced` | Houve referência válida há menos de 6 horas (ou do prazo configurado) |
| `estimated` | O prazo expirou; o relógio continua usando sua referência anterior |

A janela inicial de 6 horas dá margem a falhas temporárias entre atualizações de 1 hora, mas não certifica a precisão do oscilador. A qualidade depende da idade da última sincronização bem-sucedida, não do estado do Wi-Fi. Falhar uma tentativa ou desconectar não apaga uma referência recente; conectar também não renova sua idade sem uma resposta NTP válida. A idade usa um timer monotônico de 64 bits, sem depender de saltos do relógio UTC ou do rollover de `millis()`.

Internamente, `measurementTime` guarda horário UTC, disponibilidade e qualidade no início da conversão do DS18B20. O snapshot permanece fixo até terminar essa medição: sincronizações posteriores só afetam novas medições. Em `unknown`, não se atribui uma data de calendário; o campo numérico interno não deve ser usado quando `available` é falso. O Monitor Serial mostra o horário em UTC com sufixo `Z` e a qualidade junto à leitura. Os snapshots não formam um histórico armazenado nem uma fila offline.

Eventos de solicitação, nova referência, perda de rede e expiração são registrados sem credenciais; não há mensagens de espera a cada passagem pelo loop. O POST não aguarda NTP e continua enviando somente `sensor_id` e `temperature`, mesmo em `unknown` ou `estimated`. Horário da medição e `time_quality` não são transmitidos nem persistidos na API.

Para validação física na Arduino IDE, com Monitor Serial a 115200 baud:

1. Reinicie com a rede indisponível: observe `time_quality=unknown`, horário indisponível e leituras do sensor continuando.
2. Disponibilize Wi-Fi com acesso NTP: observe a solicitação em segundo plano, a referência recebida, UTC e `synced`; confirme que o POST e `/health` continuam funcionando.
3. Interrompa apenas a conectividade, mantendo a placa ligada: UTC deve avançar e a qualidade permanecer `synced` até expirar o prazo, depois `estimated`. Para um teste curto, use temporariamente `TIME_REFERENCE_MAX_AGE_MS` de `60000ULL` antes de carregar; ao terminar, restaure o padrão de 6 horas.
4. Restabeleça a conectividade: só uma nova resposta NTP deve restaurar `synced`. Se necessário, confira DNS e UDP 123. Compare linhas anteriores do Serial: seus horários e qualidades não são recalculados, mesmo se o relógio atual for corrigido.

Compilar o sketch, isoladamente, não comprova sincronização, precisão, continuidade do relógio sem rede ou reconexão física. A validação abaixo foi relatada pelo usuário no dispositivo real, não inferida da compilação.

### Validação física NTP — 14/10/2026

Com acompanhamento pelo Monitor Serial, foi confirmada a sequência **`unknown → synced → estimated → synced`** em um ESP32 real:

- Inicialização sem Wi-Fi: nenhuma referência NTP, horário da medição indisponível e DS18B20 continuando as leituras.
- Conexão Wi-Fi e recebimento de referência NTP válida: horário UTC disponível e medições com `time_quality=synced`.
- Desconexão com a placa energizada e limite experimental de `60000ULL`: transição automática para `estimated` após aproximadamente 60 segundos sem nova referência válida. UTC continuou avançando pelo relógio interno e o sensor continuou medindo.
- Retorno do Wi-Fi e nova referência NTP: recuperação automática de `estimated` para `synced`, sem reiniciar o ESP32.

O teste aprovou as transições e a recuperação, mas **não mediu a precisão do relógio por períodos prolongados sem sincronização**. A configuração normal permanece em atualização prevista de **1 hora** (`3600000UL`) e validade de **6 horas** (`21600000ULL`). Esses dois valores foram confirmados no `config.local.h` privado antes do checkpoint; o arquivo não foi alterado por esta revisão. O limite experimental de 60 segundos não é a configuração definitiva, e esse teste curto não equivale a uma medição de precisão durante 6 horas.

Medições feitas sem Wi-Fi não são armazenadas para reenvio. O horário da medição e sua qualidade são informações internas ao firmware; os campos `measured_at` e `time_quality` não são transmitidos ou persistidos na API. O POST segue com `sensor_id` e `temperature`; `received_at` continua sendo gerado no PostgreSQL.

Durante os testes também foram observados `connection refused` e `read Timeout` em HTTP, sinais de aproximadamente **-88 e -92 dBm** em algumas varreduras e um ponto mesh da cozinha desligado durante parte do período. São observações para diagnóstico: podem contribuir para a instabilidade, mas não demonstram a causa de todas as falhas HTTP nem sua resolução definitiva. A regularidade observada após aproximar o ESP32 do roteador não comprova estabilidade contínua por períodos prolongados.

### Próxima fase planejada — contrato de dados

A próxima etapa é discutir o contrato e a persistência dos campos candidatos `device_id`, `measurement_id`, `measured_at`, `received_at`, `time_quality`, `wifi_rssi`, `uptime` e `observation`. `received_at` já existe; sua semântica de recebimento deverá ser preservada ou discutida explicitamente. Os demais campos não são persistidos atualmente. Essa lista é planejamento, sem autorização para implementar novos campos, migrações, filas offline ou endpoints. Nenhum milestone inteiro foi declarado concluído por essa validação NTP; permanecem as ressalvas e a contagem formal registrada no `AGENTS.md`.

Na Arduino IDE, com o suporte à placa ESP32 e as bibliotecas OneWire e DallasTemperature disponíveis:

1. Abra `firmware/esp32/esp32.ino` em **Arquivo → Abrir**. A pasta `esp32` já corresponde ao nome do sketch.
2. Conecte o ESP32 via USB, selecione a placa correspondente ao seu modelo e a porta serial em **Ferramentas**.
3. Clique em **Verificar** para compilar e em **Carregar** para enviar à placa.
4. Abra o Monitor Serial em **115200 baud**: acompanhe a contagem de sensores, tentativas de Wi-Fi, IP atribuído, temperatura ou falha do sensor e `GET /health: HTTP 200` quando a API responder. Outro status indica resposta inesperada; códigos negativos indicam falha de transporte. As mensagens não imprimem senha ou token.

Adicionar o sketch ao repositório não confirma sua compilação ou carregamento no dispositivo. Essas operações devem ser verificadas na Arduino IDE com a placa utilizada.

## Testes do backend

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
