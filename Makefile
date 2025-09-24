ENV_FILE ?= .env
PLUGIN = repository_omeka

# Local CI install locations (isolated from your Docker bind mounts)
# These are created and reused automatically after first install.
CI_MOODLE ?= .ci/moodle-$(MOODLE_REF)
CI_DATA ?= .ci/moodledata

# Moodle core source for CI
MOODLE_REPO ?= https://github.com/moodle/moodle.git
# Use tag or branch. Examples: v5.0.1 or MOODLE_501_STABLE
MOODLE_REF  ?= v5.0.1

# ---- moodle-plugin-ci runner ----
CI_BIN ?= ./ci/bin/moodle-plugin-ci
MOODLE_ARG = --moodle $(CI_MOODLE)

# Node toolchain for moodle-plugin-ci install (fixes Node 24 incompat)
CI_NODE_VERSION ?= 22.12.0
# Skip plugin Node tasks by default (faster; enable with CI_NO_PLUGIN_NODE=)
CI_NO_PLUGIN_NODE ?= 1
# Skip Moodle core init (grunt) during install to avoid Node mismatch locally
CI_NO_INIT ?= 1

# Prefer Homebrew node@22 if present (macOS / ARM)
BREW_NODE22_PREFIX := $(shell brew --prefix node@22 2>/dev/null || true)
NODE22_BIN := $(if $(BREW_NODE22_PREFIX),$(BREW_NODE22_PREFIX)/bin,)
# Reset DB before install to avoid 'database exists' failures from plugin-ci
CI_RESET_DB_ON_INSTALL ?= 1

.PHONY: help check-env check-docker up upd down pull build shell ci-deps check behat clean package ci-clean ci-prepare ci-bootstrap check-phpunit check-validate test test-up test-down phpunit-init

help: ## Show available make targets and brief descriptions
	@printf "Available targets:\n\n"
	@awk 'BEGIN {FS = ":.*?## "}; \
	  /^[a-zA-Z0-9_.-]+:.*?## / {printf "  %-18s %s\n", $$1, $$2}' \
	  $(MAKEFILE_LIST) | sort
	@printf "\nHints:\n  - Use CI_MOODLE and CI_DATA to override CI paths.\n  - Run \"make ci-clean\" if an old CI install remains.\n\n"

ci-prepare: ## Create CI directories if missing (non-destructive)
	@mkdir -p .ci "$(CI_DATA)"


