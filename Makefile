app_name := merlin
build_dir := build/artifacts
source_dir := $(build_dir)/$(app_name)
archive := $(build_dir)/$(app_name).tar.gz
cert_dir := $(HOME)/.nextcloud/certificates

.PHONY: dist clean build-js build-source sign

# Builds build/artifacts/merlin.tar.gz, ready for upload to apps.nextcloud.com.
#
# The tarball is assembled from the last commit (HEAD) via `git archive`, not
# from the working tree, so uncommitted changes never leak into a release and
# .git/, node_modules/ etc. are excluded automatically (untracked/gitignored).
# Dev-only *tracked* files (INSTALLATION.md, tools/, vite.config.mjs, ...) are
# stripped via the `export-ignore` entries in .gitattributes instead of a
# second, hand-maintained exclude list here.
dist: clean build-js build-source
	@if ! git diff --quiet HEAD -- . ':(exclude)build'; then \
		echo "warning: uncommitted changes exist - the archive is built from the last commit (HEAD), not the working tree"; \
	fi
	tar -czf $(archive) -C $(build_dir) $(app_name)
	@echo "built $(archive)"

clean:
	rm -rf $(build_dir)

# Compiles src/ to js/ (and css/) via vite, in place in the working tree -
# this is the same build step used during local development.
build-js:
	npm ci
	npm run build

# Assembles the release payload in $(source_dir): tracked source files plus
# the two things that must never be committed but are required at runtime:
# the vite build output (js/) and a --no-dev composer vendor/.
build-source:
	mkdir -p $(build_dir)
	git archive HEAD --worktree-attributes --prefix=$(app_name)/ | tar -x -C $(build_dir)
	cp -r js $(source_dir)/js
	if [ -f composer.lock ]; then cp composer.lock $(source_dir)/composer.lock; fi
	composer install --no-dev --optimize-autoloader --no-interaction --working-dir=$(source_dir)

# Signs the release archive with the app store certificate approved via
# https://github.com/nextcloud/app-certificate-requests, expected at
# ~/.nextcloud/certificates/merlin.key. Paste the printed base64 signature
# into the release form on https://apps.nextcloud.com/developer/apps/releases/new
sign:
	openssl dgst -sha512 -sign $(cert_dir)/$(app_name).key $(archive) | openssl base64
