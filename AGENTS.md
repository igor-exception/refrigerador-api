Projeto Refrigerador IoT — instruções de desenvolvimento
1. Contexto e escopo
O Projeto Refrigerador IoT é um laboratório educacional e projeto de portfólio na área de Refrigeração e Climatização.
Um ESP32 com dois sensores DS18B20 medirá as temperaturas do congelador e do compartimento refrigerado. As medições serão enviadas por Wi-Fi e HTTPS a uma API PHP, responsável por autenticação, validação, persistência no PostgreSQL e consultas posteriores.
Fluxo previsto: sensores DS18B20 → ESP32 → API PHP com Slim → PostgreSQL → dashboard MVP v0.1 (detalhes na seção 15).
Estas instruções distinguem funcionalidades implementadas de propostas futuras. Antes de implementar, examinar o código, o composer.json e os arquivos relacionados; não presumir que o repositório esteja vazio ou que planos já foram implementados.
2. Stack e ambiente definidos
- PHP 8.4, executado diretamente no Windows nesta fase.
- Slim Framework 4 e Slim PSR-7 para roteamento e processamento HTTP.
- Composer para gerenciamento de dependências.
- PDO com PostgreSQL como banco principal em desenvolvimento e produção.
- PostgreSQL 17 local via Docker Compose; o ambiente local já possui essa configuração.
- PHPUnit 13 para testes automatizados.
- Git e GitHub.
- Windows e PowerShell no desenvolvimento atual; scripts PHP/Composer de testes planejados para funcionar também em Linux e macOS (ainda não validados nesses sistemas).
- scripts/setup-tests.php para preparação automatizada do banco de testes isolado.
Não substituir a stack sem aprovação expressa. Não introduzir Laravel, Herd, SQLite, ORM ou frameworks adicionais.
3. Arquitetura e simplicidade
Utilizar a solução mais simples que atenda corretamente aos requisitos de funcionamento, segurança, testes e manutenção. O projeto deve permanecer pequeno enquanto seus requisitos permitirem.
- Favorecer PHP simples, legível e fácil de depurar.
- Separar responsabilidades quando necessário.
- Evitar classes, interfaces e abstrações sem justificativa.
- Evitar duplicação significativa sem abstrair prematuramente.
- Não introduzir arquitetura hexagonal, DDD, microsserviços, filas ou sistemas de eventos sem necessidade concreta.
- Não criar diretórios, arquivos, tabelas ou colunas antecipadamente.
- Explicar o motivo e obter aprovação antes de instalar dependências.
4. Situação implementada e evolução do MVP
Já implementado e validado localmente (14/10/2026)
- GET /health retorna HTTP 200 e JSON {"status":"ok"}.
- POST /api/v1/temperaturas recebe uma medição JSON por requisição. Mantém o legado sensor_id (freezer ou refrigerator) e temperature numérica, e aceita o contrato enriquecido definido abaixo.
- O POST exige o mesmo Bearer token, valida a requisição e grava via PDO. Nova medição retorna 201; repetição equivalente do contrato enriquecido retorna 200 e a linha original; mesmo identificador com conteúdo diferente retorna 409, sem sobrescrita.
- sql/001_temperature_readings.sql cria a tabela original; sql/002_measurement_metadata.sql acrescenta metadados, restrição de consistência e unicidade de (device_id, measurement_id), preservando IDs, received_at e todos os registros históricos.
- A persistência real foi confirmada em consulta ao PostgreSQL local.
- GET /api/v1/temperaturas é público, retorna HTTP 200 com data (até 50 medições, ordenadas por received_at DESC, id DESC), id e todos os campos do contrato enriquecido; data: [] quando vazio; falha retorna 503 genérico. A consulta original e o retorno dos novos metadados foram validados fisicamente pelo usuário; o contrato também foi verificado em integração no banco de testes. Paginação opcional before_id usa o ID do último item anterior e compara (received_at, id), com até 50 linhas por página; cursor inválido retorna 422 e inexistente retorna lista vazia. Filtros por datas permanecem pendentes.
- Evolução do contrato implementada e testada localmente sobre o checkpoint 244213e: device_id, measurement_id, measured_at, time_quality, wifi_rssi, uptime_ms e observation. O banco refrigerador_test recebeu a migração; o banco refrigerador não foi alterado automaticamente. O usuário confirmou a aplicação manual de 002 ao banco refrigerador e a conferência da estrutura. O fluxo físico básico do contrato enriquecido foi validado conforme registro abaixo.
Firmware e comunicação ESP32 → API — validação física confirmada pelo usuário em 14/10/2026
- Firmware em firmware/esp32/esp32.ino, utilizado com Arduino IDE 2.3.10 e placa ESP32 Dev Module.
- DS18B20 funcionando no GPIO 4, com OneWire e DallasTemperature; leituras a cada 10 segundos por millis() e conversão assíncrona.
- Wi-Fi TESLA conectado; diagnóstico opcional implementado, ativável pela configuração local e desativável para uso normal.
- ESP32 recebeu HTTP 200 de GET /health na API LAN, acessível em 192.168.1.81:8080 durante os testes. Esse IP é configuração local alterável, não um endereço permanente.
- Fluxo físico DS18B20 → ESP32 → Wi-Fi → POST autenticado → API Slim → PostgreSQL → GET confirmado pelo usuário: uma medição válida por POST, inicialmente a cada 10 segundos, com sensor_id explicitamente configurado e contrato atual preservado.
- HTTP 201 confirmado no Monitor Serial; medições reais recuperadas por GET /api/v1/temperaturas, incluindo registros consecutivos com intervalos próximos de 10 segundos. Conexões aceitas nos logs do servidor PHP, isoladamente, não comprovam gravação.
- Houve melhora observada nas transmissões após aproximar o ESP32 do roteador. Relatos anteriores de connection refused e read Timeout não devem ser considerados definitivamente resolvidos: a comunicação não foi validada continuamente por períodos prolongados.
- Não há armazenamento offline ou reenvio automático; falhas podem resultar em perda de medições, e timeout após possível gravação deixa o resultado desconhecido. A API enriquecida já garante idempotência para futuras retransmissões que preservem o ID e o conteúdo originais. RTC externo não está implementado; dashboard v0.1 descrito na seção 15; received_at continua sendo gerado no PostgreSQL.
- NTP/SNTP e seu snapshot no início da conversão foram preservados. Qualidades unknown (sem referência desde o boot), synced (referência recente) e estimated (referência antiga) independem do Wi-Fi. Configuração normal: NTP_SYNC_INTERVAL_MS=3600000UL (1 hora) e TIME_REFERENCE_MAX_AGE_MS=21600000ULL (6 horas). O contrato enriquecido transmite o snapshot original, sem recálculo após sincronização; em unknown, measured_at é null. A configuração privada não foi sobrescrita nesta evolução.
- Validação física NTP confirmada pelo usuário em ESP32 real: unknown → synced → estimated → synced. Sem Wi-Fi desde o boot, horário indisponível e DS18B20 medindo; após referência NTP válida, UTC disponível e synced. Com limite experimental de 60000ULL, a referência expirou em aproximadamente 60 segundos sem atualização, passando para estimated enquanto UTC e medições continuavam. Ao retornar o Wi-Fi, nova referência restaurou synced sem reiniciar a placa. O limite de 60 segundos foi exclusivo do teste, não da operação normal; a qualidade e o horário de snapshots anteriores não são corrigidos retroativamente.
- Os testes físicos aprovaram as transições de qualidade e a recuperação; não mediram a precisão do relógio durante longos períodos sem sincronização nem comprovaram estabilidade contínua da comunicação HTTP.
- Diagnóstico pendente: connection refused e read Timeout em HTTP, RSSI observado de aproximadamente -88 e -92 dBm e ponto mesh da cozinha desligado durante parte dos testes. Esses fatores podem contribuir para instabilidade, mas a causa de todas as falhas HTTP não foi demonstrada.
Exemplo do contrato legado, ainda aceito (metadados ausentes retornam null, sem idempotência):
{
  "sensor_id": "freezer",
  "temperature": -18.2
}
Contrato enriquecido atual (exemplo sintético, não uma captura de credenciais ou do dispositivo):
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
- device_id: ASCII de 1–64 caracteres; measurement_id: 1–128. Ambos começam com letra ou dígito e depois admitem letras, dígitos, ponto, underscore, dois-pontos e hífen. O dispositivo é configurável por DEVICE_ID, inicialmente geladeira_01.
- measured_at: ISO 8601 UTC com Z ou +00:00, data real de ano 0001–9999 e até 6 casas decimais; obrigatório para synced/estimated e null para unknown. A API não corrige timestamps ou qualidades anteriores. received_at é exclusivamente do servidor.
- wifi_rssi: inteiro de -127 a 0 dBm ou null; uptime_ms: inteiro de 0 a 9007199254740991, armazenado em BIGINT; observation: texto opcional de até 1024 caracteres Unicode sem NUL, padrão null. Temperature continua numérica e finita, sem limites alimentares arbitrários.
- Qualquer metadado reconhecido no POST ativa a validação enriquecida: todos são obrigatórios, exceto observation. Campos desconhecidos ou received_at são rejeitados nesse formato. Legado mantém seu comportamento anterior; não se inventam identificadores, qualidade, horários, RSSI ou uptime para ele ou para o histórico.
- O firmware gera UUID v4 por boot com esp_fill_random após habilitar Wi-Fi, mais contador de 64 bits por medição. Não depende de NTP; colisões entre boots são extremamente improváveis. Futuras retransmissões devem preservar o identificador e todo o snapshot. Não há fila ou retransmissão implementada.
Ainda pendente de implementação ou decisão
- Suporte a lotes com uma ou várias medições em uma requisição.
- Armazenamento offline, retransmissão e política de confirmação de lotes; idempotência por medição já implementada no formato enriquecido.
- Diagnóstico das falhas HTTP intermitentes, validação prolongada e filtros por datas para o futuro dashboard.
- Evolução de um sensor DS18B20 inicial para dois sensores e intervalos independentes de medição/transmissão.
O formato abaixo é somente um exemplo conceitual para lotes futuros, e não representa o contrato atualmente aceito pela API:
{
  "device_id": "geladeira_01",
  "readings": [
    {
      "measurement_id": "geladeira_01-000001",
      "timestamp": "2026-10-13T19:00:00-03:00",
      "freezer": -18.2,
      "refrigerator": 4.8
    }
  ]
}
Não definir unilateralmente campos, limites, políticas de erro, novas rotas ou mudanças de contrato. A implementação futura deverá continuar incremental e testada.
Próximos assuntos planejados — ainda não implementados nem autorizados por esta atualização documental
- O processo simples de migrações já está implementado conforme seção abaixo. Backup/restauração e adoção do banco principal foram confirmados pelo usuário em 15/10/2026, conforme seção 14; não repetir no principal sem nova autorização. O nome definitivo do tempo de atividade é uptime_ms, não uptime. Evoluções do dashboard além da v0.1, filtros por datas, filas offline e novas evoluções de contrato permanecem sujeitos a aprovação específica.
- Preservar a avaliação de segurança do Bearer por HTTP local: usar somente credencial descartável e exclusiva do laboratório, nunca permanente ou de produção. Credenciais devem permanecer exclusivamente em arquivos locais ignorados pelo Git, sem aparecer em logs ou documentação; HTTPS continua obrigatório em produção.
5. Segurança obrigatória
A API será exposta à internet. Aplicar os seguintes requisitos:
- Exigir HTTPS em produção.
- Autenticar dispositivos nos endpoints de gravação; não permitir gravação pública sem autenticação.
- Validar estrutura, tipos, valores e timestamps.
- Estabelecer limites para lotes e tamanho de requisições, com valores aprovados antes da implementação.
- Usar consultas SQL parametrizadas com PDO e prepared statements.
- Evitar exposição de detalhes internos nas respostas de erro.
- Registrar erros úteis sem revelar informações sensíveis.
- Utilizar variáveis de ambiente para credenciais; nunca incluir senhas, tokens ou segredos no repositório.
- Restringir recursos administrativos e internos e utilizar permissões mínimas no banco.
- Avaliar controle de acesso para endpoints de consulta.
- Evitar dependências vulneráveis ou sem manutenção.
Não introduzir autenticação complexa sem necessidade. O POST atual usa Bearer token; qualquer mudança de autenticação ou credenciais exige discussão prévia.
6. Banco de dados e integridade
- Acessar PostgreSQL por PDO, conforme as regras de consultas parametrizadas.
- Toda alteração futura na estrutura PostgreSQL deve estar em migração versionada, reproduzível e testada. Não fazer alterações estruturais avulsas sem o SQL correspondente.
- Utilizar restrições apropriadas para integridade e unicidade.
- Tratar timestamps e fusos horários corretamente.
- Usar configurações distintas para desenvolvimento, testes e produção.
- Não modificar diretamente o banco de produção.
- Nunca executar testes destrutivos no banco de produção.
A tabela atual já está implementada. Alterações futuras de modelagem deverão ser aprovadas antes da implementação. A política de transações, confirmação parcial ou rejeição integral de lotes ainda precisa ser discutida.
7. Testes e verificação
Utilizar PHPUnit desde as primeiras funcionalidades. Quando pertinente, cobrir:
- Comportamento esperado, entradas inválidas e falhas relevantes.
- Autenticação e validação de temperaturas, formato, quantidade de medições e timestamps.
- Persistência, integridade transacional e duplicidades.
- Respostas JSON e códigos HTTP.
Preferir testes pequenos e determinísticos. Separar testes unitários e de integração quando fizer sentido e utilizar banco de testes isolado para persistência. Não usar serviços de produção em testes.
O ESP32 não precisa estar conectado ao computador para desenvolver ou testar a API. Simular requisições com ferramentas HTTP e testes automatizados, sem depender do dispositivo físico.
Executar os testes afetados por cada alteração. Nunca afirmar que passaram sem executá-los; informar claramente qualquer impedimento de execução.
Automação de testes já implementada — Tarefa 03
- Banco principal de desenvolvimento: refrigerador; banco isolado para testes: refrigerador_test, ambos no PostgreSQL 17 via Docker Compose.
- scripts/setup-tests.php prepara o banco isolado e usa o gerenciador para aplicar somente versões pendentes, com transação por versão e registro atômico, sem apagar dados existentes. Banco de testes antigo sem controle exige baseline explícito; não adotar silenciosamente.
- composer setup-tests executa a preparação; composer test prepara o banco e executa PHPUnit com variáveis TEST_DB_HOST, TEST_DB_PORT, TEST_DB_NAME, TEST_DB_USER e TEST_DB_PASSWORD.
- O script usa a configuração local do Docker Compose e tem proteção contra seleção do banco principal. Antes de alterar esse script, inspecionar como essa proteção funciona e mantê-la.
- Última suíte executada na evolução local dos metadados em 14/10/2026: composer test no Windows, com TEST_DB_NAME=refrigerador_test, 124 testes aprovados, 461 asserções, zero ignorados e zero falhas. Abrange o legado, novos metadados, limites, idempotência nos três estados temporais, conflito sem sobrescrita, paginação estável e migração sobre registros históricos, aplicada duas vezes em tabela temporária. Firmware compilado para ESP32 Dev Module, core 3.3.12, com configuração de exemplo sem segredos: 953832 bytes de flash e 49008 bytes de variáveis globais. Esse registro não substitui verificações futuras. A validação física básica do contrato enriquecido foi posteriormente confirmada pelo usuário; idempotência e conflitos foram validados por testes automatizados, sem alegar teste físico desses cenários.
- A preparação repetida foi relatada como bem-sucedida pelo Codex. Ainda não foram validados: execução em Linux/macOS e criação automática de um banco de testes inexistente (o banco já havia sido criado manualmente).
- Mensagens 404 Not Found, Slim Application Error e Falha ao persistir medição de temperatura podem ser emitidas em cenários negativos de teste mesmo com a suíte aprovada; verificar resultado real do PHPUnit antes de classificá-las como regressão.
- Não executar testes de integração no banco refrigerador ou em produção. Não remover controles de segurança para simplificar a automação.
Inicialização local da API — Milestone 4
- scripts/serve.php e composer serve foram implementados; o script resolve configurações via Compose sem imprimir credenciais, fixa DB_NAME=refrigerador e sobe o servidor PHP em 127.0.0.1:8080.
- O comando recusa iniciar quando a porta 8080 está ocupada. Validado GET /health e GET /api/v1/temperaturas HTTP 200.
- composer serve-lan reutiliza scripts/serve.php, com escuta padrão em 0.0.0.0:8080 ou IP configurado por --host; composer serve permanece em 127.0.0.1:8080. Usar LAN somente em rede doméstica confiável, sem tratar o servidor PHP de desenvolvimento como produção.
- serve e serve-lan executam Composer\Config::disableProcessTimeout antes de iniciar o servidor. A correção é localizada nesses scripts, preserva --host e não altera timeouts dos demais scripts nem das requisições HTTP do ESP32. Ambos foram validados com limite temporário reduzido; não houve espera de 300 segundos nessa validação.
- Não utilizar refrigerador_test para servir a API local.
Reconstrução documentada do ambiente
Pré-requisitos atuais: Git, PHP com extensões necessárias, Composer e Docker/Compose. Após clonar e configurar .env local sem versionar segredos:
composer install
docker compose up -d postgres
composer setup-tests
composer test
O script de testes não instala PHP, Composer ou Docker e não substitui backup/restauração do banco principal.
8. Escopo autorizado e desenvolvimento incremental
Trabalhar uma etapa por vez. Não desenvolver toda a API em uma única interação nem antecipar funcionalidades futuras por conveniência. O agente não possui autorização permanente para modificar qualquer parte da aplicação.
Para cada solicitação:
1. Identificar o escopo solicitado e examinar o código relacionado.
2. Implementar apenas o escopo autorizado, evitando alterações não relacionadas.
3. Criar ou atualizar testes relevantes e executar as verificações possíveis.
4. Apresentar os resultados e eventuais limitações.
Solicitar aprovação antes de mudanças arquiteturais significativas, novas dependências, alterações destrutivas ou mudanças fora do escopo. Não realizar commits, pushes, deploys, exclusão de dados ou comandos destrutivos sem autorização expressa.
Em instalações e configurações manuais, fornecer uma ação por vez, explicar brevemente sua finalidade e aguardar o resultado. Em tarefas de programação autorizadas, concluir o escopo combinado e seus testes sem interromper desnecessariamente a execução.
9. Infraestrutura e produção previstas
A implantação em produção será discutida posteriormente. Heroku é a primeira opção prevista, ainda não uma decisão definitiva de hospedagem. No desenvolvimento atual, somente o PostgreSQL roda em contêiner; PHP, Composer e PHPUnit são executados no host. Colocar PHP/Composer/testes também no Docker é possibilidade futura, não tarefa autorizada nem prioridade imediata.
- Manter compatibilidade com PostgreSQL e evitar acoplamento desnecessário ao provedor.
- Respeitar variáveis de ambiente e preservar o isolamento entre arquivos públicos e configurações privadas.
- Não implementar deploy sem solicitação.
- Não presumir disponibilidade de créditos ou serviços externos.
- Considerar procedimentos futuros de backup e restauração.
10. Funcionalidades fora do escopo inicial
Não implementar antecipadamente:
- Evoluções do dashboard além da v0.1 e gráficos adicionais, salvo autorização específica.
- Notificações e alertas.
- Funcionalidades futuras do firmware ESP32 além da leitura, Wi-Fi, diagnóstico, GET /health, POST autenticado de uma temperatura e NTP com qualidade temporal interna já implementados, salvo autorização específica.
- Persistência local no ESP32, possivelmente com LittleFS.
- RTC.
- Monitoramento elétrico.
- Integrações.
11. Decisões pendentes
Discutir e aprovar antes das respectivas implementações:
- Novas rotas ainda não implementadas e eventuais mudanças no GET /api/v1/temperaturas já público e funcional.
- Contrato JSON, campos, tipos e formato de timestamps.
- Limites de temperaturas, quantidade de medições e tamanho de requisições.
- Eventuais mudanças no mecanismo de autenticação Bearer já existente e na gestão de credenciais.
- Controle de acesso para consultas.
- Evolução da modelagem atual e estratégia de identificação e unicidade das medições para lotes.
- Política de transações, confirmação de lotes, tratamento de duplicidades e respostas de erro.
- Hospedagem e procedimentos de deploy, backup e restauração.
12. Comunicação e aprendizado
Utilizar português brasileiro nas explicações e preservar identificadores técnicos e nomes de bibliotecas conforme as convenções do projeto.
O desenvolvedor possui experiência anterior com PHP, APIs e bancos de dados e está retomando conhecimentos técnicos. Ajudar na compreensão do sistema sem explicar programação como para um iniciante absoluto.
Ao finalizar uma implementação, informar os arquivos criados ou modificados, a funcionalidade implementada, os testes executados e seus resultados, como testar manualmente pelo PowerShell e as limitações ou decisões pendentes. Explicar o percurso da requisição pela aplicação quando pertinente.
13. Estado do Git em 14/10/2026
Registro anterior: commits 89ae94c (testes), 1d8da75 (AGENTS), ba0fd47 (consulta GET + composer serve) e fb23e63 (atualização AGENTS.md) confirmados em origin/main em 14/10/2026.
Na inspeção documental de 14/10/2026, HEAD era aaa8ae2 (correção atualização AGENTS.md), na branch main com referência de acompanhamento origin/main e sem indicação local de commits à frente ou atrás. Havia alterações não commitadas em .gitignore, AGENTS.md, README.md, composer.json e scripts/serve.php, além de firmware/ ainda não rastreado. Esta atualização altera somente AGENTS.md e README.md, preservando as alterações anteriores; não houve consulta remota para confirmar publicação dessas alterações. Não realizar push ou commit automaticamente.
Registro posterior: checkpoint da integração ESP32 → API criado em 4912a13. Na preparação do checkpoint NTP, main e origin/main estavam nesse commit, sem divergência após git fetch; as alterações pendentes eram AGENTS.md, README.md, firmware/esp32/esp32.ino e firmware/esp32/config.example.h, todas relacionadas à etapa NTP. Credenciais e configurações privadas permanecem fora do versionamento. Commit e push continuam exigindo autorização específica.
Roadmap: mantido o registro de 4/9 milestones formalmente concluídos (44%, por contagem). O repositório não contém uma lista completa de critérios de aceite dos milestones 5 e 6 que permita certificar seu encerramento integral.
- Milestone 5: conclusão funcional com ressalvas do envio autenticado ESP32 → API → PostgreSQL e recuperação pelo GET, conforme a validação física relatada. Não declarado formalmente concluído; a estabilidade por períodos prolongados permanece não validada.
- Milestone 6: leitura física do DS18B20 no GPIO 4 e integração dessa leitura ao fluxo comprovadas, com testes de hardware adiantados. Conclusão funcional do recorte testado com ressalvas, sem declarar encerramento integral ou funcionamento de dois sensores.
Checkpoint NTP 244213e confirmado em main/origin/main antes desta evolução. Os metadados estão implementados e testados localmente, com fluxo físico básico confirmado pelo usuário e migração 002 aplicada manualmente ao banco principal. Nenhum milestone inteiro é declarado concluído por esses testes, nem seus critérios foram alterados. Não implementar as próximas etapas sem aprovação e não realizar commit ou push desta evolução sem autorização específica.

