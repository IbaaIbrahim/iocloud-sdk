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
examples/laravel-demo/  Runnable partner portal that federates via the SDK
.github/workflows/      Test and package-publishing automation
PUBLISHING.md           One-time registry setup each publish workflow depends on
```

All clients implement:

- partner and tenant client-credential authentication;
- token caching with a 30-second expiry buffer;
- one automatic token refresh and retry after a `401`;
- tenant creation, external tenant mapping, tenant credentials, and user
  persona updates;
- tenant plans and subscriptions: list the plans you offer, put a tenant on
  one, and activate it (see below);
- top-up packages: author the credit bundles you sell your tenants, scope them
  to particular tenant plans, and grant them (see below);
- federation: RSA keypair generation, the JWKS document, subject-token signing,
  identity-provider registration, and the RFC 8693 token exchange;
- typed responses and consistent API/authentication exceptions.

## Putting a tenant on a plan

Your tenants are your clients: they pay *you*, never the platform. So the
platform gives you both halves of the flow and no tenant-facing payment
endpoint at all — you subscribe the tenant and you say when it is paid.

Activation is the step that provisions the balance. It opens the billing window
and writes the child-cap rows the metering layer enforces: the tenant's own cap
from the plan's `credits`, plus one cap per active user from `user_credits_cap`.
Tenants never own a credit pool — they draw on yours, bounded by those caps.

```python
plans = client.list_tenant_plans()

# The common case: you already billed the client, so create and activate at once.
result = client.subscribe_tenant(
    tenant_uuid=tenant.uuid,
    plan_uuid=plans[0].uuid,
    billing_cycle="monthly",
    reference="invoice INV-2026-0042",   # kept in the platform's audit trail
)
result.subscription.is_active        # True
result.provisioned.caps_created      # tenant cap + one per active user
```

Two-step instead, when the plan is requested before it is paid:

```python
pending = client.subscribe_tenant(
    tenant_uuid=tenant.uuid, plan_uuid=plan.uuid, activate_now=False,
)
# ...collect payment in your own billing system...
active = client.activate_tenant_subscription(
    subscription_uuid=pending.subscription.uuid, reference="stripe pi_123",
)
```

Activation is idempotent: calling it on an already-active subscription returns
it unchanged with `provisioned` empty. `list_tenant_subscriptions()` returns
every subscription across your tenants.

The Node and Laravel packages expose the same four calls
(`listTenantPlans`, `subscribeTenant`, `activateTenantSubscription`,
`listTenantSubscriptions`).

## Selling top-ups

A plan is a recurring allowance; a top-up is a one-off bundle of credits on
top of it. You author the packages your tenants can buy, and — same rule as
plans, because your tenants pay *you* — you grant and activate them.

```python
package = client.create_topup_package(
    name="Booster 5K",
    credits=5000,
    price_cents=4900,
    validity_days=90,        # omit for credits that never expire
)

result = client.grant_tenant_topup(
    tenant_uuid=tenant.uuid,
    package_uuid=package.uuid,
    reference="invoice INV-2026-0043",
)
result.purchase.is_active           # True
result.provisioned.pool_credits     # 5000
```

The credits land in a pool the **tenant owns**, spent before your own balance
— unlike a plan, which provisions caps against your pool. `credits` is
snapshotted onto the purchase, so editing the package later never changes what
an existing purchase granted.

### Different offers per plan

Passing `plan_uuids` confines a package to the tenant plans you name, which is
how Bronze and Silver end up with different top-up menus:

```python
plans = {plan.name: plan for plan in client.list_tenant_plans()}

client.create_topup_package(
    name="Bronze Booster", credits=1000, price_cents=900,
    plan_uuids=[plans["Bronze"].uuid],
)
client.create_topup_package(
    name="Silver Booster", credits=10_000, price_cents=6900,
    plan_uuids=[plans["Silver"].uuid],
)
```

A tenant on Bronze is offered the first and cannot see or buy the second — the
platform enforces this on the grant too, not only in the listing. Pass no
`plan_uuids` to offer a package to every tenant, which is what an unscoped
package has always been.

Two-step instead, when the tenant asks before it pays:

```python
pending = client.grant_tenant_topup(
    tenant_uuid=tenant.uuid, package_uuid=package.uuid, activate_now=False,
)
# ...collect payment in your own billing system...
active = client.activate_tenant_topup(
    transaction_uuid=pending.purchase.uuid, reference="stripe pi_456",
)
```

Activation is idempotent: calling it on an already-active purchase returns it
unchanged with `provisioned` empty, so a retry never grants the credits twice.
`list_tenant_topups()` returns every purchase across your tenants, and
`update_topup_package(package_uuid=..., status="inactive")` withdraws a package
from the catalogue without touching what it already sold.

The Node and Laravel packages expose the same six calls
(`listTopupPackages`, `createTopupPackage`, `updateTopupPackage`,
`grantTenantTopup`, `activateTenantTopup`, `listTenantTopups`).

## Federation in one page

A partner that federates its users acts as a small OIDC issuer. The SDK provides
every cryptographic piece, so the integration is configuration plus one call per
login.

```text
generate a keypair        FederationSigningKey.generate()
publish the public half   signing key -> jwks() at <issuer>/.well-known/jwks.json
                          (the Laravel package serves this route for you)
