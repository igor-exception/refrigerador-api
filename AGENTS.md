# Projeto Refrigerador IoT — instruções de desenvolvimento

## 1. Contexto e escopo

O Projeto Refrigerador IoT é um laboratório educacional e projeto de portfólio na área de Refrigeração e Climatização.

Um ESP32 com dois sensores DS18B20 medirá as temperaturas do congelador e do compartimento refrigerado. As medições serão enviadas por Wi-Fi e HTTPS a uma API PHP, responsável por autenticação, validação, persistência no PostgreSQL e consultas posteriores.

Fluxo previsto: sensores DS18B20 → ESP32 → API PHP com Slim → PostgreSQL → dashboard futuro.

Estas instruções registram requisitos e diretrizes; não indicam que as funcionalidades previstas já estejam implementadas. Antes de implementar, examinar a estrutura, o `composer.json` e os arquivos relacionados. Não presumir que o repositório esteja vazio.

## 2. Stack e ambiente definidos

- PHP 8.4, executado diretamente no Windows nesta fase.
- Slim Framework 4 e Slim PSR-7 para roteamento e processamento HTTP.
- Composer para gerenciamento de dependências.
- PDO com PostgreSQL como banco principal em desenvolvimento e produção.
- PostgreSQL 17 local via Docker Compose; o ambiente local já possui essa configuração.
- PHPUnit 13 para testes automatizados.
- Git e GitHub.
- Windows e PowerShell para desenvolvimento e comandos de orientação.

Não substituir a stack sem aprovação expressa. Não introduzir Laravel, Herd, SQLite, ORM ou frameworks adicionais.

## 3. Arquitetura e simplicidade

Utilizar a solução mais simples que atenda corretamente aos requisitos de funcionamento, segurança, testes e manutenção. O projeto deve permanecer pequeno enquanto seus requisitos permitirem.

- Favorecer PHP simples, legível e fácil de depurar.
- Separar responsabilidades quando necessário.
- Evitar classes, interfaces e abstrações sem justificativa.
- Evitar duplicação significativa sem abstrair prematuramente.
- Não introduzir arquitetura hexagonal, DDD, microsserviços, filas ou sistemas de eventos sem necessidade concreta.
- Não criar diretórios, arquivos, tabelas ou colunas antecipadamente.
- Explicar o motivo e obter aprovação antes de instalar dependências.

## 4. Requisitos definidos para o primeiro MVP

A primeira versão deverá:

1. Receber medições por HTTP POST e autenticar o dispositivo remetente.
2. Validar a estrutura e os valores recebidos.
3. Aceitar lotes com uma ou várias medições.
4. Persistir medições no PostgreSQL com integridade e prevenção de duplicidades em retransmissões.
5. Disponibilizar consultas por HTTP GET.
6. Retornar JSON e códigos HTTP adequados, com falhas previsíveis e testáveis.
7. Possuir testes automatizados relevantes desde as primeiras funcionalidades.

### Medição e transmissão

O ESP32 deverá medir e transmitir em intervalos independentes. Por exemplo, poderá medir a cada minuto e transmitir a cada minuto, a cada cinco minutos ou a cada hora. Esses intervalos são exemplos, não configurações obrigatórias.

A API deve considerar que uma medição pode ser reenviada quando o dispositivo perde a confirmação do servidor. Cada medição deverá possuir identificação adequada, e uma medição já armazenada não poderá gerar duplicidade. Não presumir que uma requisição HTTP ocorrerá exatamente uma vez.

### Propostas ainda não aprovadas

As rotas constam no documento original como `GET health`, `POST apiv1temperaturas` e `GET apiv1temperaturas`. Os caminhos exatos, incluindo barras e versionamento, precisam ser confirmados antes da implementação.

O contrato JSON definitivo também está pendente. O exemplo abaixo ilustra apenas o conceito, com sintaxe JSON válida; nomes, tipos e formato dos campos ainda precisam ser aprovados:

```json
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
```

Não definir unilateralmente campos adicionais, limites numéricos, políticas de erro ou novas rotas.

## 5. Segurança obrigatória

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

Não introduzir autenticação complexa sem necessidade. O mecanismo de autenticação e o armazenamento de credenciais devem ser discutidos antes da implementação.

## 6. Banco de dados e integridade