Validação física básica do contrato enriquecido — relato confirmado nesta revisão
- Monitor Serial: time_quality=synced, measurement_id exclusivo por leitura, POST HTTP 201 e GET /health HTTP 200. GET de temperaturas retornou metadados reais e registros legados com null.
- Registro observado: id 275, device_id geladeira_01, sensor refrigerator, 21.8125 °C, measured_at 2026-10-15T02:24:03Z e received_at 2026-10-15T02:24:04.885438Z (aproximadamente 1,89 s depois), RSSI -70 dBm, uptime_ms 330373 e observation null. O horário UTC cruza para 15/10; não se deve reinterpretá-lo como data local.
- Evidência fornecida pelo usuário confirma o fluxo básico ESP32 → API → PostgreSQL → GET. Não comprova todos os cenários de idempotência/falha fisicamente nem estabilidade prolongada. Persistem falhas HTTP intermitentes e ausência de armazenamento/reenvio de medições sem Wi-Fi. Milestones permanecem 4/9 formalmente concluídos.
- Checkpoint desta etapa autorizado expressamente: commit das alterações de metadados e documentação, seguido de push normal de main para origin/main, condicionado às verificações. Essa autorização não se estende a novas etapas.

14. Migrações versionadas — implementação local em 15/10/2026
- Checkpoint de partida ba24be660889854fc118393fcd0a4d6aa7f54a14. Implementação inicialmente sem commit/push; o fechamento posterior autorizou expressamente commit e push normal de main para origin/main após verificações. API, firmware, SQLs 001/002 e configurações privadas foram preservados.
- migrations.php implementa executor PDO; scripts/db.php expõe composer db:status, db:migrate e db:baseline. Usa Compose/.env sem imprimir credenciais. Destino padrão fixo refrigerador; --test seleciona TEST_DB_NAME ou refrigerador_test, com guardas de isolamento. Nenhum modo de escrita foi executado no principal nesta tarefa.
- SQLs sql/NNN_nome.sql são ordenados por versão de três dígitos. 001 é a migração inicial, 002 a incremental existente, ambas sem alterações. Criar novas versões para mudanças futuras; não editar arquivos aplicados. SHA-256 normaliza somente CRLF para LF e detecta alterações, arquivos ausentes e histórico fora de ordem.
- schema_migrations registra versão única, arquivo, checksum, applied_at e método migration/baseline. SQL e registro são confirmados atomicamente em transação por versão. Trava advisory de sessão por banco recusa execuções concorrentes; lock_timeout de 5 segundos limita espera por locks de tabelas. Não há rollback destrutivo automático. SQLs futuros devem ser transacionais, sem COMMIT/BEGIN/ROLLBACK ou CREATE INDEX CONCURRENTLY.
- db:status usa transação somente leitura, não cria tabelas. Sem registro, indica versões pendentes, sem inferir ausência física. db:migrate recusa esquema existente sem histórico e nunca faz adoção silenciosa.
- db:baseline exige --confirm=NOME_DO_BANCO e reconhece somente o esquema manual completo 001+002. Compara o esquema real ao produzido pelos arquivos em um esquema de referência revertido por savepoint, incluindo colunas, tipos, defaults, identidade, collation, constraints, índices, sequência e RLS/objetos relevantes. Lock exclusivo impede mudanças durante a verificação; nenhuma medição ou valor corrente de sequência é alterado. Exige CREATE SCHEMA no banco. Diferenças ou esquema parcial exigem revisão humana, sem reparo automático ou registro de sucesso.
- Baseline é conservador: nomes/expressões diferentes podem ser recusados mesmo quando equivalentes. Não compara proprietário, ACL, armazenamento físico ou valor corrente da sequência e não valida política de produção. Migrações posteriores a 002 continuam pendentes após baseline. Baseline repetido é recusado.
- O usuário confirmou baseline explícito no principal refrigerador, com 001/002 aplicadas pelo método baseline e 460 registros preservados. O agente não repetiu baseline nem migrações no principal durante o fechamento. refrigerador_test também foi adotado deliberadamente para a validação automatizada. Testes do gerenciador criam/removem apenas esquemas exclusivos migration_test_* nesse banco; não limpam public. Foi criado um banco exclusivo refrigerador_migration_cli_1792033323_test para validar os comandos reais em instalação vazia; permaneceu disponível, sem exclusão.
- Migrações reconstroem somente a estrutura; medições perdidas exigem backup/pg_dump e restauração PostgreSQL. README documenta backup sem redirecionamento binário PowerShell e restauração em banco vazio separado. Dumps são privados; *.dump e *.backup são ignorados. O usuário confirmou backup Custom copiado para fora do contêiner e restaurado em refrigerador_restore_test: original e restaurado conferidos com 460 registros cada. Essas operações não foram repetidas pelo agente.
- Pendências: validação Linux/macOS e repetição periódica dos testes de recuperação; baseline e restauração básicos já confirmados pelo usuário. Registro da etapa de migrações: milestones não alterados e lotes/offline não implementados; dashboard v0.1 adicionado posteriormente na seção 15.

