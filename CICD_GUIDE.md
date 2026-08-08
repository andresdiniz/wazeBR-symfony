# Guia do Pipeline de CI/CD - wazeBR-symfony

**Data:** 07 de Agosto de 2026  
**Projeto:** WazeBR Monitor  
**Plataforma:** GitHub Actions

---

## 🚀 Visão Geral

O projeto **wazeBR-symfony** conta com um sistema completo de Integração Contínua (CI) e Entrega Contínua (CD) configurado através de **GitHub Actions**. Este pipeline automatiza testes, análise estática de código, auditoria de segurança e o processo de deploy em produção.

---

## 🔄 1. Integração Contínua (CI) - `ci.yml`

O workflow de CI é executado automaticamente em todo `push` ou `pull_request` para as branches principais (`main`, `master`, `develop`).

### Etapas do Pipeline de CI:
1. **Configuração de Ambiente (Matrix)**: Inicializa o ambiente PHP nas versões `8.2` e `8.3` com extensões essenciais (`mbstring`, `xml`, `intl`, `pdo_mysql`, etc.).
2. **Serviço MySQL**: Inicializa um container MySQL 8.0 temporário para testes de integração com banco de dados.
3. **Cache de Dependências**: Utiliza o cache do Composer para acelerar a instalação de dependências.
4. **Instalação de Dependências**: Executa `composer install`.
5. **Validação do Schema Doctrine**: Verifica se as entidades e o banco de dados estão sincronizados (`bin/console doctrine:schema:validate`).
6. **Testes Unitários e de Integração**: Executa o PHPUnit (`vendor/bin/phpunit`).
7. **Análise Estática**: Executa o PHPStan para detectar potenciais bugs.
8. **Auditoria de Segurança**: Executa `composer audit` para verificar vulnerabilidades em dependências de terceiros.

---

## 🚢 2. Entrega Contínua (CD) - `cd.yml`

O workflow de CD é disparado automaticamente quando uma nova `Release` é publicada no GitHub, ou manualmente via interface (`workflow_dispatch`).

### Etapas do Pipeline de CD:
1. **Instalação Otimizada**: Executa `composer install --no-dev --optimize-autoloader` para ambiente de produção.
2. **Cache Warmup**: Limpa e aquece o cache do Symfony em modo produção.
3. **Deploy via SSH**: Conecta-se ao servidor de produção via SSH, atualiza o repositório (`git pull`), executa migrações de banco de dados (`doctrine:migrations:migrate`), limpa o cache e reinicia o Nginx.
4. **Notificações**: Envia alertas de sucesso ou falha via webhook do Slack.

---

## ⚙️ Secrets Necessárias no GitHub

Para que o pipeline de CD funcione corretamente, configure as seguintes **Repository Secrets** nas configurações do seu repositório no GitHub (`Settings > Secrets and variables > Actions`):

| Nome da Secret | Descrição |
|----------------|-----------|
| `APP_SECRET` | Chave secreta do Symfony para produção |
| `DATABASE_URL` | String de conexão com o banco de dados de produção |
| `SSH_HOST` | Endereço IP ou domínio do servidor de produção |
| `SSH_USERNAME` | Nome de usuário SSH (ex: `ubuntu`) |
| `SSH_PRIVATE_KEY` | Chave privada SSH para autenticação no servidor |
| `SSH_PORT` | Porta SSH (padrão: `22`) |
| `DEPLOY_PATH` | Caminho absoluto para a pasta do projeto no servidor (ex: `/var/www/wazebr`) |
| `SLACK_WEBHOOK_URL` | (Opcional) URL do Webhook do Slack para notificações |

---

## 🛠️ Como Testar Localmente

Você pode simular partes do pipeline localmente:

```bash
# Executar testes
APP_ENV=test vendor/bin/phpunit

# Análise estática
vendor/bin/phpstan analyse

# Auditoria de segurança
composer audit
```

---

**Última atualização:** 07 de Agosto de 2026
