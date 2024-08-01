# This file is part of Bileto.
# Copyright 2022-2024 Probesys
# SPDX-License-Identifier: AGPL-3.0-or-later

.DEFAULT_GOAL := help

USER = $(shell id -u):$(shell id -g)

DOCKER_COMPOSE = docker compose -p glpi-to-bileto -f docker/docker-compose.yml

ifdef NO_DOCKER
	PHP = php
	COMPOSER = composer
else
	PHP = ./docker/bin/php
	COMPOSER = ./docker/bin/composer
endif

.PHONY: docker-start
docker-start: ## Start a development server with Docker
	$(DOCKER_COMPOSE) up

.PHONY: docker-build
docker-build: ## Rebuild Docker containers
	$(DOCKER_COMPOSE) build

.PHONY: docker-clean
docker-clean: ## Clean the Docker stuff
	$(DOCKER_COMPOSE) down -v

.PHONY: docker-db-import
docker-db-import: ## Import a SQL file from GLPI
ifndef FILE
	$(error You need to provide a "FILE" argument)
endif
	./docker/bin/mariadb < $(FILE)

.PHONY: install
install: ## Install the development dependencies
	$(COMPOSER) install

.PHONY: lint
lint: ## Execute the linters
	$(PHP) vendor/bin/phpstan analyse -c .phpstan.neon
	$(PHP) vendor/bin/phpcs

.PHONY: help
help:
	@grep -h -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-30s\033[0m %s\n", $$1, $$2}'