- Validação executada nesta etapa: composer test em refrigerador_test, 144 testes aprovados, 536 asserções, zero falhas/ignorados; sintaxe PHP aprovada. Comandos Composer de status, migrate (duas vezes em banco novo) e baseline testados realmente em bancos isolados. Composer validate aprovado com aviso preexistente de licença ausente. Validação Linux/macOS permanece pendente. Restauração básica confirmada pelo usuário em banco isolado.

- Validação prática adicional confirmada pelo usuário em 15/10/2026: banco vazio refrigerador_migration_manual_test recebeu 001/002 por composer db:migrate -- --test; a segunda execução não reaplicou versões. No principal, composer db:baseline -- --confirm=refrigerador foi seguido por db:status com método baseline para ambas. Contagem final informada: 460 registros preservados. Essas evidências são relato do usuário, separado da suíte executada pelo agente. Não apagar contêineres, volumes ou bancos de testes neste fechamento.

- Fechamento em 15/10/2026: suíte reexecutada com 144 testes e 536 asserções, zero falhas/ignorados; sintaxe PHP e git diff --check aprovados. db:status consultado em READ ONLY confirmou 001/002 por baseline no principal e no teste. Backup local .backup preservado e ignorado; nenhum banco, contêiner ou volume excluído. Commit e push desta etapa autorizados expressamente pelo usuário, condicionados às verificações; sem autorização permanente para etapas futuras.

