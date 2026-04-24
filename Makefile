.PHONY: help install test lint fix analyze clean storage docker-build docker-test docker-shell

SHELL := /bin/bash
PHP = php
COMPOSER = composer
BIN = ./bin/file-broker

# ─────────────────────────────────────────────────────────────
# Help
# ─────────────────────────────────────────────────────────────

help: ## Show available commands
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

# ─────────────────────────────────────────────────────────────
# Build
# ─────────────────────────────────────────────────────────────

install: ## Install dependencies
	$(COMPOSER) install --optimize-autoloader --no-interaction

# ─────────────────────────────────────────────────────────────
# Docker
# ─────────────────────────────────────────────────────────────

docker-build: ## Build Docker image
	docker build -t file-broker:latest .

docker-test: ## Run tests in Docker
	docker run --rm -v $$(pwd):/app file-broker:latest make test

docker-shell: ## Open shell in Docker container
	docker run -it --rm -v $$(pwd):/app file-broker:latest sh

# ─────────────────────────────────────────────────────────────
# Testing
# ─────────────────────────────────────────────────────────────

test: ## Run PHPUnit tests
	$(PHP) vendor/bin/phpunit --colors=always --fail-on-risky --no-progress || true

test_unit: ## Run unit tests only
	@$(PHP) vendor/bin/phpunit --testsuite=unit --colors=always

test_integration: ## Run integration tests only
	@$(PHP) vendor/bin/phpunit --testsuite=integration --colors=always

test_coverage: ## Run tests with code coverage
	@$(PHP) vendor/bin/phpunit --coverage-html=coverage --coverage-text --colors=always

# ─────────────────────────────────────────────────────────────
# Code quality
# ─────────────────────────────────────────────────────────────

lint: ## Run PHP CS Fixer in dry-run mode
	$(PHP) vendor/bin/php-cs-fixer fix --dry-run --diff --verbose

fix: ## Run PHP CS Fixer
	$(PHP) vendor/bin/php-cs-fixer fix --verbose

analyze: ## Run PHPStan analysis
	$(PHP) vendor/bin/phpstan analyse -c phpstan.neon --memory-limit=512M

# ─────────────────────────────────────────────────────────────
# Maintenance
# ─────────────────────────────────────────────────────────────

clean: ## Clean generated files
	rm -rf coverage/ .phpunit.cache/ vendor/

storage: ## Create storage directories
	mkdir -p storage/{queues,dead-letter,retry}

# ─────────────────────────────────────────────────────────────
# CLI examples
# ─────────────────────────────────────────────────────────────

# make produce QUEUE=orders BODY='{"order_id":123}'
produce:
	$(BIN) produce $(QUEUE) "$(BODY)"

# make consume QUEUE=orders
consume:
	$(BIN) consume $(QUEUE)

# make stats QUEUE=orders
stats:
	$(BIN) stats $(QUEUE)

# make watch QUEUE=orders
watch:
	$(BIN) watch $(QUEUE)
