# Makefile for DutyCheck app release

app_name = dutycheck
build_dir = build
release_dir = $(build_dir)/release
version = $(shell grep '^\s*<version>' appinfo/info.xml | sed 's/.*<version>\([0-9.]*\)<\/version>.*/\1/' | head -1)
archive_name = $(app_name)-$(version).tar.gz
archive_path = $(release_dir)/$(archive_name)
occ = ../../occ
SIGN_KEY := $(if $(strip $(APP_CERT_KEY_PATH)),$(APP_CERT_KEY_PATH),$(HOME)/.nextcloud/certificates/$(app_name).key)
SIGN_CRT := $(if $(strip $(APP_CERT_CRT_PATH)),$(APP_CERT_CRT_PATH),$(HOME)/.nextcloud/certificates/$(app_name).crt)
ready2publish_sign = ../../ready2publish/scripts/sign-nextcloud-appstore-archive.sh

.PHONY: release verify-release verify-signature-manifest sign-release release-signed sign-tarball clean test test-docker mutation mutation-image

PHPUNIT_DOCKER_IMAGE ?= php:8.3-cli
INFECTION_DOCKER_IMAGE ?= dutycheck-infection:php8.3

release:
	@echo "Building $(app_name) v$(version)..."
	@mkdir -p $(release_dir)
	@staging=$$(mktemp -d) && \
		mkdir -p "$$staging/$(app_name)" && \
		rsync -a --exclude='.git' --exclude='$(build_dir)' --exclude='.github' \
			--exclude='node_modules' --exclude='tests' --exclude='.phpunit.result.cache' \
			--exclude='test-results' --exclude='scripts' --exclude='release/*.tar.gz' --exclude='release/*.asc' \
			--exclude='appinfo/signature.json' --exclude='infection.json5' --exclude='infection-security.json5' \
			./ "$$staging/$(app_name)/" && \
		tar -czf $(archive_path) -C "$$staging" $(app_name) && \
		rm -rf "$$staging"
	@echo "Created $(archive_path)"

verify-release:
	@test -f $(archive_path) || (echo "Error: Run 'make release' first"; exit 1)
	@if tar -tzf $(archive_path) | grep -Eq '/(\.git/|node_modules/|build/|tests/|test-results/|scripts/)'; then \
		echo "Error: release archive contains forbidden development paths"; \
		tar -tzf $(archive_path) | grep -E '/(\.git/|node_modules/|build/|tests/|test-results/|scripts/)' || true; \
		exit 1; \
	fi
	@echo "Release archive layout looks clean."

verify-signature-manifest:
	@test -f $(archive_path) || (echo "Error: Run 'make release-signed' first"; exit 1)
	@tmpdir=$$(mktemp -d) && \
		trap 'rm -rf "$$tmpdir"' EXIT && \
		tar -xzf $(archive_path) -C "$$tmpdir" "$(app_name)/appinfo/signature.json" && \
		sig="$$tmpdir/$(app_name)/appinfo/signature.json" && \
		if ! test -f "$$sig"; then \
			echo "Error: signature.json missing from signed archive"; \
			exit 1; \
		fi && \
		if grep -Eq '"([^"]*/)?(\.git|node_modules|build|tests|test-results|scripts)\\/' "$$sig"; then \
			echo "Error: signature.json references forbidden development paths"; \
			grep -E '"([^"]*/)?(\.git|node_modules|build|tests|test-results|scripts)\\/' "$$sig" || true; \
			exit 1; \
		fi
	@echo "Signature manifest sanity check passed."

clean:
	rm -rf $(build_dir)

# Run PHPUnit on the host PHP (requires pdo_sqlite for SQLite integration tests).
test:
	./vendor/bin/phpunit

# Run PHPUnit in Docker (official PHP CLI image includes pdo_sqlite). Use when the host
# PHP build has no SQLite driver (common on minimal CLI installs).
test-docker:
	docker run --rm -v "$$(pwd)":/app -w /app $(PHPUNIT_DOCKER_IMAGE) ./vendor/bin/phpunit

# Build the mutation-testing image (php:8.3-cli + pcov + infection.phar).
mutation-image:
	docker build -t $(INFECTION_DOCKER_IMAGE) tests/Mutation/docker

# Mutation testing (Infection) against the unit suite; config in infection.json5.
# Runs as the invoking user so logs stay writable on the host bind mount.
# Default target is the security-critical subset (infection-security.json5, MSI ≥ 70).
# Pass CONFIG=infection.json5 for the broader informational suite (MSI floor 50).
mutation:
	docker run --rm -u "$$(id -u):$$(id -g)" -v "$$(pwd)":/app -w /app \
		$(INFECTION_DOCKER_IMAGE) infection run \
		--configuration=$(or $(CONFIG),infection-security.json5) \
		--threads=max --only-covered --no-progress

sign-tarball:
	@test -f $(archive_path) || (echo "Error: Run 'make release' first"; exit 1)
	@test -f $(ready2publish_sign) || (echo "Error: Missing $(ready2publish_sign)"; exit 1)
	@APPSTORE_SIGNING_KEY="$(SIGN_KEY)" APPSTORE_SIGNING_CERT="$(SIGN_CRT)" bash "$(ready2publish_sign)" $(app_name) $(archive_path)

sign-release: verify-release
	@test -f "$(SIGN_KEY)" || (echo "Error: Missing signing key: $(SIGN_KEY) (set APP_CERT_KEY_PATH or install under ~/.nextcloud/certificates/ — see https://github.com/nextcloud/app-certificate-requests)"; exit 1)
	@test -f "$(SIGN_CRT)" || (echo "Error: Missing certificate: $(SIGN_CRT) (set APP_CERT_CRT_PATH or store at ~/.nextcloud/certificates/$(app_name).crt)"; exit 1)
	@test -f $(occ) || (echo "Error: occ not found at $(occ). Override with 'make sign-release occ=/path/to/occ'"; exit 1)
	@staging=$$(mktemp -d) && \
		trap 'rm -rf "$$staging"' EXIT && \
		tar -xzf $(archive_path) -C "$$staging" && \
		php $(occ) integrity:sign-app \
			--privateKey="$(SIGN_KEY)" \
			--certificate="$(SIGN_CRT)" \
			--path="$$staging/$(app_name)" && \
		tar -czf $(archive_path) -C "$$staging" $(app_name)
	@echo "Signed archive updated at $(archive_path)"

release-signed: release sign-release verify-signature-manifest
	@echo "Release build + Nextcloud signature complete."