register the issuer once   createIdentityProvider() + mapExternalTenant()
per login                  federatedLogin(subject, externalTenantId, …)
                              -> signs a short-lived JWT with the private key
                              -> exchanges it for an opaque platform session
```

The key id is the RFC 7638 thumbprint of the key, so it is stable across restarts
and **identical across all three SDKs** for the same keypair — a key generated by
one can be served and signed with by another.

Per-language detail lives in each package README; every claim and provider field
is documented in `docs/FEDERATION.md` in the AI-EcoSystem repository.

## Local checks

```bash
# Python
python -m venv .venv
.venv/bin/python -m pip install -e "packages/python[federation]"
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

# Laravel demo (integration tests against the local SDK)
cd examples/laravel-demo
composer install
vendor/bin/phpunit
```

The federation tests in `packages/python` and `packages/node` need the signing
dependencies; the Python suite requires the `federation` extra.

## Releases

Each package has its own GitHub Actions publishing workflow and is released by
pushing an ecosystem-specific tag to this monorepo. Nothing else triggers a
release — the workflows listen on `push: tags` only, never on a branch push.

| Tag             | Workflow              | Destination                                       |
| --------------- | --------------------- | ------------------------------------------------- |
| `python-v0.4.0` | `publish-python.yml`  | PyPI `iocloud-sdk`                                |
| `node-v0.4.0`   | `publish-node.yml`    | npm `@iocloud/sdk`                                |
| `laravel-v0.4.0`| `release-laravel.yml` | `IbaaIbrahim/iocloud-laravel-sdk` -> Packagist     |

The Python and npm workflows support trusted publishing. The Laravel workflow
splits its self-contained package into a Composer-compatible repository, which
Packagist can index automatically.

The one-time registry setup each workflow depends on (PyPI trusted publisher,
`NPM_TOKEN`, the `LARAVEL_SPLIT_TOKEN` secret and the Packagist hook) is in
[PUBLISHING.md](PUBLISHING.md). It is already done for all three; you only
revisit it when a token expires.

### Releasing the Laravel package

Composer derives the version from the Git tag, so `packages/laravel/composer.json`
carries no version field — the tag is the only thing to bump.

```bash
# 1. Land the work on main and move the changelog entry out of "Unreleased".
git switch main && git pull

# 2. Confirm the package tests pass on exactly the commit you are about to tag.
cd packages/laravel && composer validate --strict && composer install && composer test && cd ../..

# 3. Tag that commit and push the tag.
git tag laravel-v0.4.0
git push origin laravel-v0.4.0
```

The workflow then re-runs the package tests, `git subtree split --prefix=packages/laravel`
into `IbaaIbrahim/iocloud-laravel-sdk`, and pushes a bare `v0.4.0` tag there;
Packagist's GitHub hook indexes it within a minute or two. The split is needed
because Packagist expects `composer.json` at the repository root.

Verify with `gh run list --workflow=release-laravel.yml -L 3`, then
`composer show iocloud/laravel-sdk --all | head` from any project.

### Rules that apply to every release

- **The tag decides what ships, not `main`.** The tag pins one commit; work
  committed after it is not in the release. Tag the commit you actually tested.
- **Never move or re-push a tag.** Registries treat a published version as
  immutable and Packagist will keep serving the first thing it indexed. If a
  release is wrong, fix forward with the next patch version.
- **Bump the manifest first for Python and npm.** `pyproject.toml` and
  `package.json` carry hard-coded versions, and a tag that disagrees with the
  manifest publishes the manifest's version. Laravel has no such field.
- **Move the `CHANGELOG.md` entry out of `## <version> - Unreleased`** in the
  same commit you tag, so the tagged tree documents itself.
