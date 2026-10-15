Projeto Refrigerador IoT — instruções de desenvolvimento
1. Contexto e escopo
O Projeto Refrigerador IoT é um laboratório educacional e projeto de portfólio na área de Refrigeração e Climatização.
Um ESP32 com dois sensores DS18B20 medirá as temperaturas do congelador e do compartimento refrigerado. As medições serão enviadas por Wi-Fi e HTTPS a uma API PHP, responsável por autenticação, validação, persistência no PostgreSQL e consultas posteriores.
Fluxo previsto: sensores DS18B20 → ESP32 → API PHP com Slim → PostgreSQL → dashboard futuro.
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
- POST /api/v1/temperaturas recebe uma medição JSON por requisição, usando sensor_id (freezer ou refrigerator) e temperature numérica.
- O POST exige autenticação via Bearer token, valida a requisição, grava a medição no PostgreSQL via PDO e responde HTTP 201 em caso de sucesso.
- O esquema sql/001_temperature_readings.sql cria temperature_readings com id, sensor_id, temperature e received_at.
- A persistência real foi confirmada em consulta ao PostgreSQL local.
- GET /api/v1/temperaturas é público, retorna HTTP 200 com data (até 50 medições, ordenadas por received_at DESC, id DESC), campos id, sensor_id, temperature, received_at (UTC); data: [] quando vazio; erro de consulta retorna 503 genérico. Validado manualmente em 14/10/2026.
Firmware e comunicação ESP32 → API — validação física confirmada pelo usuário em 14/10/2026
- Firmware em firmware/esp32/esp32.ino, utilizado com Arduino IDE 2.3.10 e placa ESP32 Dev Module.
- DS18B20 funcionando no GPIO 4, com OneWire e DallasTemperature; leituras a cada 10 segundos por millis() e conversão assíncrona.
- Wi-Fi TESLA conectado; diagnóstico opcional implementado, ativável pela configuração local e desativável para uso normal.
- ESP32 recebeu HTTP 200 de GET /health na API LAN, acessível em 192.168.1.81:8080 durante os testes. Esse IP é configuração local alterável, não um endereço permanente.
- Fluxo físico DS18B20 → ESP32 → Wi-Fi → POST autenticado → API Slim → PostgreSQL → GET confirmado pelo usuário: uma medição válida por POST, inicialmente a cada 10 segundos, com sensor_id explicitamente configurado e contrato atual preservado.
- HTTP 201 confirmado no Monitor Serial; medições reais recuperadas por GET /api/v1/temperaturas, incluindo registros consecutivos com intervalos próximos de 10 segundos. Conexões aceitas nos logs do servidor PHP, isoladamente, não comprovam gravação.
- Houve melhora observada nas transmissões após aproximar o ESP32 do roteador. Relatos anteriores de connection refused e read Timeout não devem ser considerados definitivamente resolvidos: a comunicação não foi validada continuamente por períodos prolongados.
- Não há armazenamento offline ou reenvio automático; falhas podem resultar em perda de medições, e timeout após possível gravação deixa o resultado desconhecido. Transmissão de measured_at, RTC externo e dashboard ainda não estão implementados. received_at continua sendo o horário de recebimento gerado no PostgreSQL.
- NTP/SNTP implementado apenas no firmware, com horário UTC e snapshot interno no início da conversão do sensor. Qualidades unknown (sem referência desde o boot), synced (referência recente) e estimated (referência antiga) independem do estado do Wi-Fi. Configuração normal aprovada: NTP_SYNC_INTERVAL_MS=3600000UL (1 hora) e TIME_REFERENCE_MAX_AGE_MS=21600000ULL (6 horas); esses valores foram confirmados no config.local.h privado antes do checkpoint, sem alterá-lo. O POST permanece somente com sensor_id e temperature.
- Validação física NTP confirmada pelo usuário em ESP32 real: unknown → synced → estimated → synced. Sem Wi-Fi desde o boot, horário indisponível e DS18B20 medindo; após referência NTP válida, UTC disponível e synced. Com limite experimental de 60000ULL, a referência expirou em aproximadamente 60 segundos sem atualização, passando para estimated enquanto UTC e medições continuavam. Ao retornar o Wi-Fi, nova referência restaurou synced sem reiniciar a placa. O limite de 60 segundos foi exclusivo do teste, não da operação normal; a qualidade e o horário de snapshots anteriores não são corrigidos retroativamente.
- Os testes físicos aprovaram as transições de qualidade e a recuperação; não mediram a precisão do relógio durante longos períodos sem sincronização nem comprovaram estabilidade contínua da comunicação HTTP.
- Diagnóstico pendente: connection refused e read Timeout em HTTP, RSSI observado de aproximadamente -88 e -92 dBm e ponto mesh da cozinha desligado durante parte dos testes. Esses fatores podem contribuir para instabilidade, mas a causa de todas as falhas HTTP não foi demonstrada.
Exemplo do contrato atual do POST:
{
  "sensor_id": "freezer",
  "temperature": -18.2
}
Ainda pendente de implementação ou decisão
- Suporte a lotes com uma ou várias medições em uma requisição.
- Identificação de medição, prevenção de duplicidades em retransmissões e política de confirmação de lotes.
- Timestamps originados pelo ESP32, armazenamento offline e sincronização posterior.
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
- Discutir e definir o contrato de dados e a persistência dos campos candidatos device_id, measurement_id, measured_at, received_at, time_quality, wifi_rssi, uptime e observation. received_at já existe e representa o recebimento no servidor; measured_at deverá representar a medição. Os demais campos candidatos ainda não são persistidos pela API. Aprovar nomes, tipos, semântica, formato temporal, validação, integridade e evolução do banco antes de implementar; essa lista não autoriza novos campos, migrações ou endpoints. O POST atual permanece somente com sensor_id e temperature.
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
- Versionar alterações de estrutura com scripts SQL ou mecanismo simples de migração.
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
- scripts/setup-tests.php configura/prepara o banco isolado e aplica o script SQL versionado sql/001_temperature_readings.sql sem apagar os dados existentes.
- composer setup-tests executa a preparação; composer test prepara o banco e executa PHPUnit com variáveis TEST_DB_HOST, TEST_DB_PORT, TEST_DB_NAME, TEST_DB_USER e TEST_DB_PASSWORD.
- O script usa a configuração local do Docker Compose e tem proteção contra seleção do banco principal. Antes de alterar esse script, inspecionar como essa proteção funciona e mantê-la.
- Última suíte executada na preparação do checkpoint NTP em 14/10/2026: composer test no Windows, com TEST_DB_NAME=refrigerador_test, 44 testes aprovados, 146 asserções, zero ignorados e zero falhas. Esse registro não substitui verificações futuras. Os testes de integração em tests/TemperaturePersistenceTest.php exercitam gravação, consulta e falhas; usam banco isolado e transações com rollback.
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
- Dashboard e gráficos históricos.
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
NTP está implementado e suas transições de qualidade foram fisicamente validadas, com as limitações registradas na seção 4. A próxima fase é discutir o contrato e a persistência dos campos candidatos, sem alterar retroativamente os critérios dos milestones 5 e 6 ou declarar milestones inteiros concluídos por esse teste. Não implementar as próximas etapas sem aprovação.