15. Dashboard MVP v0.1 — implementação local em 15/10/2026
- Checkpoint de partida d3115c360b57c07315c8cad313ba806eddb46e5f. Implementação inicialmente autorizada sem commit/push; o usuário aprovou posteriormente a validação visual/funcional com dados reais e autorizou este checkpoint com push normal. Milestones não foram alterados.
- GET /dashboard entrega HTML semântico; CSS/JS locais /assets/dashboard.css e .js, sem bibliotecas/CDNs. Fetch consulta GET público /api/v1/dashboard/heatmap, sem token, com sensor_id opcional refrigerator (padrão) ou freezer; não mistura sensores, mas agrega todos os dispositivos desse sensor, explicitado na interface. Filtro por dispositivo fica futuro.
- Quatro grades simultâneas, exatamente 60 células cada: 10 s, 30 s, 60 s e 300 s. Ordem crescente, da esquerda para a direita, linha a linha; desktop 30 colunas, móvel 15. Escala experimental fixa 18–24 °C com centro em 21; extremos saturam só a cor. Não é limite de segurança alimentar. Células vazias são cinza/texturizadas, com média/min/max/última null e count 0, sem interpolação.
- dashboard.php faz uma única consulta parametrizada: CTEs, generate_series, agregação PostgreSQL e join pelas últimas IDs. Não carrega todo o histórico para o navegador; não cria tabelas nem altera SQLs versionados, firmware ou dados. Índice existente sustenta a última medição por received_at/id. measured_at não possui índice: volume histórico grande pode exigir revisão de plano/índice em nova migração aprovada. Tabela de agregados pré-calculados é otimização futura, não implementada.
- Buckets UTC alinhados à época Unix, semiabertos [início,fim), 59 fechados + atual parcial, portanto o fim nominal pode estar até um bucket no futuro (não são dados previstos). Somente instantes <= generated_at são agregados. Cada registro entra uma vez por seção. UI mostra America/Sao_Paulo.
- Usa measured_at com synced/estimated; legado e unknown usam received_at, explicitado por time_source e received_at_count. Atraso de transmissão não muda o horário do bucket. Exclui horário efetivo futuro/não finito e measured_at > received_at + 60 s; sinaliza excluded_temporal_count nos candidatos recentes, sem apagar ou corrigir registros. Não tenta corrigir relógios nem inferir falha de internet. A tolerância de 60 s cobre discrepância entre relógios, mas tempo efetivo futuro ainda fica excluído até o servidor alcançá-lo.
- Card mostra a última recebida, mesmo antiga, ambos timestamps, qualidade e idade. Mais de 60 s desde recebimento (seis intervalos nominais de 10 s) indica dados desatualizados; também sinaliza inconsistências. API disponível não prova dispositivo conectado.
- Atualização 10 s sem sobreposição, timeout 10 s. Falha inicial não fabrica células cinza; falha posterior mantém snapshot anterior, idade avançando por relógio monotônico e última atualização bem-sucedida. Tooltip por hover/foco; clique/Enter/espaço abre dialog nativo com Escape/fechar. Metadados são da última medição por tempo efetivo, received_at e id, não da média. Null aparece Não disponível; DOM usa textContent, sem inserir observações como HTML.
- Validação local inclui navegação real, 240 células, detalhe com medição existente, célula vazia por teclado, layouts de 1280/390 px sem overflow horizontal, troca de sensor e falha de rede simulada interrompendo somente servidor iniciado pelo agente: preservou 240 células/valores anteriores e mostrou erro explícito. Testes JavaScript adicionais usam node:test com DOM mínimo em memória, sem bibliotecas; validação física por toque/leitor de tela e operação prolongada ainda dependem do usuário.
- Não há autenticação visual, alerta alimentar, fila offline ou novos campos no contrato de medições. Endpoint de leitura retorna 503 genérico em falha e 422 para sensor inválido. Rotas existentes, autenticação e idempotência preservadas. Aparência e funcionamento desta primeira versão foram aprovados pelo usuário para checkpoint; novas evoluções continuam exigindo autorização.
- Validação final dashboard em 15/10/2026: composer test em refrigerador_test, 152 testes aprovados, 1801 asserções, zero falhas/ignorados; sintaxe PHP, node --check e git diff --check aprovados. Recuperação da consulta após reiniciar apenas o servidor local validada no navegador. Firmware não compilado/alterado nesta tarefa porque não foi afetado. Sem commit/push.