ci-bootstrap: ci-deps ci-prepare db-up ## Ensure CI Moodle is present; reuse if already installed
	@if [ -f "$(CI_MOODLE)/version.php" ]; then \
	  if [ ! -f "$(CI_MOODLE)/config.php" ]; then \
	    echo "→ Completing CI Moodle setup (no config.php)…"; \
	    mkdir -p "$(CI_DATA)" "$(CI_DATA)/phpu_moodledata" "$(CI_DATA)/behat_moodledata" "$(CI_DATA)/behat_dump"; \
	    chmod -R 777 "$(CI_DATA)"; \
	    command -v mysql >/dev/null 2>&1 && mysql -u"$(CI_DB_USER)" -p"$(CI_DB_PASS)" -h "$(DB_HOST)" --port="$(DB_PORT)" -e "CREATE DATABASE IF NOT EXISTS \`$(CI_DB_NAME)\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" || true; \
	    DB_TYPE=$(DB_TYPE) CI_DB_NAME=$(CI_DB_NAME) CI_DB_USER=$(CI_DB_USER) CI_DB_PASS=$(CI_DB_PASS) DB_HOST=$(DB_HOST) DB_PORT=$(DB_PORT) CI_DATA=$(CI_DATA) CI_MOODLE=$(CI_MOODLE) \
	    php -r 'require "ci/vendor/autoload.php"; $$r=new MoodlePluginCI\Installer\Database\DatabaseResolver(); $$db=$$r->resolveDatabase(getenv("DB_TYPE"), getenv("CI_DB_NAME"), getenv("CI_DB_USER"), getenv("CI_DB_PASS"), getenv("DB_HOST"), getenv("DB_PORT")); $$c=new MoodlePluginCI\Bridge\MoodleConfig(); $$d=realpath(getenv("CI_DATA")); $$cfg=$$c->createContents($$db, $$d); file_put_contents(getenv("CI_MOODLE")."/config.php", $$cfg);' ; \
	  else \
	    echo "→ Reusing existing CI Moodle at $(CI_MOODLE)"; \
	  fi; \
	elif [ -d "$(CI_MOODLE)/.git" ]; then \
	  echo "→ Updating existing CI Moodle checkout to $(MOODLE_REF)…"; \
	  git -C "$(CI_MOODLE)" fetch --depth=1 origin $(MOODLE_REF) && \
	  git -C "$(CI_MOODLE)" checkout -f $(MOODLE_REF) && \
	  if [ ! -f "$(CI_MOODLE)/config.php" ]; then \
	    echo "→ Generating config.php for updated checkout…"; \
	    mkdir -p "$(CI_DATA)" "$(CI_DATA)/phpu_moodledata" "$(CI_DATA)/behat_moodledata" "$(CI_DATA)/behat_dump"; \
	    chmod -R 777 "$(CI_DATA)"; \
	    command -v mysql >/dev/null 2>&1 && mysql -u"$(CI_DB_USER)" -p"$(CI_DB_PASS)" -h "$(DB_HOST)" --port="$(DB_PORT)" -e "CREATE DATABASE IF NOT EXISTS \`$(CI_DB_NAME)\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" || true; \
	    DB_TYPE=$(DB_TYPE) CI_DB_NAME=$(CI_DB_NAME) CI_DB_USER=$(CI_DB_USER) CI_DB_PASS=$(CI_DB_PASS) DB_HOST=$(DB_HOST) DB_PORT=$(DB_PORT) CI_DATA=$(CI_DATA) CI_MOODLE=$(CI_MOODLE) \
	    php -r 'require "ci/vendor/autoload.php"; $$r=new MoodlePluginCI\Installer\Database\DatabaseResolver(); $$db=$$r->resolveDatabase(getenv("DB_TYPE"), getenv("CI_DB_NAME"), getenv("CI_DB_USER"), getenv("CI_DB_PASS"), getenv("DB_HOST"), getenv("DB_PORT")); $$c=new MoodlePluginCI\Bridge\MoodleConfig(); $$d=realpath(getenv("CI_DATA")); $$cfg=$$c->createContents($$db, $$d); file_put_contents(getenv("CI_MOODLE")."/config.php", $$cfg);' ; \
	  fi; \
    elif [ ! -e "$(CI_MOODLE)" ] || [ -z "`ls -A \"$(CI_MOODLE)\" 2>/dev/null`" ]; then \
      echo "▶ Setting up CI Moodle in $(CI_MOODLE)…"; \
      if [ "$(CI_RESET_DB_ON_INSTALL)" = "1" ]; then \
        echo "→ Resetting CI DB $(CI_DB_NAME) before install (if exists)…"; \
        $(MAKE) ci-drop-db || true; \
      fi; \
      PATH=$(NODE22_BIN):$$PATH ./ci/bin/moodle-plugin-ci install \
        --moodle $(CI_MOODLE) \
        --data $(CI_DATA) \
        --plugin . \
        --repo $(MOODLE_REPO) \
        --branch=$(MOODLE_REF) \
        --db-type=$(DB_TYPE) \
        --db-host=$(DB_HOST) \
        --db-port=$(DB_PORT) \
        --db-user=$(CI_DB_USER) \
        --db-pass=$(CI_DB_PASS) \
        --db-name=$(CI_DB_NAME) \
        --node-version=$(CI_NODE_VERSION) \
        $(if $(CI_NO_PLUGIN_NODE),--no-plugin-node,) \
        $(if $(CI_NO_INIT),--no-init,); \
	else \
	  echo "$(CI_MOODLE) exists and is not a Moodle checkout. Run: make ci-clean or set CI_MOODLE to a new path."; \
	  exit 1; \
	fi

