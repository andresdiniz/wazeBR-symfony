# CI/CD Setup Guide - wazeBR-symfony

## Visao Geral

O sistema de CI/CD do wazeBR-symfony consiste em:

1. **CI (Continuous Integration)**: Tests e verificacoes de qualidade em cada push/PR
2. **CD (Continuous Deployment)**: Deploy automatico para producao em cada merge na main

## Workflows

### CI - Tests & Quality (.github/workflows/ci.yml)

**Quando roda**: 
- Push em `main` ou `develop`
- Pull requests para `main`

**O que faz**:
- ✅ Setup PHP 8.3 + MySQL
- ✅ Install Composer dependencies (com cache)
- ✅ PHPUnit tests
- ✅ PHPStan static analysis
- ✅ Psalm (opcional)
- ✅ PHP CS Fixer (coding standards)

### CD - Deploy to Production (.github/workflows/cd.yml)

**Quando roda**: 
- Push em `main`

**O que faz**:
- ✅ Build otimizado (no-dev, optimized autoloader)
- ✅ Deploy via FTP para Hostinger
- ✅ Upload de vendor separadamente
- ✅ Roda migrations no servidor
- ✅ Clear/warmup cache
- ✅ Notificacoes de sucesso/falha

## Configuracao dos Secrets

### No GitHub (Settings > Secrets and variables > Actions)

Adicione os seguintes **repository secrets**:

```bash
# FTP Hostinger
FTP_SERVER=ftp://seu-dominio.com
FTP_USERNAME=seu-ftp-user
FTP_PASSWORD=seu-ftp-password

# SSH Hostinger (para migrations)
SSH_HOST=seu-dominio.com
SSH_USERNAME=seu-ssh-user
SSH_KEY=${{ secrets.SSH_PRIVATE_KEY }}

# Database (para tests CI)
DATABASE_URL=mysql://root:root@localhost:3306/wazebr_test
```

### Como gerar SSH Key

```bash
# No seu computador
ssh-keygen -t ed25519 -C "github-actions@wazebr"

# Copie a chave publica para o servidor
ssh-copy-id user@seu-dominio.com

# Adicione a chave privada no GitHub
gh secret set SSH_PRIVATE_KEY < ~/.ssh/id_ed25519
```

## Personalizacao

### Mudar branches

Edite `.github/workflows/ci.yml` e `cd.yml`:

```yaml
on:
  push:
    branches: [ main, staging, develop ]  # Adicione branches
```

### Adicionar ambiente de staging

Crie `.github/workflows/cd-staging.yml`:

```yaml
name: CD - Deploy to Staging

on:
  push:
    branches: [ develop ]

jobs:
  deploy-staging:
    environment:
      name: staging
      url: https://staging.wazebr.com.br
    # ... mesmo setup do CD, mas com secrets diferentes
```

### Adicionar notificacoes

#### Telegram

```yaml
- name: Notify Telegram
  if: always()
  uses: appleboy/telegram-action@master
  with:
    to: ${{ secrets.TELEGRAM_CHAT_ID }}
    token: ${{ secrets.TELEGRAM_TOKEN }}
    message: |
      ${{ job.status }}
      Commit: ${{ github.event.head_commit.message }}
      Author: ${{ github.actor }}
      URL: ${{ github.event.head_commit.url }}
```

#### Email

```yaml
- name: Send email on failure
  if: failure()
  uses: dawiddrs/action-send-mail@v3
  with:
    server_address: smtp.gmail.com
    server_port: 587
    username: ${{ secrets.SMTP_USERNAME }}
    password: ${{ secrets.SMTP_PASSWORD }}
    subject: Deploy failed!
    to: dev@wazebr.com.br
```

## Otimizacoes

### Cache de dependencias

Ja configurado no CI/CD:

```yaml
- name: Cache Composer dependencies
  uses: actions/cache@v4
  with:
    path: vendor
    key: ${{ runner.os }}-php-${{ hashFiles('composer.lock') }}
```

### Parallel tests

Se tiver muitos tests, pode parallelizar:

```yaml
strategy:
  matrix:
    php: ['8.2', '8.3']
```

## Troubleshooting

### Deploy falhou

1. Verifique logs do workflow no GitHub Actions
2. Confira se secrets estao corretos
3. Teste conexao FTP/SSH manualmente

### Tests falhando no CI

```bash
# Rode localmente para debug
php bin/phpunit
php vendor/bin/phpstan analyse
```

### Cache desatualizado

Delete cache no GitHub: 
Settings > Actions > Caches > Delete

## Monitoramento

### GitHub Actions

- https://github.com/andresdiniz/wazeBR-symfony/actions

### Hostinger

- Logs: /home/u937753890/domains/wazebr.com.br/logs/
- Access: cPanel > File Manager

## Comandos Uteis

```bash
# Rodar CI localmente
act -j tests  # requer Docker

# Simular deploy
act -j deploy --dry-run

# Ver secrets
gh secret list

# Adicionar secret
gh secret set FTP_PASSWORD
```

## Referencias

- GitHub Actions: https://docs.github.com/actions
- FTP Deploy Action: https://github.com/SamKirkland/FTP-Deploy-Action
- SSH Action: https://github.com/appleboy/ssh-action
- Setup PHP: https://github.com/shivammathur/setup-php
