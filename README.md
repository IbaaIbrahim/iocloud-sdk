# IOCloud SDKs

Official IOCloud clients for Python, Node.js, and Laravel. Each package is
released independently while sharing the same API contract and authentication
behavior.

| Ecosystem | Package | Source |
| --- | --- | --- |
| PyPI | `iocloud-sdk` | [`packages/python`](packages/python) |
| npm | `@iocloud/sdk` | [`packages/node`](packages/node) |
| Packagist | `iocloud/laravel-sdk` | [`packages/laravel`](packages/laravel) |

## Repository layout

```text
openapi/                Canonical HTTP API contract
contracts/fixtures/     Shared response and error examples
packages/python/        Python 3.10+ SDK
packages/node/          Node.js 20+ TypeScript SDK
packages/laravel/       Laravel 10+ SDK
.github/workflows/      Test and package-publishing automation
```

All clients implement:

- partner and tenant client-credential authentication;
- token caching with a 30-second expiry buffer;
- one automatic token refresh and retry after a `401`;
- tenant creation, external tenant mapping, tenant credentials, and user
  persona updates;
- typed responses and consistent API/authentication exceptions.

## Local checks

```bash
# Python
python -m venv .venv
.venv/bin/python -m pip install -e packages/python
.venv/bin/python -m unittest discover -s packages/python/tests

# Node.js
cd packages/node
npm ci
npm test
npm run build

# Laravel/PHP
cd packages/laravel
composer install
composer test
```

## Releases

Each package has its own GitHub Actions publishing workflow and is released by
pushing an ecosystem-specific tag:

```text
python-v0.2.0   -> PyPI
node-v0.2.0     -> npm
laravel-v0.2.0  -> Packagist-compatible Composer release
```

The Python and npm workflows support trusted publishing. The Laravel workflow
splits its self-contained package into a Composer-compatible repository, which
Packagist can index automatically.

Before creating a tag, update that package's manifest version and changelog.
