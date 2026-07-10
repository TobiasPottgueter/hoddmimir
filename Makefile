.DEFAULT_GOAL := help

.PHONY: help secrets config build up down logs ps backend-test frontend-test test smoke clean inventory lint syntax deployment-test ping bootstrap deploy verify

ANSIBLE_DIRECTORY := deployment/ansible
ANSIBLE_EXAMPLE_INVENTORY := inventories/production/hosts.example.yml
ANSIBLE_PRODUCTION_INVENTORY := inventories/production/hosts.yml
ANSIBLE_VAULT_ARGS ?= --ask-vault-pass

help: ## Show available commands
	@awk 'BEGIN {FS = ":.*## "; printf "Usage: make <target>\n\n"} /^[a-zA-Z_-]+:.*## / {printf "  %-16s %s\n", $$1, $$2}' $(MAKEFILE_LIST)

secrets: ## Generate local development secrets when missing
	./scripts/init-dev-secrets.sh

config: secrets ## Validate the Docker Compose model
	docker compose config --quiet

build: secrets ## Build all application and database images
	docker compose build

up: secrets ## Start the complete local stack
	docker compose up --detach --wait

down: ## Stop the local stack
	docker compose down

logs: ## Follow logs from all services
	docker compose logs --follow

ps: ## Show service state
	docker compose ps

backend-test: ## Build and run the PHP validation target
	docker build --target backend-test --file docker/php/Dockerfile .

frontend-test: ## Build and run the frontend validation target
	docker build --target frontend-test --file docker/web/Dockerfile .

test: backend-test frontend-test ## Run backend and frontend validation

smoke: up ## Verify the running web application
	curl --fail --silent --show-error http://localhost:$${WEB_PORT:-8080}/api/health

clean: ## Stop the stack and remove local database data
	docker compose down --volumes --remove-orphans

inventory: ## Generate the ignored production Ansible inventory
	./scripts/init-ansible-inventory.sh

lint: ## Lint the Ansible deployment files
	cd $(ANSIBLE_DIRECTORY) && yamllint .
	cd $(ANSIBLE_DIRECTORY) && ansible-lint .

syntax: ## Check all Ansible playbooks without remote access
	cd $(ANSIBLE_DIRECTORY) && ansible-inventory --inventory $(ANSIBLE_EXAMPLE_INVENTORY) --list >/dev/null
	cd $(ANSIBLE_DIRECTORY) && ansible-playbook --inventory $(ANSIBLE_EXAMPLE_INVENTORY) playbooks/bootstrap.yml --syntax-check
	cd $(ANSIBLE_DIRECTORY) && ansible-playbook --inventory $(ANSIBLE_EXAMPLE_INVENTORY) playbooks/deploy.yml --syntax-check
	cd $(ANSIBLE_DIRECTORY) && ansible-playbook --inventory $(ANSIBLE_EXAMPLE_INVENTORY) playbooks/verify.yml --syntax-check

deployment-test: ## Run isolated deployment contract and rollback tests
	cd $(ANSIBLE_DIRECTORY) && python3 -m unittest discover -s tests -p 'test_*.py' -v

ping: inventory ## Test Ansible connectivity to the configured deployment host
	cd $(ANSIBLE_DIRECTORY) && ansible --inventory $(ANSIBLE_PRODUCTION_INVENTORY) hoddmimir_hosts --module-name ansible.builtin.ping $(ANSIBLE_VAULT_ARGS)

bootstrap: inventory ## Bootstrap Alpine, Python, Docker, and OpenRC on the deployment host
	cd $(ANSIBLE_DIRECTORY) && ansible-playbook --inventory $(ANSIBLE_PRODUCTION_INVENTORY) playbooks/bootstrap.yml $(ANSIBLE_VAULT_ARGS)

deploy: inventory ## Deploy the pinned production images to the deployment host
	cd $(ANSIBLE_DIRECTORY) && ansible-playbook --inventory $(ANSIBLE_PRODUCTION_INVENTORY) playbooks/deploy.yml $(ANSIBLE_VAULT_ARGS)

verify: inventory ## Verify the deployed services and API health
	cd $(ANSIBLE_DIRECTORY) && ansible-playbook --inventory $(ANSIBLE_PRODUCTION_INVENTORY) playbooks/verify.yml $(ANSIBLE_VAULT_ARGS)
