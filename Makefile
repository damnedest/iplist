.PHONY: help upgrade keenetic-routes keenetic-youtube keenetic-all ensure-generated awg-fetch awg-all awg-reload awg-update

.DEFAULT_GOAL := help

BRANCH ?= master
UPSTREAM ?= origin
FORK ?= fork
GENERATED_DIR ?= generated
CIDR4_SCRIPT ?= scripts/build-keenetic-routes-from-cidr4.php
AWG_CIDR_SCRIPT ?= scripts/build-cidr4-list.php
AWG_LST ?= $(GENERATED_DIR)/awg-cidr4.lst
AWG_NFT ?= $(GENERATED_DIR)/awg-set.nft
AWG_UPDATE_SCRIPT ?= scripts/awg-update.sh
LIST ?=

help: ## Show this help
	@echo "Usage: make <target>"
	@echo ""
	@echo "Targets:"
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-18s\033[0m %s\n", $$1, $$2}'

upgrade: ## Sync the fork with the upstream branch
	git fetch $(UPSTREAM)
	git checkout $(BRANCH)
	git merge --no-edit $(UPSTREAM)/$(BRANCH)
	git push $(FORK) $(BRANCH)

ensure-generated:
	mkdir -p $(GENERATED_DIR)

keenetic-routes: ensure-generated ## Generate CIDR4 routes for the keenetic set (LIST=<name> to override)
	php $(CIDR4_SCRIPT) --list=$(or $(LIST),keenetic) > $(GENERATED_DIR)/routes-cidr4.bat

keenetic-youtube: ensure-generated ## Generate CIDR4 routes for YouTube
	php $(CIDR4_SCRIPT) --list=$(or $(LIST),youtube) > $(GENERATED_DIR)/youtube-routes-cidr4.bat

keenetic-all: keenetic-routes keenetic-youtube ## Generate CIDR4 routes for both YouTube and non-YouTube

awg-fetch: ## Fetch latest data from the fork remote (not upstream)
	git fetch $(FORK)
	git merge --ff-only $(FORK)/$(BRANCH)

awg-all: ensure-generated ## Generate AWG CIDR list + nftables set (LIST=<name> to override; default awg)
	php $(AWG_CIDR_SCRIPT) --list=$(or $(LIST),awg) --lst=$(AWG_LST) --nft=$(AWG_NFT)

awg-reload: ## Atomically load the nftables set (root; run on Server 1)
	nft -f $(AWG_NFT)

awg-update: ## Full cycle for the timer: fetch -> generate -> diff -> reload+notify if changed
	$(AWG_UPDATE_SCRIPT)
