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

Com Docker disponível e o serviço PostgreSQL em execução, prepare uma instalação nova do banco de desenvolvimento com os dois scripts, na ordem, em uma transação:

```powershell
Get-Content -Raw sql/001_temperature_readings.sql,sql/002_measurement_metadata.sql | docker compose exec -T postgres psql -v ON_ERROR_STOP=1 -1 -U refrigerador -d refrigerador -f -
```

`001` cria a tabela original; `002` acrescenta metadados, restrição de consistência e índice único, sem apagar registros ou mudar IDs e `received_at`. Ambos podem ser reaplicados, mas não corrigem estruturas incompatíveis arbitrárias. Para uma instalação já existente, siga a migração incremental abaixo. A API requer o esquema atualizado antes de receber o contrato enriquecido. Não aplique diretamente em produção.

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

Esse exemplo usa o contrato legado. Também é aceito `refrigerator`, independentemente de `freezer`. O retorno 201 preserva `id`, `sensor_id`, `temperature` e `received_at`, acrescentando metadados nulos. PostgreSQL gera o ID e o horário de recebimento. O contrato enriquecido abaixo adiciona idempotência; o ID numérico sozinho não deduplica retransmissões.

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
- 422 no formato enriquecido: metadados parciais, tipos/valores inválidos, timestamp incompatível com a qualidade ou campos não permitidos.
- 409: mesmo par `device_id`/`measurement_id` com conteúdo diferente.
- 503: token do servidor ausente, conexão/configuração/persistência indisponível, com mensagem pública genérica e log sem detalhes de credenciais.

Não há classificação de temperaturas nem limites relativos a alimentos. `DOUBLE PRECISION` suporta números finitos do PHP; a restrição SQL exclui infinitos e NaN. O legado continua ignorando campos extras não reconhecidos; a presença de qualquer metadado reconhecido exige o contrato enriquecido completo. Não há lotes nem retransmissão automática no firmware.

## Consultar medições

`GET /api/v1/temperaturas` é público e não exige Bearer token. Com o servidor iniciado no terminal configurado com `DB_*`, execute em outro PowerShell:

```powershell
Invoke-RestMethod -Uri http://127.0.0.1:8080/api/v1/temperaturas | ConvertTo-Json -Depth 4
```

A rota abre a conexão PDO e consulta até 50 medições, ordenadas por `received_at DESC, id DESC`. Retorna HTTP 200 com `data`, uma lista com os campos da resposta documentada abaixo. Registros legados continuam visíveis, com metadados nulos. Sem registros, retorna `{"data":[]}`. Falhas retornam HTTP 503 com mensagem genérica, sem credenciais ou SQL. O POST continua exigindo Bearer token. Para consultar o histórico, use `?before_id=ID` com o `id` do último item da página anterior. Cada página mantém o limite de 50 e a ordenação pelo par `(received_at, id)`, sem depender apenas da sequência dos IDs. Sem parâmetro, o comportamento permanece igual. Cursor deve ser inteiro positivo; inválido retorna 422 e inexistente retorna `{"data":[]}`. Filtros por datas ficam pendentes. Exemplo PowerShell: `Invoke-RestMethod "http://127.0.0.1:8080/api/v1/temperaturas?before_id=50"` (substitua 50 pelo último ID recebido).

## Contrato enriquecido de uma medição

O POST aceita dois formatos: legado com `sensor_id` e `temperature`, ou enriquecido com todos os metadados obrigatórios abaixo. Campos desconhecidos e `received_at` no formato enriquecido são rejeitados. `observation` pode ser omitido e assume `null`. Nenhum identificador ou timestamp do dispositivo é fabricado pela API para clientes antigos.

| Campo | JSON / PostgreSQL | Validação e significado |
|---|---|---|
| `id` | inteiro / BIGINT identity | Apenas resposta; identificador da linha no servidor |
| `device_id` | string / VARCHAR(64) | 1–64 caracteres ASCII: primeira letra ou dígito, depois letras, dígitos, `.`, `_`, `:`, `-`; estável por dispositivo |
| `sensor_id` | string / TEXT | `refrigerator` ou `freezer` |
| `measurement_id` | string / VARCHAR(128) | 1–128 caracteres, mesmo alfabeto; exclusivo dentro do dispositivo |
| `temperature` | número / DOUBLE PRECISION | Número JSON finito em °C |
| `measured_at` | string ou null / TIMESTAMPTZ | Data real ISO 8601 UTC (`Z` ou `+00:00`), ano 0001–9999, até 6 casas decimais; normalizada para UTC na resposta |
| `received_at` | string / TIMESTAMPTZ | Gerado pelo PostgreSQL no INSERT; nunca substituído pelo horário do ESP32 |
| `time_quality` | string / VARCHAR(9) | `synced`, `estimated` ou `unknown` |
| `wifi_rssi` | inteiro ou null / SMALLINT | -127 a 0 dBm; `null` quando indisponível |
| `uptime_ms` | inteiro / BIGINT | 0 a 9007199254740991 ms; inteiro também representável exatamente em JavaScript |
| `observation` | string ou null / TEXT | Até 1024 caracteres Unicode, incluindo quebras de linha; sem caractere NUL; padrão `null` |

