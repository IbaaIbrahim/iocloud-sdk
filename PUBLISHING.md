# Publishing the SDKs

The repository prepares releases but intentionally does not store registry
credentials. Complete each registry's one-time setup before pushing a release
tag.

The manifests currently mark the SDKs as proprietary/UNLICENSED. Replace those
values and add the corresponding license file before publishing if these SDKs
should be open source.

## PyPI

1. Create or claim the `iocloud-sdk` project on PyPI.
2. Configure a PyPI trusted publisher for this GitHub repository, workflow
   `publish-python.yml`, and environment `pypi`.
3. Set the version in `packages/python/pyproject.toml`.
4. Push a matching tag such as `python-v0.2.0`.

## npm

1. Create the `iocloud` npm organization or replace `@iocloud/sdk` in
   `packages/node/package.json` with a scope you control.
2. For the first release, add a granular `NPM_TOKEN` repository secret with
   publish access. After the package exists, configure npm trusted publishing
   for workflow `publish-node.yml` and environment `npm`; the workflow then no
   longer needs a long-lived token.
3. Set the version with `npm version 0.2.0 --no-git-tag-version` from
   `packages/node`.
4. Commit the manifest and lockfile, then push `node-v0.2.0`.

## Composer / Packagist

Composer derives the package version from Git tags, so `composer.json` has no
hard-coded version.

1. Create an empty `IbaaIbrahim/iocloud-laravel-sdk` GitHub repository.
2. Add a fine-grained `LARAVEL_SPLIT_TOKEN` Actions secret with contents write
   access to that repository.
3. Submit the split repository to Packagist as `iocloud/laravel-sdk` and enable
   the Packagist GitHub hook.
4. Push a tag such as `laravel-v0.2.0` to this monorepo.

The release workflow tests the package, splits `packages/laravel` into the
dedicated repository, and pushes a Composer-compatible `v0.2.0` tag. Packagist
then indexes that tag. The split is necessary because Packagist expects
`composer.json` at the repository root.

## Pre-release verification

Run all package tests and confirm that the tag version matches the package
manifest. Never publish the same version twice; registries treat released
artifacts as immutable.
