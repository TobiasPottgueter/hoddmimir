.DEFAULT_GOAL := help

.PHONY: help secrets production-secrets production-secrets-test app-secret-staging-test config build up down logs ps migration-wrapper-test migrate backend-test mariadb-bootstrap-test backend-integration-wrapper-test backend-integration backend-coverage-wrapper-test backend-coverage mutation-image mutation-config mutation-critical mutation-global mutation frontend-test e2e-wrapper-test e2e api-schema-drift-test supply-chain-contract-test secret-scan container-multiarch container-security supply-chain test smoke clean inventory lint syntax deployment-test ping bootstrap deploy verify

ANSIBLE_DIRECTORY := deployment/ansible
ANSIBLE_TOOL_PATH := $(CURDIR)/$(ANSIBLE_DIRECTORY)/.venv/bin
ANSIBLE_EXAMPLE_INVENTORY := inventories/production/hosts.example.yml
ANSIBLE_PRODUCTION_INVENTORY := inventories/production/hosts.yml
ANSIBLE_VAULT_PASSWORD_FILE ?= $(CURDIR)/.secrets/production/ansible_vault_password
ANSIBLE_LOCAL_VAULT_ARGS = $(if $(wildcard $(ANSIBLE_VAULT_PASSWORD_FILE)),--vault-password-file $(ANSIBLE_VAULT_PASSWORD_FILE),)
ANSIBLE_VAULT_ARGS ?= $(if $(wildcard $(ANSIBLE_VAULT_PASSWORD_FILE)),--vault-password-file $(ANSIBLE_VAULT_PASSWORD_FILE),--ask-vault-pass)
INFECTION_THREADS ?= max
REUSE_MUTATION_COVERAGE ?= 0
MUTATION_IMAGE := hoddmimir-backend-mutation:local
GITLEAKS_IMAGE := zricethezav/gitleaks:v8.24.3@sha256:e1b35e12a8c6fa8901f060459cfb6b2fc4c484d3afbe3b029733a3bbfab07055
TRIVY_IMAGE := aquasec/trivy:0.64.1@sha256:a8ca29078522f30393bdb34225e4c0994d38f37083be81a42da3a2a7e1488e9e
SUPPLY_CHAIN_DIRECTORY ?= $(CURDIR)/artifacts/supply-chain

help: ## Show available commands
	@awk 'BEGIN {FS = ":.*## "; printf "Usage: make <target>\n\n"} /^[a-zA-Z_-]+:.*## / {printf "  %-16s %s\n", $$1, $$2}' $(MAKEFILE_LIST)

secrets: ## Generate local development secrets when missing
	./scripts/init-dev-secrets.sh

production-secrets: ## Generate distinct local production secrets and encrypted Ansible Vault
	./scripts/init-production-secrets.sh

production-secrets-test: ## Verify safe, idempotent production secret initialization
	sh scripts/tests/test-init-production-secrets.sh

app-secret-staging-test: ## Verify fail-closed non-root secret staging in native Linux containers
	sh scripts/tests/test-app-secret-staging.sh

config: secrets ## Validate the Docker Compose model
	docker compose config --quiet

build: secrets ## Build all application and database images
	docker compose build

up: migrate ## Apply migrations and start the complete local stack
	docker compose up --detach --wait

down: ## Stop the local stack
	docker compose down

logs: ## Follow logs from all services
	docker compose logs --follow

ps: ## Show service state
	docker compose ps

migration-wrapper-test: ## Verify local migration command ordering without Docker
	sh scripts/tests/test-migrate-database.sh

migrate: secrets ## Apply pending V2 schema migrations through the one-shot container
	./scripts/migrate-database.sh

backend-test: ## Build and run the PHP validation target
	docker build --target backend-test --file docker/php/Dockerfile .

backend-integration-wrapper-test: ## Test integration cleanup and exit semantics without Docker
	sh scripts/tests/test-backend-integration-cleanup.sh

mariadb-bootstrap-test: ## Verify fail-closed, idempotent MariaDB user bootstrap against real containers
	sh scripts/tests/test-mariadb-user-bootstrap.sh