- Ajuste autorizado após aprovação da aparência: atualização automática reduzida para 10000 ms, guard busy e timeout de 10 s preservados. Ticks durante consulta ativa são ignorados, sem concorrência. Última célula de cada grade recebe borda interna discreta e aria-current=time, sem substituir textura/cor de lacuna. Escalas 10/30/60/300 s, contagens de 60 e firmware inalterados. Testes JS: node --test tests/dashboard-refresh.test.cjs (node:test nativo, DOM mínimo em memória, sem dependências). Aprovação visual e checkpoint desta versão posteriormente confirmados pelo usuário; autorização restrita ao estado atual.

Validação do ajuste de atualização em 15/10/2026: 5 testes JavaScript aprovados (zero falhas), PHPUnit reexecutado exclusivamente em refrigerador_test com 152 testes, 1801 asserções, zero falhas e zero ignorados. Sintaxe JS/PHP e git diff --check aprovados. O agente não repetiu a validação visual específica desse ajuste; o usuário confirmou depois o funcionamento da versão e aprovou o checkpoint. Alterações anteriores do dashboard foram preservadas.

16. Fechamento do dashboard térmico MVP v0.1 — 15/10/2026
- Usuário aprovou a primeira versão funcional e a validação visual com dados reais do ESP32. Fluxo operacional: ESP32 → POST autenticado pela API servida em LAN → PostgreSQL → GET público /api/v1/dashboard/heatmap → /dashboard. Última temperatura, time_quality, idade dos dados e quatro heatmaps simultâneos, com polling de 10 s e preservação do snapshot em falhas.
- Autorização específica deste fechamento: revisar, documentar, testar e criar commit/push normal de main para origin/main. Não implementar melhorias, não alterar regras térmicas nem banco principal, firmware, migrações ou arquivos privados. Autorizações anteriores de não fazer commit/push se referem à fase de implementação; esta autorização cobre somente o checkpoint atual.
- MVP experimental aprovado, não versão final. Melhorias futuras de UX/texto: esclarecer atualização global da página a cada 10 s versus duração das células 10/30/60/300 s e históricos 10/30/60/300 min. Propostas do usuário com “por célula” são pendências de redação, não novas frequências de polling. Manter escalas atuais até nova solicitação.
- Faixas ideais por armazenamento/alimento (congelados, resfriados, carnes etc.) são planejamento, sem limites ou regras de segurança alimentar implementados. Permanecem falhas HTTP intermitentes, ausência de armazenamento offline/reenvio, operação prolongada não comprovada e futuras melhorias de UI.
- Milestones e suas ressalvas permanecem inalterados. Validação aprovada pelo usuário não comprova todos os cenários físicos de falha ou recuperação. Segredos e backups continuam privados e ignorados pelo Git.
Verificações do checkpoint em 15/10/2026: suíte PHP reexecutada em refrigerador_test com 152 testes, 1801 asserções, zero falhas/ignorados; 5 testes JavaScript aprovados. Sintaxe PHP/JS e git diff --check aprovados. Arquivos privados/backups ignorados e não rastreados; nenhum segredo local examinado encontrado nos arquivos candidatos. Revisão sem mudanças no código funcional, firmware, migrações ou banco principal. Aprovação visual com dados reais fornecida pelo usuário, sem nova alegação de teste físico executado pelo agente neste fechamento.

