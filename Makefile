ENV_FILE ?= .env
PLUGIN = repository_omeka


# Local DB defaults (override if needed)
DB_TYPE   ?= mariadb
DB_HOST   ?= 127.0.0.1
DB_PORT   ?= 3306
DB_NAME   ?= moodle
DB_USER   ?= moodle
DB_PASS   ?= moodle

# Detect current Git branch
BRANCH    ?= $(shell git rev-parse --abbrev-ref HEAD)


check-env:
	@if [ ! -f $(ENV_FILE) ]; then \
	    cp .env.dist $(ENV_FILE); \
	    echo "Created $(ENV_FILE) from .env.dist"; \
	fi

check-docker:
	@command -v docker >/dev/null 2>&1 || { echo "Docker is not installed"; exit 1; }
	@docker compose version >/dev/null 2>&1 || { echo "Docker Compose is required"; exit 1; }

# Start Docker containers in interactive mode
up: check-docker check-env
	docker compose up

# Start Docker containers in background mode
upd: check-docker check-env
	docker compose up -d

# Stop containers
down: check-docker check-env
	docker compose down

# Pull latest images
pull: check-docker check-env
	docker compose -f docker-compose.yml pull

# Build containers
build: check-docker check-env
	docker compose build

# Open shell inside moodle container
shell: check-docker check-env
	docker compose exec moodle sh


# Install local dependencies for Moodle Plugin CI
ci-deps:
	@if [ ! -d ci ]; then \
	    composer create-project -n --no-dev --prefer-dist moodlehq/moodle-plugin-ci ci ^4; \
	    echo -e "\033[32m✔ Moodle plugin CI installed in ./ci\033[0m"; \
	else \
	    echo -e "\033[33m→ ./ci already exists, skipping installation\033[0m"; \
	fi

# Run all CI checks against your Docker-hosted database
check: ci-deps
	@echo -e "\033[36m▶ Initialising Moodle for plugin CI…\033[0m" && \
	./ci/bin/moodle-plugin-ci install \
	  --plugin . \
	  --branch=$(BRANCH) \
	  --db-type=$(DB_TYPE) \
	  --db-host=$(DB_HOST) \
	  --db-port=$(DB_PORT) \
	  --db-user=$(DB_USER) \
	  --db-pass=$(DB_PASS) \
	  --db-name=$(DB_NAME) && \
	\
	echo -e "\033[36m▶ PHP lint…\033[0m" && \
	./ci/bin/moodle-plugin-ci phplint && \
	\
	echo -e "\033[36m▶ PHP Mess Detector…\033[0m" && \
	./ci/bin/moodle-plugin-ci phpmd && \
	\
	echo -e "\033[36m▶ Moodle Code Checker…\033[0m" && \
	./ci/bin/moodle-plugin-ci phpcs --max-warnings 0 && \
	\
	echo -e "\033[36m▶ Code validation…\033[0m" && \
	./ci/bin/moodle-plugin-ci validate && \
	\
	echo -e "\033[36m▶ Checking upgrade savepoints…\033[0m" && \
	./ci/bin/moodle-plugin-ci savepoints && \
	\
	echo -e "\033[36m▶ Mustache lint…\033[0m" && \
	./ci/bin/moodle-plugin-ci mustache && \
	\
	echo -e "\033[36m▶ PHPUnit tests…\033[0m" && \
	./ci/bin/moodle-plugin-ci phpunit --fail-on-warning && \
	\
	echo -e "\033[36m▶ Behat features…\033[0m" && \
	./ci/bin/moodle-plugin-ci behat --profile chrome


# Clean environment
clean: check-docker check-env
	docker compose down -v --remove-orphans

# Create release package
package:
	@if [ -z "$(VERSION)" ]; then \
	echo "VERSION variable is required"; \
	exit 1; \
	fi
	composer archive --format=zip --file=$(PLUGIN)-$(VERSION)