- Acessar PostgreSQL por PDO, conforme as regras de consultas parametrizadas.
- Versionar alterações de estrutura com scripts SQL ou mecanismo simples de migração.
- Utilizar restrições apropriadas para integridade e unicidade.
- Tratar timestamps e fusos horários corretamente.
- Usar configurações distintas para desenvolvimento, testes e produção.
- Não modificar diretamente o banco de produção.
- Nunca executar testes destrutivos no banco de produção.

A modelagem definitiva deverá ser aprovada antes da implementação. A política de transações, confirmação parcial ou rejeição integral de lotes também precisa ser discutida previamente.

## 7. Testes e verificação

Utilizar PHPUnit desde as primeiras funcionalidades. Quando pertinente, cobrir:

- Comportamento esperado, entradas inválidas e falhas relevantes.
- Autenticação e validação de temperaturas, formato, quantidade de medições e timestamps.
- Persistência, integridade transacional e duplicidades.
- Respostas JSON e códigos HTTP.

Preferir testes pequenos e determinísticos. Separar testes unitários e de integração quando fizer sentido e utilizar banco de testes isolado para persistência. Não usar serviços de produção em testes.

O ESP32 não precisa estar conectado ao computador para desenvolver ou testar a API. Simular requisições com ferramentas HTTP e testes automatizados, sem depender do dispositivo físico.

Executar os testes afetados por cada alteração. Nunca afirmar que passaram sem executá-los; informar claramente qualquer impedimento de execução.

## 8. Escopo autorizado e desenvolvimento incremental

Trabalhar uma etapa por vez. Não desenvolver toda a API em uma única interação nem antecipar funcionalidades futuras por conveniência. O agente não possui autorização permanente para modificar qualquer parte da aplicação.

Para cada solicitação:

1. Identificar o escopo solicitado e examinar o código relacionado.
2. Implementar apenas o escopo autorizado, evitando alterações não relacionadas.
3. Criar ou atualizar testes relevantes e executar as verificações possíveis.
4. Apresentar os resultados e eventuais limitações.

Solicitar aprovação antes de mudanças arquiteturais significativas, novas dependências, alterações destrutivas ou mudanças fora do escopo. Não realizar commits, pushes, deploys, exclusão de dados ou comandos destrutivos sem autorização expressa.

Em instalações e configurações manuais, fornecer uma ação por vez, explicar brevemente sua finalidade e aguardar o resultado. Em tarefas de programação autorizadas, concluir o escopo combinado e seus testes sem interromper desnecessariamente a execução.

## 9. Infraestrutura e produção previstas

A implantação em produção será discutida posteriormente. Heroku é a primeira opção prevista, ainda não uma decisão definitiva de hospedagem.

- Manter compatibilidade com PostgreSQL e evitar acoplamento desnecessário ao provedor.
- Respeitar variáveis de ambiente e preservar o isolamento entre arquivos públicos e configurações privadas.
- Não implementar deploy sem solicitação.
- Não presumir disponibilidade de créditos ou serviços externos.
- Considerar procedimentos futuros de backup e restauração.

## 10. Funcionalidades fora do escopo inicial

Não implementar antecipadamente:

- Dashboard e gráficos históricos.
- Notificações e alertas.
- Firmware do ESP32.
- Persistência local no ESP32, possivelmente com LittleFS.
- RTC.
- Monitoramento elétrico.
- Integrações.

## 11. Decisões pendentes

Discutir e aprovar antes das respectivas implementações:

- Caminhos e contrato das rotas previstas.
- Contrato JSON, campos, tipos e formato de timestamps.
- Limites de temperaturas, quantidade de medições e tamanho de requisições.
- Mecanismo de autenticação e armazenamento de credenciais.
- Controle de acesso para consultas.
- Modelagem do banco e estratégia concreta de identificação e unicidade das medições.
- Política de transações, confirmação de lotes, tratamento de duplicidades e respostas de erro.
- Hospedagem e procedimentos de deploy, backup e restauração.

## 12. Comunicação e aprendizado

Utilizar português brasileiro nas explicações e preservar identificadores técnicos e nomes de bibliotecas conforme as convenções do projeto.

O desenvolvedor possui experiência anterior com PHP, APIs e bancos de dados e está retomando conhecimentos técnicos. Ajudar na compreensão do sistema sem explicar programação como para um iniciante absoluto.

Ao finalizar uma implementação, informar os arquivos criados ou modificados, a funcionalidade implementada, os testes executados e seus resultados, como testar manualmente pelo PowerShell e as limitações ou decisões pendentes. Explicar o percurso da requisição pela aplicação quando pertinente.