Para `unknown`, `measured_at` deve ser `null`. Para `synced` e `estimated`, o timestamp é obrigatório. Datas inexistentes, segundos de salto e horários sem UTC explícito são rejeitados. A API não altera a qualidade declarada nem corrige medições antigas depois de uma nova sincronização. Os metadados são fornecidos pelo cliente; o servidor valida sua consistência, sem comprovar por si só a precisão NTP.

Exemplo sintético de envio pelo firmware (Bearer somente no cabeçalho, não no JSON):

```json
{
  "device_id": "geladeira_01",
  "sensor_id": "refrigerator",
  "measurement_id": "6f03b7a2-19c8-4d53-ae91-072e4c68b015-1",
  "temperature": 4.8,
  "measured_at": "2026-10-14T12:00:00Z",
  "time_quality": "synced",
  "wifi_rssi": -65,
  "uptime_ms": 10000,
  "observation": null
}
```

Resposta de uma gravação nova (201); uma repetição equivalente retorna o mesmo objeto com 200. O GET inclui objetos desse formato dentro de `data`:

```json
{
  "id": 123,
  "sensor_id": "refrigerator",
  "temperature": 4.8,
  "received_at": "2026-10-14T12:00:00.750000Z",
  "device_id": "geladeira_01",
  "measurement_id": "6f03b7a2-19c8-4d53-ae91-072e4c68b015-1",
  "measured_at": "2026-10-14T12:00:00.000000Z",
  "time_quality": "synced",
  "wifi_rssi": -65,
  "uptime_ms": 10000,
  "observation": null
}
```

### Idempotência e identificação no ESP32

O índice único usa `(device_id, measurement_id)`, com comparação sensível a maiúsculas. INSERT parametrizado com `ON CONFLICT DO NOTHING` evita duplicação, inclusive sob inserções concorrentes. Após conflito, a API lê a linha original e compara sensor, temperatura e todos os metadados: igualdade retorna 200 sem alterar ID, horário de recebimento ou conteúdo; diferença retorna 409 sem sobrescrever. Timestamps equivalentes, normalizados para microssegundos UTC, são considerados iguais. Não se inclui `received_at` na comparação porque pertence ao servidor. Sequências de IDs podem avançar mesmo quando não ocorre nova gravação.

O firmware gera um UUID v4 por boot usando `esp_fill_random`, após habilitar o rádio Wi-Fi, e acrescenta um contador de 64 bits por medição. O contador não volta ao início durante aquele boot; um novo UUID torna colisões entre reinicializações extremamente improváveis (122 bits aleatórios). Não se depende de NTP ou do rollover de `millis()` para identificação. Configure um `DEVICE_ID` estável e exclusivo em `config.local.h`; o exemplo e o fallback para a configuração privada anterior usam `geladeira_01`. Futuras retransmissões deverão preservar o ID e o snapshot completos originais, sem gerar um ID novo ou atualizar RSSI, uptime ou qualidade. Não há fila nem reenvio nesta etapa.

### Histórico, migração e validação local

`sql/002_measurement_metadata.sql` acrescenta somente colunas, uma restrição de consistência, o índice único necessário à idempotência e um índice por `(received_at DESC, id DESC)` para consulta e paginação, sem tabelas adicionais. Registros anteriores e novos POSTs legados têm todos os sete metadados novos em `null`; isso não equivale a atribuir `time_quality=unknown`, pois a qualidade histórica não é conhecida. O contrato legado continua retornando 201 a cada envio, sem idempotência. `id`, sensor, temperatura e `received_at` históricos são preservados.

Antes de aplicar ao desenvolvimento, preserve um backup caso os dados precisem ser recuperados. Confira a branch e o destino `refrigerador`, e use somente o SQL versionado, em transação. Para uma tabela original já existente:

```powershell
Get-Content -Raw sql/002_measurement_metadata.sql | docker compose exec -T postgres psql -v ON_ERROR_STOP=1 -1 -U refrigerador -d refrigerador -f -
```