17. Continuidade do laboratório e orientação do Codex — 17/10/2026
Esta seção complementa os registros históricos acima e prevalece quando houver divergência de estado ou endereço de teste. Não autoriza implementação de novas funcionalidades por si só.

Estado confirmado pelo usuário em 17/10/2026:
- `git log -n 5` mostrou HEAD/main, origin/main e origin/HEAD em `632ffef2bd2162fb0c3a80ca5bb5370c5f2ab7b0`, commit `feat: adiciona dashboard termico MVP com heatmap e atualizacao automatica` de 15/10/2026. `git status` mostrou `nothing to commit, working tree clean` naquele momento. Isso confirma o checkpoint publicado v0.1, mas NÃO autoriza presumir que modificações visuais posteriores já foram commitadas ou publicadas.
- Projeto local no Windows: `C:\Users\igoro\projetos\refrigerador-api`.
- Na sessão de 17/10, após iniciar o Docker Desktop/PostgreSQL, `composer serve-lan -- --host=192.168.1.81` iniciou com sucesso o PHP 8.4.25 Development Server em `http://192.168.1.81:8080` conectado ao banco `refrigerador`. A URL usada para conferir o dashboard real foi `http://192.168.1.81:8080/dashboard`. O ESP32 voltou a produzir dados exibidos no dashboard; última temperatura recente foi observada pelo usuário. O IP é específico da rede daquele momento e PODE mudar; verificar antes de reutilizar.
- A tentativa anterior de abrir `http://192.168.1.81:8000/dashboard` falhou porque a porta correta é 8080. O navegador do Codex, segundo o usuário, tentou `127.0.0.1`; esse endereço pode ser válido para um servidor que escute em loopback, mas não substitui o teste do cenário LAN quando a tarefa envolver o ESP32 e a API acessível pela rede.

