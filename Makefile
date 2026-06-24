.PHONY: help install db-create db-drop db-reset migrate migrate-rollback fixtures \
        test test-coverage lint phpstan qa cache-clear cache-warmup \
        collect-alerts collect-traffic collect-cemaden notify-risk report-daily \
        server docker-up docker-down docker-logs routes security-check

# ----------------------------------------------------------------
# Variáveis
# ----------------------------------------------------------------
PHP        = php
COMPOSER   = composer
CONSOLE    = $(PHP) bin/console
PHPUNIT    = $(PHP) vendor/bin/phpunit
PHPSTAN    = $(PHP) vendor/bin/phpstan
PHPCS      = $(PHP) vendor/bin/php-cs-fixer
SYMFONY    = symfony

# Cor para output
GREEN  = \033[0;32m
YELLOW = \033[1;33m
RESET  = \033[0m

# ----------------------------------------------------------------
# Ajuda
# ----------------------------------------------------------------
help: ## Exibe esta ajuda
	@echo ""
	@echo "$(YELLOW)WazeBR — Symfony 7.4$(RESET)"
	@echo ""
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| sort \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  $(GREEN)%-28s$(RESET) %s\n", $$1, $$2}'
	@echo ""

# ----------------------------------------------------------------
# Instalação
# ----------------------------------------------------------------
install: ## Instala dependências do Composer
	$(COMPOSER) install --no-interaction --prefer-dist --optimize-autoloader

install-dev: ## Instala dependências incluindo as de desenvolvimento
	$(COMPOSER) install --no-interaction --prefer-dist

update: ## Atualiza dependências
	$(COMPOSER) update

# ----------------------------------------------------------------
# Banco de dados
# ----------------------------------------------------------------
db-create: ## Cria o banco de dados
	$(CONSOLE) doctrine:database:create --if-not-exists

db-drop: ## Remove o banco de dados
	$(CONSOLE) doctrine:database:drop --force --if-exists

db-reset: db-drop db-create migrate fixtures ## Recria banco, roda migrations e fixtures
	migrate: ## Executa todas as migrations pendentes
	$(CONSOLE) doctrine:migrations:migrate --no-interaction

migrate-diff: ## Gera migration a partir das entidades atuais
	$(CONSOLE) doctrine:migrations:diff

migrate-rollback: ## Desfaz a última migration
	$(CONSOLE) doctrine:migrations:execute --down $(filter-out $@,$(MAKECMDGOALS))

migrate-status: ## Exibe status das migrations
	$(CONSOLE) doctrine:migrations:status

fixtures: ## Carrega fixtures (dados de exemplo)
	$(CONSOLE) doctrine:fixtures:load --no-interaction

fixtures-append: ## Adiciona fixtures sem apagar dados existentes
	$(CONSOLE) doctrine:fixtures:load --no-interaction --append

schema-validate: ## Valida mapeamento Doctrine vs banco
	$(CONSOLE) doctrine:schema:validate

# ----------------------------------------------------------------
# Testes
# ----------------------------------------------------------------
test: ## Roda a suite de testes completa
	$(PHPUNIT) --colors=always

test-unit: ## Roda apenas testes unitários
	$(PHPUNIT) --colors=always --testsuite "Project Test Suite" --filter "Unit"

test-filter: ## Roda testes que correspondam ao filtro (make test-filter FOO=MyClass)
	$(PHPUNIT) --colors=always --filter $(FOO)

test-coverage: ## Gera relatório de cobertura HTML em var/coverage/
	$(PHP) -d xdebug.mode=coverage $(PHPUNIT) --coverage-html var/coverage
	@echo "$(GREEN)Coverage gerado em var/coverage/index.html$(RESET)"

# ----------------------------------------------------------------
# Qualidade de código
# ----------------------------------------------------------------
phpstan: ## Roda PHPStan (análise estática, nível 6)
	$(PHPSTAN) analyse --memory-limit=256M

lint: ## Lint YAML, Twig e PHP
	$(CONSOLE) lint:yaml config/
	$(CONSOLE) lint:twig templates/
	$(CONSOLE) lint:container
	$(CONSOLE) lint:xliff translations/ 2>/dev/null || true

qa: lint phpstan test ## Lint + PHPStan + Testes (pipeline completo)
	@echo "$(GREEN)✅ QA passou com sucesso!$(RESET)"

security-check: ## Verifica vulnerabilidades nas dependências
	$(COMPOSER) audit

# ----------------------------------------------------------------
# Cache
# ----------------------------------------------------------------
cache-clear: ## Limpa o cache (dev)
	$(CONSOLE) cache:clear

cache-warmup: ## Aquece o cache (prod)
	$(CONSOLE) cache:warmup --env=prod

cache-prod: ## Limpa e aquece cache de produção
	$(CONSOLE) cache:clear --env=prod
	$(CONSOLE) cache:warmup --env=prod

# ----------------------------------------------------------------
# Console Commands (Waze / CEMADEN)
# ----------------------------------------------------------------
collect-alerts: ## Coleta alertas da API Waze
	$(CONSOLE) waze:collect:alerts -v

collect-traffic: ## Coleta congestionamentos da API Waze
	$(CONSOLE) waze:collect:traffic -v

collect-cemaden: ## Coleta dados CEMADEN (make collect-cemaden UF=MG)
	$(CONSOLE) cemaden:collect $(UF) -v

notify-risk: ## Gera notificações de alto risco
	$(CONSOLE) waze:notify:high-risk -v

report-daily: ## Envia relatório diário por e-mail
	$(CONSOLE) waze:report:daily -v

# ----------------------------------------------------------------
# Servidor de desenvolvimento
# ----------------------------------------------------------------
server: ## Inicia servidor Symfony (porta 8000)
	$(SYMFONY) server:start --port=8000

server-stop: ## Para o servidor Symfony
	$(SYMFONY) server:stop

server-log: ## Exibe logs do servidor em tempo real
	$(SYMFONY) server:log

# ----------------------------------------------------------------
# Docker
# ----------------------------------------------------------------
docker-up: ## Sobe os containers Docker (MySQL + Redis)
	docker compose up -d

docker-down: ## Para os containers Docker
	docker compose down

docker-logs: ## Exibe logs dos containers
	docker compose logs -f

docker-reset: docker-down ## Para containers, remove volumes e reinicia
	docker compose down -v
	docker compose up -d

# ----------------------------------------------------------------
# Rotas e debug
# ----------------------------------------------------------------
routes: ## Lista todas as rotas registradas
	$(CONSOLE) debug:router

services: ## Lista services registrados no container
	$(CONSOLE) debug:container

env-check: ## Exibe variáveis de ambiente carregadas
	$(CONSOLE) debug:dotenv

# ----------------------------------------------------------------
# Atalho: setup completo do zero
# ----------------------------------------------------------------
setup: install db-create migrate fixtures ## Instala, cria banco, migra e carrega fixtures
	@echo ""
	@echo "$(GREEN)✅ Setup completo!$(RESET)"
	@echo "  Acesse: http://localhost:8000"
	@echo "  Admin : admin@wazebr.local / Admin@12345"
	@echo "  User  : usuario@wazebr.local / User@12345"
	@echo ""
