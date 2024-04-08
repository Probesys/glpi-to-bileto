# This file is part of Bileto.
# Copyright 2022-2024 Probesys
# SPDX-License-Identifier: AGPL-3.0-or-later

.DEFAULT_GOAL := help

ifdef NO_DOCKER
	PHP = php
	COMPOSER = composer
else
	PHP = ./docker/bin/php
	COMPOSER = ./docker/bin/composer
endif

.PHONY: docker-build
docker-build: ## Rebuild the Docker image
	docker build --pull -t glpi-to-docker docker/

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