backend-integration: migration-wrapper-test backend-integration-wrapper-test mariadb-bootstrap-test ## Run the isolated MariaDB 11.4 integration suite
	./scripts/test-backend-integration.sh

backend-coverage-wrapper-test: ## Test owned split coverage cleanup and failure propagation without Docker
	sh scripts/tests/test-backend-coverage.sh

backend-coverage: backend-coverage-wrapper-test backend-integration-wrapper-test ## Compose disjoint core and MariaDB coverage, then enforce gates
	./scripts/run-backend-coverage.sh

mutation-image: ## Build the pinned PHP 8.5 Infection runtime
	docker build --target backend-mutation-runtime --tag $(MUTATION_IMAGE) --file docker/php/Dockerfile .

mutation-config: mutation-image ## Validate both Infection configurations and source buckets
	mkdir -p backend/var/mutation
	docker run --rm --user "$$(id -u):$$(id -g)" --env HOME=/tmp --volume "$(CURDIR)/backend/var/mutation:/app/var/mutation" $(MUTATION_IMAGE) sh tools/run-mutation.sh config

mutation-critical: mutation-image ## Enforce 90 percent MSI for critical scheduler and state-machine code
	mkdir -p backend/var/mutation
	docker run --rm --user "$$(id -u):$$(id -g)" --env HOME=/tmp --env XDEBUG_MODE=coverage --env INFECTION_THREADS="$(INFECTION_THREADS)" --env REUSE_MUTATION_COVERAGE="$(REUSE_MUTATION_COVERAGE)" --volume "$(CURDIR)/backend/var/mutation:/app/var/mutation" $(MUTATION_IMAGE) sh tools/run-mutation.sh critical

mutation-global: mutation-image ## Enforce 80 percent MSI for handwritten backend source
	mkdir -p backend/var/mutation
	docker run --rm --user "$$(id -u):$$(id -g)" --env HOME=/tmp --env XDEBUG_MODE=coverage --env INFECTION_THREADS="$(INFECTION_THREADS)" --env REUSE_MUTATION_COVERAGE="$(REUSE_MUTATION_COVERAGE)" --volume "$(CURDIR)/backend/var/mutation:/app/var/mutation" $(MUTATION_IMAGE) sh tools/run-mutation.sh global

mutation: mutation-image ## Enforce both mutation gates with one reusable coverage run
	mkdir -p backend/var/mutation
	docker run --rm --user "$$(id -u):$$(id -g)" --env HOME=/tmp --env XDEBUG_MODE=coverage --env INFECTION_THREADS="$(INFECTION_THREADS)" --env REUSE_MUTATION_COVERAGE="$(REUSE_MUTATION_COVERAGE)" --volume "$(CURDIR)/backend/var/mutation:/app/var/mutation" $(MUTATION_IMAGE) sh tools/run-mutation.sh all

frontend-test: ## Build and run the frontend validation target
	docker build --target frontend-test --file docker/web/Dockerfile .

e2e-wrapper-test: ## Verify E2E diagnostics, cleanup, and exit semantics without Docker
	sh scripts/tests/test-e2e-cleanup.sh

e2e: e2e-wrapper-test ## Run isolated MariaDB-seeded Playwright browser flows
	./scripts/test-e2e.sh

api-schema-drift-test: ## Test the official Proxmox schema drift policy offline
	python3 -m unittest scripts/tests/test_proxmox_api_schema_drift.py -v

supply-chain-contract-test: ## Verify pinned CI supply-chain gates without running scanners
	python3 -m unittest scripts/tests/test_supply_chain_gates.py -v

secret-scan: ## Scan the complete Git history with the digest-pinned Gitleaks image
	docker run --rm --volume "$(CURDIR):/repo:ro" --workdir /repo $(GITLEAKS_IMAGE) git --gitleaks-ignore-path /repo/.gitleaksignore --redact --verbose --no-banner /repo

container-multiarch: ## Build production images for amd64 and arm64 without pushing them
	SUPPLY_CHAIN_DIRECTORY="$(SUPPLY_CHAIN_DIRECTORY)" ./scripts/ci/build-container-images.sh

