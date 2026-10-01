# Publishing the SDKs

This file covers the **one-time setup per registry**. For the per-release
procedure — which tag to push, which manifest to bump first, how to recover a
failed release — see [Releases](README.md#releases) in the root README.

The repository prepares releases but intentionally does not store registry
credentials, other than the two secrets named below. Complete each registry's
one-time setup before pushing a release tag.

Status as of 0.6.0 (2026-10-01): **Composer is done and proven** —
`LARAVEL_SPLIT_TOKEN` and the Packagist hook have published v0.3.0 through
v0.6.0. **PyPI and npm have never run.** Their steps below may already be complete or may not be; the first tag is
what will tell you, so push it when you can watch the run rather than as the
last act of a working day.

The manifests currently mark the SDKs as proprietary/UNLICENSED. Replace those
values and add the corresponding license file before publishing if these SDKs
should be open source.

## PyPI

1. Create or claim the `iocloud-sdk` project on PyPI.
2. Configure a PyPI trusted publisher for this GitHub repository, workflow
   `publish-python.yml`, and environment `pypi`.
3. Set the version in `packages/python/pyproject.toml`.
4. Push a matching tag such as `python-v0.4.0`.

The environment name in the trusted-publisher configuration must be exactly
`pypi`, because that is what `publish-python.yml` declares; a mismatch fails at
the upload step with a permissions error rather than at checkout.

`publish-python.yml` runs **no tests** — it builds and uploads. Run the suite
before tagging; nothing downstream will catch a regression.

## npm

1. Create the `iocloud` npm organization or replace `@iocloud/sdk` in
   `packages/node/package.json` with a scope you control.
2. For the first release, add a granular `NPM_TOKEN` repository secret with
   publish access. After the package exists, configure npm trusted publishing
   for workflow `publish-node.yml` and environment `npm`; the workflow then no
   longer needs a long-lived token.
3. Set the version with `npm version 0.4.0 --no-git-tag-version` from
   `packages/node`. Use the command rather than editing `package.json`: it bumps
   `package-lock.json` too, which the workflow's `npm ci` requires to agree with
   the manifest.
4. Commit the manifest and lockfile, then push `node-v0.4.0`.

`publish-node.yml` runs no explicit test step, but `npm publish` fires the
package's `prepublishOnly` hook (`npm test && npm run build`), so the suite gates
the upload.

## Composer / Packagist

Composer derives the package version from Git tags, so `composer.json` has no
hard-coded version.

1. Create an empty `IbaaIbrahim/iocloud-laravel-sdk` GitHub repository.
2. Add a fine-grained `LARAVEL_SPLIT_TOKEN` Actions secret with contents write
   access to that repository.
3. Submit the split repository to Packagist as `iocloud/laravel-sdk` and enable
   the Packagist GitHub hook.
4. Push a tag such as `laravel-v0.4.0` to this monorepo.

The release workflow tests the package, splits `packages/laravel` into the
dedicated repository, and pushes a Composer-compatible `v0.4.0` tag. Packagist
then indexes that tag. The split is necessary because Packagist expects
`composer.json` at the repository root.

The `LARAVEL_SPLIT_TOKEN` is the only credential here that expires. When it does,
the run fails at the split step with a 403 that the workflow translates into a
message naming the likely cause — read that annotation before re-issuing
anything.

## Pre-release verification

Run all package tests and confirm that the tag version matches the package
manifest. Never publish the same version twice; registries treat released
artifacts as immutable.

Both PHP workflows pass the job's `GITHUB_TOKEN` to Composer via `COMPOSER_AUTH`.
Do not remove it: without authentication, `composer install --prefer-dist` draws
on a 60-request-per-hour anonymous quota that shared runner IPs arrive with
already spent, and the install then fails on an arbitrary dependency with
`could not be downloaded (HTTP/2 429)`. Because the failing package differs every
run, it reads as a flaky dependency rather than as a rate limit.