Procedimento correto de validação local:
1. Inspecionar configuração e estado de serviços antes de executar comandos. O banco PostgreSQL do projeto roda no Docker Compose; erro de conexão ao banco pode ocorrer quando o Docker Engine ou o contêiner estiverem parados. Verificar primeiro com `docker compose ps`. Se necessário e autorizado, iniciar somente o serviço de banco com `docker compose up -d postgres`; nunca executar down -v, apagar volumes, restaurar o banco principal ou reaplicar baseline por reflexo.
2. Para teste do fluxo LAN real, usar o comando existente `composer serve-lan -- --host=192.168.1.81` SOMENTE quando esse ainda for o IP LAN atribuído ao computador; validar IP antes de iniciar. Porta padrão do projeto: 8080. Abrir no navegador `http://<IP_LAN_ATUAL>:8080/dashboard`. Também conferir `http://<IP_LAN_ATUAL>:8080/health` quando pertinente. Não inventar porta 8000 nem assumir URL fixa.
3. `composer serve` escuta em `127.0.0.1:8080`, apropriado para testes exclusivamente locais. O navegador acessando `http://127.0.0.1:8080/dashboard` pode funcionar quando esse modo está ativo, mas isso NÃO confirma que o ESP32 alcança a API por Wi-Fi. Não declarar falha só porque um modo diferente do solicitado não está servindo no loopback; não iniciar um segundo servidor conflitante na mesma porta.
4. O PHP Development Server é para laboratório em rede doméstica confiável, não produção. Não divulgar Bearer token nem segredos em comandos, logs, screenshots, URLs ou Git. O endpoint de leitura público e o status de API não comprovam, isoladamente, que o ESP32 esteja online.
5. Para testes automatizados, continuar usando `refrigerador_test`, nunca o banco principal. Conservar migrações existentes, backups e todos os registros. Quando a tarefa for só visual, evitar qualquer alteração em API, PostgreSQL, firmware e infraestrutura.

