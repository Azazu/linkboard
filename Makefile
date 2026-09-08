# Command interface for local development. Targets stay thin.
# EXEC is how PHP is reached: inside the php container locally, natively
# in CI (`make check EXEC=`).
COMPOSE ?= docker compose
EXEC    ?= $(COMPOSE) exec -T php
ARGS    ?=

PHPUNIT ?= vendor/bin/phpunit
PHPSTAN ?= vendor/bin/phpstan
CSFIX   ?= vendor/bin/php-cs-fixer

.DEFAULT_GOAL := help
.PHONY: help init up down ps logs sh composer console migrate migration worker test stan cs cs-fix check

help: ## List available commands
	@grep -E '^[a-z-]+:.*##' $(MAKEFILE_LIST) | awk -F':.*## ' '{printf "  %-12s %s\n", $$1, $$2}'

init: ## First run: build, up, composer install, migrate
	$(COMPOSE) build
	$(COMPOSE) up -d
	$(EXEC) composer install
	$(MAKE) migrate

up: ## Start containers (idempotent)
	$(COMPOSE) up -d

down: ## Stop containers (data volumes preserved)
	$(COMPOSE) down

ps: ## Container status
	$(COMPOSE) ps

logs: ## Tail logs
	$(COMPOSE) logs --tail=100 -f

sh: ## Shell in the php container
	$(COMPOSE) exec php sh

composer: ## Run composer: make composer ARGS='require api-platform/core'
	$(EXEC) composer $(ARGS)

console: ## Run bin/console: make console ARGS='debug:router'
	$(EXEC) bin/console $(ARGS)

migrate: ## Apply migrations
	$(EXEC) bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

migration: ## Generate a migration from the mapping diff (review it before committing)
	$(EXEC) bin/console doctrine:migrations:diff --no-interaction

worker: ## Consume the async Messenger transport in the foreground
	$(COMPOSE) exec php bin/console messenger:consume async -vv --time-limit=3600

test: ## PHPUnit
	$(EXEC) $(PHPUNIT)

stan: ## Static analysis
	$(EXEC) $(PHPSTAN) analyse --no-progress --memory-limit=1G

cs: ## Code style check (no changes)
	$(EXEC) $(CSFIX) fix --dry-run --diff

cs-fix: ## Code style fix
	$(EXEC) $(CSFIX) fix

check: cs stan test ## The gate floor: style + static analysis + tests
	@echo "check: all green"
