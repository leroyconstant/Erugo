# Security update — 2026-11-05

This fork requires patched PHP: 8.3.36+, 8.4.27+, or 8.5.12+ within each minor branch. The Docker images use Alpine 3.24 and PHP 8.3.36 or newer from its maintained community repository. PHP 8.3 remains in upstream security support. Both production and development disable native session upload progress as defense in depth for CVE-2026-115560.

The lock file upgrades Laravel 11.42.1 to 12.71.0, Twig 3.20.0 to 3.30.0, Symfony components to 7.4, and the remaining Composer dependencies. This addresses the cited Laravel file-validation bypass, Twig code-injection issue, and known advisories found during dependency resolution. The unused `kovah/laravel-socialite-oidc` dependency was removed because it only supports Laravel <=11; Erugo's OIDC implementation continues to use `jumbojett/openid-connect-php`.

Production installs exclude development dependencies and fail if Composer reports an advisory. The production Compose file pulls `ghcr.io/leroyconstant/erugo:latest`. GitHub Actions publishes AMD64 and ARM64 images only after the PHP tests and Docker startup checks pass on `main`. Each publication also has a source-commit tag `sha-<full commit SHA>`.

## Upgrade an existing Docker installation

1. Back up the **entire existing storage directory**, including hidden files, `app.key`, `jwt.secret`, `.setup-lock`, database files, uploads and templates. Stop the application for a consistent SQLite backup. Keep the old image for rollback.
2. Fetch the security branch (or `main` after the pull request is merged).
3. Point `ERUGO_STORAGE_PATH` to the **same absolute storage path used by your existing container**. Do not use the new default directory when migrating. The entrypoint copies missing skeleton files when `.setup-lock` is absent and preserves existing storage contents.
4. Pull and recreate the container:

```sh
export ERUGO_STORAGE_PATH=/absolute/path/to/existing/storage
export APP_PORT=9998
docker compose -f docker-compose-prod.yml pull
docker compose -f docker-compose-prod.yml up -d --force-recreate
```

Preserve any custom ports, network, environment and proxy configuration from your current deployment. The container automatically runs migrations and seeds; test against a copy of production storage first. Never use `down -v` for this upgrade.

5. Check the runtime and application:

```sh
docker compose -f docker-compose-prod.yml exec app php -v
docker compose -f docker-compose-prod.yml exec app php -r 'var_export(ini_get("session.upload_progress.enabled"));'
docker compose -f docker-compose-prod.yml logs --tail=100 app
```

Expect PHP >=8.3.36 and upload progress disabled. Test login, upload/download including nested folders, email templates, and any configured external identity provider. Real SMTP delivery and external OIDC sign-in require your deployment credentials and are not covered by automated tests. A source-code update cannot patch an already running container; pulling the corrected image and recreating it is required.

## Validation

- `composer validate --strict`
- `composer audit --locked` (production and development packages)
- `vendor/bin/phpunit`: includes JWT login, password-reset email rendering/escaping, quoted Twig macro names, and existing file-security tests.
- `npm ci && npm run build`
- GitHub Actions additionally tests PHP 8.3.36 and 8.4.27, builds the production image, checks its actual runtime/configuration, and boots a disposable application database.

An empty advisory report covers known dependency advisories at the time of the check; it does not certify the absence of vulnerabilities. Keep PHP/Alpine and dependencies updated.

## GHCR access

GitHub creates new GHCR packages as private by default. For anonymous pulls, open the package at https://github.com/users/leroyconstant/packages/container/package/erugo, then Package settings → Change visibility → Public. If keeping it private, sign in on the Docker host with a classic personal access token scoped to `read:packages`, using `docker login ghcr.io -u leroyconstant --password-stdin`. Never place the token in the Compose file.

To build locally instead of using GHCR:

```sh
docker build --pull -f docker/alpine/Dockerfile -t erugo-local:security .
```

## Application security hardening

The second security update restricts all settings reads to administrators, checks account state and the current password/role fingerprint when accepting JWTs, and limits login/verification attempts. Password, role or account-state changes revoke existing access and refresh tokens. Refresh cookies are HttpOnly, SameSite=Lax, Secure by default, and last 24 hours. Email-based SSO account matching and automatic provisioning require an explicitly verified email. Providers that do not attest email verification can still be explicitly linked from an existing authenticated account profile.

Existing sessions must sign in again after this update; old JWTs have no session fingerprint. Previously issued guest invitation links also need to be regenerated. Newly issued invitation links remain valid for their invite period. No database migration or storage reset is required for this token change.

Use HTTPS for the application. For isolated HTTP development only, explicitly set `ERUGO_SECURE_COOKIES=false`; otherwise the browser will correctly refuse to send the secure refresh cookie over HTTP.

Every tus upload request now requires the owner's Bearer token, including HEAD/resume, PATCH and DELETE. Concatenation and deferred-length uploads are refused to prevent cross-owner references and unknown-length quota bypasses. Raw download from tusd is disabled; completed share downloads continue through Laravel. Docker exposes the authenticated hook endpoint only on a loopback listener. A random per-container hook secret is generated at startup; no additional Compose variable is required. Non-Docker installations must configure the same authenticated internal hook and upload authorization gateway before upgrading.

ZIP bundles are checked before extraction: normalized relative paths, no symlinks, bounded entry count, uncompressed size and compression ratio, bounded manifest size and streamed extraction of manifest files only. Sizes are measured from the actual files rather than trusted client metadata. Defaults are 10,000 entries, 4 GiB expanded (also capped by the configured share limit and free disk space), and ratio 1,000. Administrators can adjust `ERUGO_BUNDLE_MAX_FILES`, `ERUGO_BUNDLE_MAX_BYTES` and `ERUGO_BUNDLE_MAX_RATIO` when necessary.

The JavaScript lockfile is updated and the Docker build audits npm dependencies as well as Composer dependencies. CI tests the real Caddy/Laravel/tusd boundary, including cross-user denial, upload resume/deletion and nested ZIP bundles.