ci-clean: ci-drop-db ## Remove CI Moodle and data directories (dangerous)
	rm -rf "$(CI_MOODLE)" "$(CI_DATA)"

# ---- DB reset solo para CI (seguro) ----

# Docker Compose file/service (can be overridden for test DB stack)
COMPOSE_FILE ?= docker-compose.yml
DB_SERVICE   ?= mariadb

ci-drop-db: db-up ## Drop CI DB (dangerous: checks name!)
	@if [ -z "$(CI_DB_NAME)" ]; then echo "CI_DB_NAME is empty"; exit 1; fi
	@if ! echo "$(CI_DB_NAME)" | grep -Eq '^(moodle|ci|behat|phpu)'; then \
	  echo "Refusing to drop non-CI database: $(CI_DB_NAME)"; exit 1; \
	fi
	@echo "→ Dropping database $(CI_DB_NAME) on $(DB_HOST):$(DB_PORT)…"
	@if command -v mysql >/dev/null 2>&1; then \
	  for i in $$(seq 1 30); do \
	    mysqladmin ping -h"$(DB_HOST)" -P"$(DB_PORT)" -u"$(CI_DB_USER)" -p"$(CI_DB_PASS)" --silent && break || true; \
	    sleep 1; \
	  done; \
	  mysql -u"$(CI_DB_USER)" -p"$(CI_DB_PASS)" -h "$(DB_HOST)" --port="$(DB_PORT)" \
	    -e "DROP DATABASE IF EXISTS \`$(CI_DB_NAME)\`;"; \
	else \
	  for i in $$(seq 1 30); do \
	    docker compose -f $(COMPOSE_FILE) exec -T $(DB_SERVICE) \
	      sh -lc 'mysqladmin ping -h127.0.0.1 -P3306 -uroot -proot --silent' && break || true; \
	    sleep 1; \
	  done; \
	  docker compose -f $(COMPOSE_FILE) exec -T $(DB_SERVICE) \
	    sh -lc 'mysql -u"$(CI_DB_USER)" -p"$(CI_DB_PASS)" -h127.0.0.1 -P3306 -e "DROP DATABASE IF EXISTS \`$(CI_DB_NAME)\`;"'; \
	fi


# Local DB defaults (override if needed)
DB_TYPE   ?= mariadb
DB_HOST   ?= 127.0.0.1
DB_PORT   ?= 3306
DB_NAME   ?= moodle
DB_USER   ?= moodle
DB_PASS   ?= moodle

# CI DB credentials for moodle-plugin-ci (needs ability to create DBs)
CI_DB_NAME ?= moodle_behat
CI_DB_USER ?= root
CI_DB_PASS ?= root

# Detect current Git branch
BRANCH    ?= $(shell git rev-parse --abbrev-ref HEAD)


check-env: ## Ensure $(ENV_FILE) exists (copy from .env.dist)
	@if [ ! -f $(ENV_FILE) ]; then \
	    cp .env.dist $(ENV_FILE); \
	    echo "Created $(ENV_FILE) from .env.dist"; \
	fi

check-docker: ## Check Docker and Docker Compose availability
	@command -v docker >/dev/null 2>&1 || { echo "Docker is not installed"; exit 1; }
	@docker compose version >/dev/null 2>&1 || { echo "Docker Compose is required"; exit 1; }

# Start Docker containers in interactive mode
up: check-docker check-env ## Start Docker services in foreground
	docker compose -f $(COMPOSE_FILE) up

# Start Docker containers in background mode
upd: check-docker check-env ## Start Docker services in background
	docker compose -f $(COMPOSE_FILE) up -d

# Stop containers
down: check-docker check-env ## Stop Docker services
	docker compose -f $(COMPOSE_FILE) down

# Start only the DB service
db-up: check-docker check-env ## Start DB service only
	docker compose -f $(COMPOSE_FILE) up -d $(DB_SERVICE)

# Stop only the DB service
db-down: check-docker check-env ## Stop DB service only
	docker compose -f $(COMPOSE_FILE) stop $(DB_SERVICE)


