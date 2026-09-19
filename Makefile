# Command interface for local development. Targets stay thin.
# EXEC is how PHP is reached: inside the php container locally, natively
# in CI (`make check EXEC=`).
COMPOSE ?= docker compose
EXEC    ?= $(COMPOSE) exec -T php
ARGS    ?=

PHPUNIT ?= vendor/bin/phpunit
PHPSTAN ?= vendor/bin/phpstan
CSFIX   ?= vendor/bin/php-cs-fixer

# Until the Symfony application exists (roadmap change scaffold-symfony-app)
# there is no composer.json and no vendor/: the check targets print SKIP
# and succeed, mirroring the CI `detect` job. A Make-level conditional,
# not a shell guard: recipe lines run in separate shells, so a guard
# line could not stop the tool line that follows it.
ifeq ($(wildcard composer.json),)
APP_MISSING := 1
endif
SKIP_MSG = @echo "[SKIP] no composer.json — application not scaffolded yet"

.DEFAULT_GOAL := help
.PHONY: help init up down ps logs sh composer console migrate migration test-db jwt-keys migrations-roundtrip worker test stan cs cs-fix check

help: ## List available commands
	@grep -E '^[a-z-]+:.*##' $(MAKEFILE_LIST) | awk -F':.*## ' '{printf "  %-12s %s\n", $$1, $$2}'

init: ## First run: build, up, composer install, migrate
	$(COMPOSE) build
	$(COMPOSE) up -d
	$(EXEC) composer install
	$(MAKE) jwt-keys
	$(MAKE) migrate
	$(MAKE) test-db

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

# The suite's click fixtures use fixed dates — the oldest is 2026-03-28 — while
# the schema provisions months relative to today, so a retention window would
# eventually leave them behind and the suite would start failing on a calendar
# date. The test database therefore provisions its own declared range, through
# the same function the migration and app:clicks:partitions use
# (change stretch-partition-clicks).
CLICK_FIXTURE_FROM ?= 2026-01-01
CLICK_FIXTURE_TO   ?= 2027-01-01

test-db: ## Create and migrate the test database (DATABASE_URL's database + _test)
	$(EXEC) bin/console doctrine:database:create --if-not-exists --env=test
	$(EXEC) bin/console doctrine:migrations:migrate --env=test --no-interaction --allow-no-migration
	$(EXEC) bin/console dbal:run-sql --env=test -- "SELECT clicks_ensure_partition(generate_series('$(CLICK_FIXTURE_FROM)'::date, '$(CLICK_FIXTURE_TO)'::date, interval '1 month')::date)" >/dev/null

# The test keypair does not go through the console command: inside the
# container that command takes JWT_PASSPHRASE from .env, not from .env.test,
# and --skip-if-exists would then keep the unusable key it wrote. The script
# resolves the passphrase by running the code tests/bootstrap.php runs
# (App\Tests\TestEnvironment::apply, through scripts/test-jwt-passphrase.php)
# and replaces a keypair that does not match it, so the generator and the suite
# cannot resolve different values (change harden-quality-and-docs, decision 6a).
jwt-keys: ## Generate the dev and test JWT keypairs (config/jwt/<env>/, gitignored; dev skips an existing key, test replaces one that does not match .env.test)
	$(EXEC) bin/console lexik:jwt:generate-keypair --skip-if-exists --env=dev
	$(EXEC) sh scripts/test-jwt-keys.sh "$$($(EXEC) php scripts/test-jwt-passphrase.php)"

# Every migration down and up again, on a scratch database this run creates
# and drops, comparing the schema before and after (change harden-gate-floor).
# CI runs the same target natively in its own job.
migrations-roundtrip: ## Run every migration down and up again on a scratch database and compare the schema
ifdef APP_MISSING
	$(SKIP_MSG)
else
	$(EXEC) sh scripts/migrations-roundtrip.sh
endif

worker: ## Consume the async Messenger transport in the foreground
	$(COMPOSE) exec php bin/console messenger:consume async -vv --time-limit=3600

test: ## PHPUnit (rebuilds the test container first: APP_DEBUG=0 in .env.test means config changes are not tracked)
ifdef APP_MISSING
	$(SKIP_MSG)
else
	$(EXEC) bin/console cache:clear --env=test --no-warmup
	$(EXEC) $(PHPUNIT)
endif

stan: ## Static analysis
ifdef APP_MISSING
	$(SKIP_MSG)
else
	$(EXEC) $(PHPSTAN) analyse --no-progress --memory-limit=1G
endif

cs: ## Code style check (no changes)
ifdef APP_MISSING
	$(SKIP_MSG)
else
	# --using-cache=no: the cache made this target report green on a file CI
	# then refused (change add-web-ui). A check that can pass on stale
	# knowledge is not a check; cs-fix below keeps the cache for speed.
	$(EXEC) $(CSFIX) fix --dry-run --diff --using-cache=no
endif

cs-fix: ## Code style fix
	$(EXEC) $(CSFIX) fix

check: cs stan test ## The gate floor: style + static analysis + tests
	@echo "check: all green"

image: ## Build the production image (the deployment's own command; CI runs this exact target)
	docker build -f .docker/php/Dockerfile --target prod -t linkboard-prod .
	@echo "image: linkboard-prod built"