A preparação e os testes automatizados aplicam as migrações somente a `refrigerador_test`. O script de servidor não migra bancos. Esta implementação foi validada no banco de testes; a migração foi aplicada manualmente pelo usuário ao banco principal, sem execução automática nesta revisão. Não apague volumes/tabelas para atualizar o esquema. Se uma estrutura divergente impedir a migração, interrompa e investigue; `IF NOT EXISTS` não repara alterações arbitrárias.

Depois de migrar, os exemplos HTTP legados acima continuam utilizáveis. Para testar o formato enriquecido, use `$body` com o JSON exemplificado, mantenha a credencial de laboratório apenas no cabeçalho e envie com `Invoke-RestMethod` para o mesmo POST. Repetir exatamente o corpo deve resultar em 200 e no mesmo `id`; alterar um metadado mantendo o identificador deve resultar em 409. Consulte o GET e, se necessário, `SELECT * FROM temperature_readings ORDER BY received_at DESC, id DESC LIMIT 10;` no banco de desenvolvimento para comparar os campos.

Validação local desta evolução em 14/10/2026: **124 testes, 461 asserções, zero falhas e zero ignorados**, exclusivamente em `refrigerador_test`. Cobertura de inserção completa, legado, estados temporais, tipos e limites, repetição nos três estados, conflito, metadados e paginação estável no GET e migração sobre tabela temporária antiga com registros, aplicada duas vezes. Compilação aprovada para ESP32 Dev Module, core 3.3.12, com configuração de exemplo sem segredos: 953832 bytes de flash (72%) e 49008 bytes de variáveis globais (14%).

O fluxo físico básico do formato enriquecido foi confirmado pelo usuário, conforme registro abaixo. Isso não significa que conflitos, repetição idempotente ou todos os cenários de falha foram testados no dispositivo. Dashboard, filtros por datas e armazenamento offline continuam pendentes; o GET já permite percorrer o histórico em páginas de até 50 linhas.

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

Após cada conversão válida, o firmware tenta um POST `/api/v1/temperaturas` com o contrato enriquecido acima. Configure `DEVICE_ID` (inicialmente `geladeira_01`), `SENSOR_ID` como `"refrigerator"` ou `"freezer"` e `API_WRITE_TOKEN` em `config.local.h`. A cópia privada anterior usa o fallback de dispositivo, sem ser sobrescrita. Sem token ou identificação válida, o POST fica desativado. Leituras inválidas não são enviadas. RSSI, uptime, qualidade e horário são capturados no início da medição; RSSI indisponível e horário `unknown` são nulos. `observation` é enviado como `null`. Sem Wi-Fi, a leitura é descartada; não há reenvio automático, embora a API enriquecida já aceite repetição idempotente. Um timeout pode ocorrer depois de o servidor gravar: o resultado fica desconhecido.

Use uma credencial nova, descartável e exclusiva deste laboratório. HTTP local expõe o Bearer token ao tráfego da rede: nunca reutilize credenciais permanentes ou de produção. Armazene o mesmo token apenas no `config.local.h` ignorado e no `.env` local como `API_WRITE_TOKEN`, reiniciando o servidor após a alteração. Uma variável `API_WRITE_TOKEN` já definida no terminal tem precedência sobre `.env`; remova-a ou substitua-a pelo token de laboratório. O arquivo legado `.api-token.local` não é lido por `composer serve-lan`. Ao terminar os testes, descarte a credencial e remova-a dos arquivos locais; arquivos binários do firmware também contêm o token e devem permanecer privados.

Na Arduino IDE, use ESP32 Dev Module e **Core Debug Level: None**. Logs internos de HTTP podem expor cabeçalhos; o sketch recusa compilar com debug do core ativado. Não publique credenciais, binários, capturas de cabeçalhos ou a configuração local. Redirecionamentos do POST estão desativados para não encaminhar a credencial a outro destino.

Validação manual, após configurar a credencial com orientação de uma ação por vez:

- Carregue o sketch e observe no Monitor Serial a 115200 baud uma temperatura válida seguida de `POST /api/v1/temperaturas: HTTP 201`. GET `/health` com 200 sozinho não comprova gravação.
- Consulte `Invoke-RestMethod -Uri http://192.168.1.81:8080/api/v1/temperaturas | ConvertTo-Json -Depth 4`, ajustando o IP. Compare sensor, temperatura e horário de recebimento com a execução observada; anote o ID retornado na lista.
- Confira a persistência com `docker compose exec -T postgres psql -U refrigerador -d refrigerador -c 'SELECT id, sensor_id, temperature, received_at FROM temperature_readings ORDER BY received_at DESC, id DESC LIMIT 10;'`. Compare o mesmo ID e valores, considerando que `received_at` representa o horário do servidor, não do ESP32.