# Pull latest images
pull: check-docker check-env ## Pull latest Docker images
	docker compose -f docker-compose.yml pull

# Build containers
build: check-docker check-env ## Build Docker images
	docker compose build

# Open shell inside moodle container
shell: check-docker check-env ## Open interactive shell in Moodle container
	docker compose -f $(COMPOSE_FILE) exec moodle sh


# Install local dependencies for Moodle Plugin CI
ci-deps: ## Install local moodle-plugin-ci into ./ci
	@if [ ! -d ci ]; then \
	    composer create-project -n --no-dev --prefer-dist moodlehq/moodle-plugin-ci ci ^4; \
	    echo -e "\033[32m✔ Moodle plugin CI installed in ./ci\033[0m"; \
	else \
	    echo -e "\033[33m→ ./ci already exists, skipping installation\033[0m"; \
	fi

# Lint / análisis individuales
phplint: ci-bootstrap ## Run PHP Lint
	@echo -e "\033[36m▶ PHP lint…\033[0m"
	$(CI_BIN) phplint .

phpmd: ci-bootstrap ## Run PHP Mess Detector
	@echo -e "\033[36m▶ PHP Mess Detector…\033[0m"
	$(CI_BIN) phpmd .

phpcs: ci-bootstrap ## Run Moodle CodeSniffer standard
	@echo -e "\033[36m▶ Moodle CodeSniffer…\033[0m"
	$(CI_BIN) phpcs --max-warnings 0 .

phpcbf: ci-bootstrap ## Run Code Beautifier and Fixer
	@echo -e "\033[36m▶ Code Beautifier & Fixer…\033[0m"
	$(CI_BIN) phpcbf .

phpdoc: ci-bootstrap ## Run Moodle PHPDoc Checker
	@echo -e "\033[36m▶ PHPDoc checker…\033[0m"
	$(CI_BIN) phpdoc .

phpcpd: ci-bootstrap ## Run PHP Copy/Paste Detector
	@echo -e "\033[36m▶ PHPCPD…\033[0m"
	$(CI_BIN) phpcpd .

savepoints: ci-bootstrap ## Check upgrade savepoints
	@echo -e "\033[36m▶ Savepoints…\033[0m"
	$(CI_BIN) savepoints .

mustache: ci-bootstrap ## Run Mustache lint
	@echo -e "\033[36m▶ Mustache lint…\033[0m"
	$(CI_BIN) mustache $(MOODLE_ARG) .

validate: ci-bootstrap ## Validate plugin
	@echo -e "\033[36m▶ Code validation…\033[0m"
	$(CI_BIN) validate $(MOODLE_ARG) .

# Tests
phpunit-init: ## Initialise Moodle PHPUnit environment if needed
	@if [ -f "$(CI_MOODLE)/admin/tool/phpunit/cli/init.php" ]; then \
	  if [ ! -f "$(CI_MOODLE)/phpunit.xml" ]; then \
	    echo "→ Initialising Moodle PHPUnit environment…"; \
	    mkdir -p .ci; \
	    echo "max_input_vars=5000" > .ci/php.ini; \
	    echo "memory_limit=512M" >> .ci/php.ini; \
	    PHPRC=.ci PHP_INI_SCAN_DIR= php -n -c .ci/php.ini "$(CI_MOODLE)/admin/tool/phpunit/cli/init.php" --force; \
	  fi; \
	fi

phpunit: ci-bootstrap sync-plugin phpunit-init ## Run PHPUnit tests
	@echo -e "\033[36m▶ PHPUnit…\033[0m"
	PHPRC=.ci PHP_INI_SCAN_DIR= $(CI_BIN) phpunit $(MOODLE_ARG) --fail-on-warning .

