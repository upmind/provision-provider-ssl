.PHONY: help

# List all available Makefile commands.
help:
	@echo "Available commands:"
	@echo "   make help                  : List all available Makefile commands"
	@echo "   make setup                 : Start the dev environment with the default PHP (8.3)"
	@echo "   make setup-php83           : Start the dev environment with PHP 8.3"
	@echo "   make setup-php84           : Start the dev environment with PHP 8.4"
	@echo "   make setup-php85           : Start the dev environment with PHP 8.5"
	@echo "   make shell                 : Get an interactive shell on the PHP container"
	@echo "   make static-analysis       : Run Static Analysis (PHPStan)"
	@echo "   make coding-standards      : Run Coding Standards (PHP-CS-Fixer)"
	@echo "   make test                  : Run Unit Tests (PHPUnit)"
	@echo "   make start-containers      : Start the dev environment"
	@echo "   make stop-containers       : Stop the dev environment"
	@echo "   make kill-containers       : Stop and remove all containers"
	@echo "   make composer-install      : Install composer dependencies"

# Note: the Dockerfile/compose file are prepared *before* stop-containers, so that these targets
# work on a fresh clone where the gitignored docker-compose.yml does not exist yet.

# Typing 'make setup' will start the dev environment with the default PHP version
setup: setup-php83

# Typing 'make setup-php83' will start the dev environment with PHP 8.3
setup-php83: --prep-dockerfile-php83 stop-containers start-containers --remove-packages composer-install

# Typing 'make setup-php84' will start the dev environment with PHP 8.4
setup-php84: --prep-dockerfile-php84 stop-containers start-containers --remove-packages composer-install

# Typing 'make setup-php85' will start the dev environment with PHP 8.5
setup-php85: --prep-dockerfile-php85 stop-containers start-containers --remove-packages composer-install

# Get a shell on the PHP container
shell:
	docker compose exec -it provision-provider-ssl /bin/bash

# Run Static Analysis (PHPStan)
static-analysis:
	docker compose exec provision-provider-ssl ./vendor/bin/phpstan analyse --memory-limit=1G

# Run Coding Standards (PHP-CS-Fixer)
coding-standards:
	docker compose exec provision-provider-ssl php ./bin/php-cs-fixer-v3.phar fix --config=./.php-cs-fixer.dist.php

# Run Unit Tests (PHPUnit)
test:
	docker compose exec provision-provider-ssl ./vendor/bin/phpunit

# Start the dev environment
start-containers:
	docker compose up -d --build

# Stop the dev environment (tolerate nothing being up)
stop-containers:
	-docker compose down

# Stop and remove all containers
kill-containers:
	docker compose kill
	docker compose rm --force

# Install composer dependencies
composer-install:
	docker compose exec provision-provider-ssl composer install --no-interaction

# Copy Dockerfile for PHP 8.3
--prep-dockerfile-php83: --prep-docker-compose-file --remove-dockerfile
	cp "./.docker/Dockerfile.php83" "./.docker/Dockerfile"

# Copy Dockerfile for PHP 8.4
--prep-dockerfile-php84: --prep-docker-compose-file --remove-dockerfile
	cp "./.docker/Dockerfile.php84" "./.docker/Dockerfile"

# Copy Dockerfile for PHP 8.5
--prep-dockerfile-php85: --prep-docker-compose-file --remove-dockerfile
	cp "./.docker/Dockerfile.php85" "./.docker/Dockerfile"

# Copy docker-compose.yml file
--prep-docker-compose-file:
	[ -f "./docker-compose.yml" ] || cp "./docker-compose.yml.example" "./docker-compose.yml"

# Remove Dockerfile
--remove-dockerfile:
	rm -f ./.docker/Dockerfile

# Remove composer related files
--remove-packages: --remove-lockfile --remove-vendor

# Remove composer.lock file
--remove-lockfile:
	docker compose exec provision-provider-ssl rm -f ./composer.lock

# Remove vendor directory
--remove-vendor:
	docker compose exec provision-provider-ssl rm -rf ./vendor
