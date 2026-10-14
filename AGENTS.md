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
- Em 14/10/2026, o usuário executou composer setup-tests e composer test no Windows: 44 testes aprovados, 146 asserções, zero ignorados e zero falhas. Três testes de integração em tests/TemperaturePersistenceTest.php exercitam persistência e validação; usam transações com rollback.
- A preparação repetida foi relatada como bem-sucedida pelo Codex. Ainda não foram validados: execução em Linux/macOS e criação automática de um banco de testes inexistente (o banco já havia sido criado manualmente).
- Mensagens 404 Not Found, Slim Application Error e Falha ao persistir medição de temperatura podem ser emitidas em cenários negativos de teste mesmo com a suíte aprovada; verificar resultado real do PHPUnit antes de classificá-las como regressão.
- Não executar testes de integração no banco refrigerador ou em produção. Não remover controles de segurança para simplificar a automação.
Inicialização local da API — Milestone 4
- scripts/serve.php e composer serve foram implementados; o script resolve configurações via Compose sem imprimir credenciais, fixa DB_NAME=refrigerador e sobe o servidor PHP em 127.0.0.1:8080.
- O comando recusa iniciar quando a porta 8080 está ocupada. Validado GET /health e GET /api/v1/temperaturas HTTP 200.
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
- Firmware do ESP32.
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
Commits 89ae94c (testes), 1d8da75 (AGENTS), ba0fd47 (consulta GET + composer serve) e fb23e63 (atualização AGENTS.md) confirmados em origin/main em 14/10/2026. O último git status informado mostrou árvore limpa e branch sincronizada. Alterações posteriores a esse estado devem ser validadas e versionadas mediante autorização expressa; não realizar push ou commit automaticamente.
Roadmap: 4/9 milestones concluídos (44%, por contagem). Próximo Milestone 5: discutir e validar envio de medição simulada do ESP32 para a API; integração física do DS18B20 é Milestone 6. Não implementar sem aprovação.