behat-init: ## Initialise Moodle Behat environment if needed
	@if [ -f "$(CI_MOODLE)/admin/tool/behat/cli/init.php" ]; then \
	  CFG="$(CI_DATA)/behat_moodledata/behatrun/behat/behat.yml"; \
	  if [ ! -f "$$CFG" ]; then \
	    echo "→ Initialising Moodle Behat environment…"; \
	    mkdir -p .ci; \
	    echo "max_input_vars=5000" > .ci/php.ini; \
	    echo "memory_limit=512M" >> .ci/php.ini; \
	    PHPRC=.ci PHP_INI_SCAN_DIR= php -n -c .ci/php.ini "$(CI_MOODLE)/admin/tool/behat/cli/init.php" --parallel=1 --optimize; \
	  fi; \
	fi

behat: ci-bootstrap sync-plugin behat-init ## Run Behat features
	@echo -e "\033[36m▶ Behat…\033[0m"
	$(CI_BIN) behat $(MOODLE_ARG) --profile chrome --tags=@$(PLUGIN) .

parallel: ci-bootstrap ## Run all tests & analysis in parallel (plugin-ci)
	@echo -e "\033[36m▶ Parallel (plugin-ci)…\033[0m"
	$(CI_BIN) parallel $(MOODLE_ARG) .


# --- Simple local test runner using a minimal DB stack ---
TEST_DB_PORT ?= 3307
test-up: ## Start minimal DB for tests (docker-compose.test.yml)
	COMPOSE_FILE=docker-compose.test.yml DB_SERVICE=ci-db \
	DB_PORT=$(TEST_DB_PORT) $(MAKE) db-up

test-down: ## Stop minimal DB for tests
	COMPOSE_FILE=docker-compose.test.yml DB_SERVICE=ci-db \
	$(MAKE) db-down

test: ## Run PHPUnit tests using minimal DB stack (no full moodle container)
	@echo -e "\033[36m▶ make test (docker DB on port $(TEST_DB_PORT))…\033[0m"
	COMPOSE_FILE=docker-compose.test.yml DB_SERVICE=ci-db DB_HOST=127.0.0.1 DB_PORT=$(TEST_DB_PORT) \
	CI_DB_USER=root CI_DB_PASS=root CI_DB_NAME=moodle_ci \
	CI_NO_PLUGIN_NODE=$(CI_NO_PLUGIN_NODE) CI_NODE_VERSION=$(CI_NODE_VERSION) \
	$(MAKE) phpunit

test-reset: ## Drop CI DB used by make test (dangerous)
	COMPOSE_FILE=docker-compose.test.yml DB_SERVICE=ci-db DB_HOST=127.0.0.1 DB_PORT=$(TEST_DB_PORT) \
	CI_DB_USER=root CI_DB_PASS=root CI_DB_NAME=moodle_ci \
	$(MAKE) ci-drop-db




# Agrupadores
lint: phplint phpmd phpcs ## Quick lint (no tests)
	@true

style: phpcs phpcbf phpdoc phpcpd ## Style & docs checks
	@true

analyze: phplint phpmd phpcs phpdoc phpcpd savepoints mustache validate ## Full analysis (no tests)
	@true

# Removed duplicate test aggregator to avoid overriding the dockerised test runner

check: analyze test ## Full CI suite (analysis + tests)
	@echo -e "\033[32m✔ CI completed\033[0m"







# Clean environment
clean: check-docker check-env ## Remove containers, volumes and orphans
	docker compose down -v --remove-orphans

# Create release package
package: ## Build a ZIP release with VERSION=X.Y.Z
	@if [ -z "$(VERSION)" ]; then \
	echo "VERSION variable is required"; \
	exit 1; \
	fi
	composer archive --format=zip --file=$(PLUGIN)-$(VERSION)
sync-plugin: ## Sync current plugin source into CI Moodle checkout
	@echo "→ Syncing plugin into $(CI_MOODLE)/repository/omeka …"
	@mkdir -p "$(CI_MOODLE)/repository/omeka"
	@rsync -a --delete \
	  --exclude='.git/' --exclude='.github/' --exclude='.ci/' --exclude='ci/' \
	  --exclude='node_modules/' --exclude='docker*/' \
	  ./ "$(CI_MOODLE)/repository/omeka/"