container-security: ## Scan the exact six platform images and generate CycloneDX SBOMs
	TRIVY_IMAGE="$(TRIVY_IMAGE)" SUPPLY_CHAIN_DIRECTORY="$(SUPPLY_CHAIN_DIRECTORY)" ./scripts/ci/scan-container-images.sh

supply-chain: supply-chain-contract-test secret-scan container-multiarch container-security ## Run all local supply-chain acceptance gates

test: backend-test frontend-test ## Run backend and frontend validation

smoke: up ## Verify the running web application
	curl --fail --silent --show-error http://localhost:$${WEB_PORT:-8080}/api/health

clean: ## Stop the stack and remove local database data
	docker compose down --volumes --remove-orphans

inventory: ## Generate the ignored production Ansible inventory
	./scripts/init-ansible-inventory.sh

lint: ## Lint the Ansible deployment files
	cd $(ANSIBLE_DIRECTORY) && PATH="$(ANSIBLE_TOOL_PATH):$$PATH" yamllint .
	cd $(ANSIBLE_DIRECTORY) && PATH="$(ANSIBLE_TOOL_PATH):$$PATH" ansible-lint .

syntax: ## Check all Ansible playbooks without remote access
	cd $(ANSIBLE_DIRECTORY) && PATH="$(ANSIBLE_TOOL_PATH):$$PATH" ansible-inventory --inventory $(ANSIBLE_EXAMPLE_INVENTORY) --list $(ANSIBLE_LOCAL_VAULT_ARGS) >/dev/null
	cd $(ANSIBLE_DIRECTORY) && PATH="$(ANSIBLE_TOOL_PATH):$$PATH" ansible-playbook --inventory $(ANSIBLE_EXAMPLE_INVENTORY) playbooks/bootstrap.yml --syntax-check $(ANSIBLE_LOCAL_VAULT_ARGS)
	cd $(ANSIBLE_DIRECTORY) && PATH="$(ANSIBLE_TOOL_PATH):$$PATH" ansible-playbook --inventory $(ANSIBLE_EXAMPLE_INVENTORY) playbooks/deploy.yml --syntax-check $(ANSIBLE_LOCAL_VAULT_ARGS)
	cd $(ANSIBLE_DIRECTORY) && PATH="$(ANSIBLE_TOOL_PATH):$$PATH" ansible-playbook --inventory $(ANSIBLE_EXAMPLE_INVENTORY) playbooks/verify.yml --syntax-check $(ANSIBLE_LOCAL_VAULT_ARGS)

deployment-test: production-secrets-test ## Run isolated deployment contract and rollback tests
	cd $(ANSIBLE_DIRECTORY) && python3 -m unittest discover -s tests -p 'test_*.py' -v

ping: inventory ## Test Ansible connectivity to the configured deployment host
	cd $(ANSIBLE_DIRECTORY) && PATH="$(ANSIBLE_TOOL_PATH):$$PATH" ansible --inventory $(ANSIBLE_PRODUCTION_INVENTORY) hoddmimir_hosts --module-name ansible.builtin.ping $(ANSIBLE_VAULT_ARGS)

bootstrap: inventory ## Bootstrap Alpine, Python, Docker, and OpenRC on the deployment host
	cd $(ANSIBLE_DIRECTORY) && PATH="$(ANSIBLE_TOOL_PATH):$$PATH" ansible-playbook --inventory $(ANSIBLE_PRODUCTION_INVENTORY) playbooks/bootstrap.yml $(ANSIBLE_VAULT_ARGS)

deploy: inventory ## Deploy the pinned production images to the deployment host
	cd $(ANSIBLE_DIRECTORY) && PATH="$(ANSIBLE_TOOL_PATH):$$PATH" ansible-playbook --inventory $(ANSIBLE_PRODUCTION_INVENTORY) playbooks/deploy.yml $(ANSIBLE_VAULT_ARGS)

verify: inventory ## Verify the deployed services and API health
	cd $(ANSIBLE_DIRECTORY) && PATH="$(ANSIBLE_TOOL_PATH):$$PATH" ansible-playbook --inventory $(ANSIBLE_PRODUCTION_INVENTORY) playbooks/verify.yml $(ANSIBLE_VAULT_ARGS)