Alterações visuais e observações de 17/10/2026 (estado observado, ainda sujeito à verificação no Git):
- Após um pedido específico ao Codex, o usuário mostrou no navegador quatro títulos alterados para `Medição a cada 10 segundos`, `Medição a cada 30 segundos`, `Medição a cada 1 minuto` e `Medição a cada 5 minutos`, com tipografia mais destacada.
- À direita, foram exibidos `60 intervalos · total de 10 minutos`, `60 intervalos · total de 30 minutos`, `60 intervalos · total de 1 hora` e `60 intervalos · total de 5 horas`. Essa informação é sobre agregação/janela histórica, NÃO a cadência efetiva do ESP32: o firmware realiza leituras aproximadamente a cada 10 s; os heatmaps agregam em buckets de 10/30/60/300 s; o dashboard consulta a API aproximadamente a cada 10 s.
- O usuário relatou que a última célula em coleta não estava piscando de forma perceptível, apesar da alteração solicitada. Não afirmar que a animação está aprovada. O usuário também levantou a ideia de mostrar temperaturas dentro das células, possivelmente com uma casa decimal, e de melhorar o destaque/animação. Essas ideias NÃO estão autorizadas para implementação por este documento: aguardar novo pedido específico e critérios visuais definidos.
- Ideia ainda em DISCUSSÃO: countdown dentro da última célula. Diferenciar fechamento do bucket do gráfico de previsão de nova medição/recebimento do ESP32; nunca prometer dado novo ao chegar a zero. Não implementar automaticamente.
- Ao receber solicitação de UX/UI, conferir código atual, regras de CSS de responsividade, acessibilidade, tooltip/dialog e testes existentes. Não colocar números nas células cinzas sem registros e não fabricar medições para preencher lacunas.

Fluxo de colaboração:
- O usuário decide o escopo. ChatGPT orienta e redige prompts quando solicitado; Codex altera apenas o que foi aprovado, testa e relata.
- Para alterações visuais, apresentar arquivos modificados, testes executados de verdade, URL/ambiente efetivamente usado pelo navegador e eventual limitação (por exemplo, teste só em loopback). Não presumir teste LAN ou física só porque a página carregou.
- Não fazer commit/push/deploy sem autorização expressa da tarefa atual. A autorização de fechamento de 15/10 não se estende a novas melhorias.

18. Dashboard v0.2 — Refinamento Visual — fechamento em 17/10/2026
Esta seção registra o estado atual e prevalece sobre descrições visuais históricas das seções 15–17. O checkpoint de partida conferido foi `632ffef2bd2162fb0c3a80ca5bb5370c5f2ab7b0`, em main e origin/main após git fetch, sem divergência. A v0.1 permanece preservada no histórico.
- HTML/CSS/JS locais, sem novas dependências. Quatro heatmaps de exatamente 60 células, agregadas em 10/30/60/300 s; títulos destacam a duração da célula e textos laterais separam as janelas totais de 10/30/60/300 min. A frequência do ESP32 não foi alterada.
- Médias históricas encerradas e confirmadas aparecem com uma casa decimal pt-BR, sem unidade dentro das células, com contraste preto/branco. Escala experimental fixa 10–35 °C, azul forte a vermelho forte; extremos saturam apenas a cor. Não é regra de segurança alimentar e não há controles de mínimo/máximo.
- Última célula exclusivamente com spinner circular centralizado e borda discreta. Rotação CSS contínua, círculo estático em prefers-reduced-motion; aria-current=time e descrição acessível, desenho aria-hidden. Indica somente intervalo temporal em andamento; não comprova carregamento da API, conectividade ESP32 ou nova medição. Pontinhos, countdown, preenchimento progressivo e piscar foram removidos.
- Polling de 10 s, timeout de 10 s e proteção contra consultas sobrepostas preservados. Um timer de 1 s mantém idade e janelas temporais; não há timer por célula. A referência do servidor é estimada com compensação aproximada de latência e relógio monotônico. Bordas tracejadas sinalizam intervalos não consultados ou fechamento não confirmado; não são ausência comprovada de coleta. Médias parciais continuam no snapshot/detalhes e só aparecem como temperatura histórica na grade após confirmação pela API. Falhas preservam dados conhecidos e avisos, enquanto a janela avança sem inventar temperaturas.
- Card, qualidade temporal, measured_at, received_at, idade, seleção de sensor, tooltip, clique/teclado e detalhes preservados. Grades responsivas com 30/20/15/10/6 colunas nos breakpoints 1050/750/650/380 px, sem reduzir as 60 células. Melhorias futuras de responsividade/usabilidade continuam pendentes.
- Persistem falhas HTTP intermitentes e relatos de RSSI baixo. Não há armazenamento offline/reenvio; pode haver medições ausentes, recepção atrasada ou resultado desconhecido após timeout. Operação prolongada e precisão do relógio sem referência renovada não foram certificadas; abas em segundo plano podem limitar timers.
- Pendências específicas: investigar Mesh TESLA com dois TP-Link Deco M4; identificar o BSSID utilizado pelo ESP32; diagnosticar RSSI, connection refused/read Timeout e demais falhas HTTP; discutir mínimo/máximo configuráveis; aprimorar responsividade/usabilidade e validar celular real/leitor de tela. Nada disso foi implementado no fechamento. Milestones e ressalvas anteriores permanecem inalterados.
- Autorização expressa desta tarefa: revisar, testar, documentar, identificar v0.2, criar commit em main com `feat(dashboard): consolida refinamento visual v0.2`, push normal para origin/main e tag anotada `dashboard-v0.2` apontando ao novo commit, publicando a tag após verificações. Não autoriza etapas futuras, deploy, mudanças de firmware/Wi-Fi, API, SQL, migrações, banco principal ou arquivos privados.
- Verificações reexecutadas no fechamento: 17 testes JS aprovados; composer test exclusivamente em refrigerador_test, 152 testes e 1801 asserções, zero falhas/ignorados; sintaxe dos arquivos PHP rastreados, sintaxe JS e git diff --check aprovados. Validação de navegador da evolução em LAN (192.168.1.81:8080, endereço conferido), quatro spinners, 240 células e larguras 320/390/751/1051/1280 px sem overflow; teclado e detalhes verificados. Cenários de API indisponível/ausência de medições foram simulados nos testes, sem novo teste físico de desconexão. Firmware e banco principal não foram alterados por esta tarefa. Arquivos privados e backups não integram os candidatos ao commit.