201 indica criação; 200, repetição equivalente; 409, identificador com conteúdo diferente; 401, falha de autenticação; 422, entrada rejeitada; 503, indisponibilidade do servidor. O Monitor Serial imprime status, ID e diagnóstico sem token ou corpo da resposta. Compilar, isoladamente, não comprova funcionamento físico. O fluxo básico do novo formato foi confirmado pelo usuário; a repetição e o conflito foram cobertos por testes automatizados.

### Registro de validação física — 14/10/2026

O usuário confirmou o fluxo DS18B20 no GPIO 4 → ESP32 conectado ao Wi-Fi TESLA → POST autenticado → API Slim → PostgreSQL → GET de consulta. O Monitor Serial apresentou HTTP 201 e medições físicas foram recuperadas em `/api/v1/temperaturas`, incluindo registros consecutivos com intervalos próximos de 10 segundos. Foi observada melhora na regularidade das transmissões após aproximar o ESP32 do roteador. Logs do PHP com conexões aceitas, isoladamente, não são prova de gravação.

A validação confirma o funcionamento no período observado, sem comprovar estabilidade contínua por períodos prolongados ou resolução definitiva das falhas de conexão anteriores. Naquela validação ainda não havia NTP; armazenamento offline e reenvio automático continuam ausentes. Uma medição pode ser perdida em uma falha de comunicação; um timeout após possível gravação deixa o resultado desconhecido. O Bearer por HTTP permanece restrito a credencial descartável exclusiva do laboratório em rede confiável; produção exige HTTPS.

A última suíte PHPUnit executada nesta etapa teve **44 testes, 146 asserções, zero falhas e zero ignorados**. Esse é um resultado anterior, não uma nova execução realizada para esta atualização documental.

Os milestones 5 e 6 têm conclusão funcional com ressalvas no recorte validado: transmissão autenticada e leitura física de um DS18B20 integradas. Como o repositório não apresenta todos os critérios de aceite desses milestones, não foi declarado seu encerramento formal nem alterada a contagem de 4/9 concluídos. Dois sensores e operação prolongada não foram comprovados por esse teste.

Esse registro físico corresponde ao contrato original de dois campos. A evolução atual acrescenta os metadados documentados acima e tem validação local automatizada; seu fluxo físico básico foi posteriormente confirmado no registro dos metadados abaixo. `received_at` continua representando o recebimento no servidor.

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

Eventos de solicitação, nova referência, perda de rede e expiração são registrados sem credenciais; não há mensagens de espera a cada passagem pelo loop. O POST não aguarda NTP: envia o contrato enriquecido mesmo em `unknown` ou `estimated`, usando o snapshot original. Em `unknown`, `measured_at` é `null`. O contrato legado continua permitido, com metadados nulos.

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

Na validação NTP acima, o horário e a qualidade eram somente internos e o POST tinha dois campos. A implementação atual já envia e persiste esses metadados no contrato enriquecido, validado localmente e com fluxo físico básico posteriormente confirmado pelo usuário. Medições feitas sem Wi-Fi continuam sem armazenamento para reenvio; `received_at` permanece gerado no PostgreSQL.

Durante os testes também foram observados `connection refused` e `read Timeout` em HTTP, sinais de aproximadamente **-88 e -92 dBm** em algumas varreduras e um ponto mesh da cozinha desligado durante parte do período. São observações para diagnóstico: podem contribuir para a instabilidade, mas não demonstram a causa de todas as falhas HTTP nem sua resolução definitiva. A regularidade observada após aproximar o ESP32 do roteador não comprova estabilidade contínua por períodos prolongados.

### Continuidade após a evolução do contrato

O contrato de metadados está implementado conforme esta documentação; o nome definitivo para o tempo de atividade é `uptime_ms`. A migração 002 foi aplicada manualmente ao desenvolvimento e o fluxo físico básico do novo firmware foi confirmado pelo usuário. Antes do dashboard, permanecem os filtros por datas e a apresentação do histórico, já consultável por paginação. Armazenamento offline e retransmissão continuam fora desta etapa. Nenhum milestone inteiro foi declarado concluído por esses testes locais; permanecem as ressalvas do `AGENTS.md`.

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

`setup-tests` consulta a configuração resolvida por `docker compose config --format json`, sem exibi-la. Usa o usuário e a senha do serviço `postgres`, o host `127.0.0.1` e a porta publicada pelo Compose. Não solicita senha e não grava credenciais em arquivos adicionais. Conecta ao banco administrativo `postgres` apenas para consultar a existência do banco de testes e criá-lo quando necessário; aplica `001_temperature_readings.sql` e `002_measurement_metadata.sql`, na ordem e em transação, somente no banco isolado. O usuário precisa de permissão CREATE DATABASE apenas se esse banco ainda não existir.

`composer test` repete a preparação e inicia o PHPUnit, transmitindo `TEST_DB_HOST`, `TEST_DB_PORT`, `TEST_DB_NAME`, `TEST_DB_USER` e `TEST_DB_PASSWORD` ao processo filho. Não é necessário exportar essas variáveis manualmente. As variáveis não permanecem no terminal depois do comando. A suíte inclui testes HTTP e de integração da gravação e da consulta; os testes da consulta usam tabelas temporárias na conexão isolada para preservar registros existentes.

Overrides no ambiente do terminal têm precedência: `TEST_DB_*` → `DB_*` → configuração do Compose. O nome de testes usa `TEST_DB_NAME` ou `refrigerador_test`, nunca `DB_NAME`. Deve terminar em `_test` e ser diferente do banco principal configurado em `DB_NAME`, do banco principal do Compose e de `refrigerador`. O `.env` é interpretado pelo Compose; variáveis `TEST_DB_*` escritas nele não são exportadas ao PHP automaticamente.

A preparação pode ser repetida: não apaga bancos, tabelas ou dados. A migração incremental acrescenta o esquema esperado; `IF NOT EXISTS` não corrige estruturas divergentes arbitrárias. Os testes usam transações com rollback e tabelas temporárias para os novos cenários, sem limpar dados existentes. Sequências de IDs podem avançar, comportamento normal do PostgreSQL. Use o banco de testes exclusivamente durante a suíte para evitar interferência nas contagens.

Se a conexão, configuração ou preparação falhar, o comando termina com erro antes de iniciar a suíte; não transforma indisponibilidade em sucesso com integração ignorada. Confira se Docker está ativo, se o PostgreSQL está pronto, se `pdo_pgsql` está habilitado e se as credenciais correspondem ao volume existente. Não use `docker compose down -v` para resolver problemas: ele pode excluir dados.

Para executar apenas a suíte sem preparação automática:

```sh
php vendor/bin/phpunit
```

Nesse comando direto, os testes de integração continuam sendo ignorados se as cinco variáveis `TEST_DB_*` não estiverem definidas. Os testes HTTP usam Slim diretamente e não precisam de servidor HTTP nem do ESP32. Nenhum comando de testes modifica funcionalidades da API ou dados do banco principal.

## Validação física do contrato enriquecido

O usuário confirmou o novo firmware em execução: medições com time_quality=synced, identificador exclusivo, POST HTTP 201 e GET /health HTTP 200. O GET de temperaturas recuperou os novos campos e manteve registros legados com metadados nulos. A migração 002 já foi aplicada manualmente ao banco principal e sua estrutura conferida; esta revisão não executa alterações nesse banco.

Registro observado: id 275, dispositivo geladeira_01, sensor refrigerator, temperatura 21.8125 °C, measured_at 2026-10-15T02:24:03Z, received_at 2026-10-15T02:24:04.885438Z, qualidade synced, RSSI -70 dBm, uptime_ms 330373 e observation null. O intervalo foi aproximadamente 1,89 segundo. Os timestamps são UTC; a data UTC não deve ser confundida com a data local.

Isso comprova o fluxo físico básico ESP32 → API → PostgreSQL → GET, conforme evidência fornecida pelo usuário. Não certifica testes físicos de todos os conflitos, retransmissões ou falhas nem estabilidade prolongada. As falhas HTTP intermitentes continuam pendentes. Medições sem Wi-Fi não são armazenadas para reenvio. Produção exige HTTPS; laboratório HTTP usa somente Bearer descartável e exclusivo, mantido nos arquivos privados.

## Próxima etapa: processo de migrações

Toda alteração futura da estrutura PostgreSQL deverá estar em migrações versionadas, reproduzíveis e testadas. A próxima tarefa será um processo simples que execute versões pendentes em ordem, registre versões aplicadas, evite reaplicações indevidas, reconstrua instalações limpas e preserve dados existentes. Deverá oferecer comandos Composer e documentação de instalação, backup e restauração. Atualmente existem os scripts SQL e a preparação de testes; não há gerenciador de versões aplicadas. Nenhum gerenciador foi implementado nesta revisão. Dashboard, armazenamento offline e lotes permanecem etapas posteriores.
