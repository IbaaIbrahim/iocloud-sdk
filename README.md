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
- tenant creation (with the external id federated logins reach the tenant
  by), tenant credentials, tenant users (created and activated with a tenant
  credential), and user persona updates;
- tenant plans and subscriptions: list the plans you offer, put a tenant on
  one, and activate it (see below);
- top-up packages: author the credit bundles you sell your tenants, scope them
  to particular tenant plans, and grant them (see below);
- federation: RSA keypair generation, the JWKS document, subject-token signing,
  identity-provider registration, and the RFC 8693 token exchange, including
  tenants created at their first login (see below);
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

Each plan also carries `plan_code`, your own code for it, set in the Admin
Dashboard: unique among your tenant plans, matched exactly, and `None` for a
plan without one. A login that creates its tenant can name a plan by it, and
the tenant is created on that plan (see
[Creating a tenant at its first login](#creating-a-tenant-at-its-first-login)).

The Node and Laravel packages expose the same four calls
(`listTenantPlans`, `subscribeTenant`, `activateTenantSubscription`,
`listTenantSubscriptions`), and the code as `planCode`.

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
register the issuer once   createIdentityProvider(applicationUuid, …)
name each tenant           createTenant(…, externalId) or setTenantExternalId()
                           (or let its first login create it: see below)
per login                  federatedLogin(subject, externalTenantId, …)
                              -> signs a short-lived JWT with the private key
                              -> returns it to your endpoint, for the frontend
in the frontend            the chat client fetches the JWT through its callback
                              -> exchanges it at /v1/federation/token for an
                                 opaque platform session
```

`federatedLogin` sends nothing: the platform session never passes through your
backend. A backend that wants the session itself passes the token to
`exchangeSubjectToken`.

The key id is the RFC 7638 thumbprint of the key, so it is stable across restarts
and **identical across all three SDKs** for the same keypair — a key generated by
one can be served and signed with by another.

Every identity provider belongs to one of your applications, and a token it signs
logs users into that application only. The token's tenant claim resolves to the
tenant of that application with that external id, and its user claim to the user
of that tenant with that external id — never by email. An application may have
several providers, so replacing an issuer loses no tenant and no user; but any of
them logs in any of the application's users, so they must all sign the same
tenant and user ids.

Per-language detail lives in each package README; every claim and provider field
is documented in `docs/FEDERATION.md` in the AI-EcoSystem repository.

### With just-in-time provisioning off

With `allow_jit_users` on, the first login creates the user. With it off, a login
reaches only a user that already exists, so create each one with the `sub` your
tokens will carry as its external id, and activate it — users created this way
start `pending`. The user calls are tenant-scoped, so they take a tenant
credential rather than the partner token:

```python
provider = client.create_identity_provider(
    application_uuid=application_uuid,
    name="Acme Portal",
    issuer=token_issuer.issuer,
    allowed_audiences=[token_issuer.audience],
    allow_jit_users=False,
    claim_names=token_issuer.claim_names,
)

tenant = client.create_tenant(
    application_uuid=application_uuid,
    name="Acme Ltd",
    contact_email="ops@acme.example",     # optional
    external_id="acme-tenant-1",          # what your tokens carry as tenant_id
)
# The secret is returned once, at creation: persist it for later user calls.
credential = client.create_tenant_credentials(tenant_uuid=tenant.uuid)

user = client.create_user(
    name="Dana Okafor",
    email="dana.okafor@acme.example",
    external_id="acme-user-1001",         # what your tokens carry as sub
    tenant_client_id=credential.client_id,
    tenant_client_secret=credential.client_secret,
)
client.update_user_status(
    user_uuid=user.uuid,
    status="active",
    tenant_client_id=credential.client_id,
    tenant_client_secret=credential.client_secret,
)

subject_token = client.federated_login(
    subject="acme-user-1001", external_tenant_id="acme-tenant-1"
)
```

The platform generates each tenant's slug from its name and returns it on the
tenant (`tenant.slug`, as in `acme-ltd-5d3c1a7e`), so `create_tenant` takes none.

The Node and Laravel packages expose the same calls (`createUser`,
`updateUserStatus`, `setTenantExternalId`, and `externalId` on `createTenant`).

### Creating a tenant at its first login

Instead of creating each tenant before anyone logs into it, register the
provider with `allow_jit_tenants` and pass the tenant's details to each login
as a `TenantProfile` — the external id, name and optional contact email
`create_tenant` takes, plus an optional plan code, which `create_tenant` does
not take. The SDK sends the external id as the token's tenant claim and signs
the rest into it as its `tenant_profile` claim. When no tenant of
the provider's application has the token's tenant claim as its external id,
the platform creates that tenant, active, with the claim as its external id,
then creates the user just in time:

```python
from iocloud_sdk import TenantProfile

client.create_identity_provider(
    application_uuid=application_uuid,
    name="Acme Portal",
    issuer=token_issuer.issuer,
    allowed_audiences=[token_issuer.audience],
    allow_jit_users=True,                 # allow_jit_tenants requires it
    allow_jit_tenants=True,
    claim_names=token_issuer.claim_names,
)

subject_token = client.federated_login(
    subject="acme-user-1001",
    email="dana.okafor@acme.example",     # its user is created just in time
    tenant=TenantProfile(
        external_tenant_id="acme-tenant-1",  # the tenant claim: the new tenant's external id
        name="Acme Ltd",
        contact_email="ops@acme.example",
    ),
)
```

A tenant created without a plan has none, so it draws on your credits uncapped
until you subscribe it with `subscribe_tenant`. The exchange that creates it
answers `tenant_created: true`, but your chat client receives that answer, not
your backend. So name one of your tenant plans by its `plan_code` instead, and
the tenant is created on that plan, with nothing to subscribe afterwards:

```python
subject_token = client.federated_login(
    subject="acme-user-1001",
    email="dana.okafor@acme.example",
    tenant=TenantProfile(
        external_tenant_id="acme-tenant-1", name="Acme Ltd", plan_code="growth"
    ),
)
```

- `allow_jit_tenants` requires `allow_jit_users`, because the users of a tenant
  created at login can only be created at login: the platform refuses one
  without the other with a `422`.
- The profile's `external_tenant_id` is the login's tenant claim, sent once
  and never inside `tenant_profile`, so `federated_login` needs no
  `external_tenant_id` of its own. Pass both and they must be the same id: the
  SDK refuses a mismatch before signing, since the platform finds and creates
  the tenant by the claim alone.
- With a `plan_code`, the tenant is created subscribed to your plan with that
  code, on a monthly billing cycle and active at once, as `subscribe_tenant`
  leaves it by default, with its cap and the cap of the login's user
  provisioned from the plan. That happens in the same transaction as the
  tenant, so the tenant exists on its plan or not at all. A code none of your
  plans has refuses the login with `invalid_target` ("The token's tenant plan
  does not exist.") and creates nothing.
- The profile creates a tenant and never updates one. Once the tenant exists
  the profile is ignored, so passing it on every login is harmless, and its
  `plan_code` never changes an existing tenant's plan.
- The exchange answers `tenant_uuid`, the tenant the session belongs to, and
  `tenant_created`, true only for the login that created it;
  `exchange_subject_token` reads them as `session.tenant_uuid` and
  `session.tenant_created`. A platform that predates them sends neither:
  `None` and `False`.

The Node and Laravel packages expose the same: `TenantProfile` (with
`planCode` and `externalTenantId`), `tenant` on `federatedLogin`, whose own
`externalTenantId` is then optional, `allowJitTenants` on
`createIdentityProvider`, and `tenantUuid` / `tenantCreated` on
`FederatedSession`.

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

| Tag pattern  | Workflow              | Destination                                    |
| ------------ | --------------------- | ---------------------------------------------- |
| `python-v*`  | `publish-python.yml`  | PyPI `iocloud-sdk`                             |
| `node-v*`    | `publish-node.yml`    | npm `@iocloud/sdk`                             |
| `laravel-v*` | `release-laravel.yml` | `IbaaIbrahim/iocloud-laravel-sdk` -> Packagist |

The Python and npm workflows support trusted publishing. The Laravel workflow
splits its self-contained package into a Composer-compatible repository, which
Packagist can index automatically.

Only the Laravel package has ever been released, so its credentials are proven
and the other two are not. Treat the PyPI and npm halves of
[PUBLISHING.md](PUBLISHING.md) as unverified — a first publish is where a
missing trusted publisher or a wrong environment name surfaces.

| Package               | Published on the registry | Setup state                                            |
| --------------------- | ------------------------- | ------------------------------------------------------ |
| `iocloud/laravel-sdk` | v0.3.0 through v0.6.0     | confirmed: `LARAVEL_SPLIT_TOKEN` + Packagist hook work  |
| `iocloud-sdk` (PyPI)  | never                     | unverified: needs the trusted publisher + `pypi` env    |
| `@iocloud/sdk` (npm)  | never                     | unverified: needs `NPM_TOKEN` + `npm` env               |

The three do not share a version counter. Laravel is published through 0.6.0, so
its next release is 0.7.0. Python and npm were never tagged at any version,
and their manifests now read 0.7.0, so `python-v0.7.0` and `node-v0.7.0` are
their next tags; an earlier version of either could now only be cut from a
commit before that bump. The snippets below write `0.7.0` throughout —
substitute the version that package is actually going to.

### Releasing the Laravel package

Composer derives the version from the Git tag, so `packages/laravel/composer.json`
carries no version field — the tag is the only thing to bump.

```bash
# 1. Land the work on main and move the changelog entry out of "Unreleased".
git switch main && git pull

# 2. Confirm the package tests pass on exactly the commit you are about to tag.
cd packages/laravel && composer validate --strict && composer install && composer test && cd ../..

# 3. Tag that commit and push the tag.
git tag laravel-v0.7.0
git push origin laravel-v0.7.0
```

The workflow then re-runs the package tests, `git subtree split --prefix=packages/laravel`
into `IbaaIbrahim/iocloud-laravel-sdk`, and pushes a bare `v0.7.0` tag there;
Packagist's GitHub hook indexes it within a minute or two. The split is needed
because Packagist expects `composer.json` at the repository root.

Verify the run, then ask Packagist directly — this needs no project that already
requires the package:

```bash
gh run list --workflow=release-laravel.yml -L 3
curl -s https://repo.packagist.org/p2/iocloud/laravel-sdk.json \
  | python -c "import json,sys; p=json.load(sys.stdin)['packages']['iocloud/laravel-sdk']; print([v['version'] for v in p])"
```

### Releasing the Python package

`pyproject.toml` carries the version and the workflow never compares it with the
tag, so the **manifest** decides what is published: a `python-v0.7.0` tag on a
manifest still reading 0.6.0 publishes 0.6.0, and PyPI rejects a version it
already holds as a duplicate.

```bash
# 1. Bump packages/python/pyproject.toml -> version = "0.7.0", and commit it.

# 2. Run the suite yourself. publish-python.yml has no test step — it builds and
#    uploads — so nothing else gates this release.
python -m unittest discover -s packages/python/tests   # after the editable install above

# 3. Tag the commit you just tested and push.
git push origin main
git tag python-v0.7.0 && git push origin python-v0.7.0
```

`publish-python.yml` builds an sdist and a wheel, then uploads with
`pypa/gh-action-pypi-publish` from the `pypi` environment via trusted
publishing, so no token is stored in the repository.

### Releasing the npm package

Use `npm version` rather than editing `package.json`: it updates the lockfile in
the same step. Hand-editing leaves `package-lock.json` behind, which is how it
sat at 0.2.0 through two manifest bumps before anyone noticed.

```bash
cd packages/node
npm version 0.7.0 --no-git-tag-version    # package.json AND package-lock.json
npm test                                  # builds first, then runs the listed suites
cd ../..
git commit -am "chore: release node 0.7.0" && git push origin main
git tag node-v0.7.0 && git push origin node-v0.7.0
```

`publish-node.yml` has no test step either, but `npm publish` triggers the
package's own `prepublishOnly` hook (`npm test && npm run build`), so the suite
does gate the upload. One catch: the `test` script **lists its test files
explicitly** instead of globbing, so a new test file that nobody added to it
never runs — locally or in CI.

### Rules that apply to every release

- **The tag decides what ships, not `main`.** The tag pins one commit; work
  committed after it is not in the release. Tag the commit you actually tested.
- **Never move a tag once the publish step has run.** Registries treat a
  published version as immutable, and Packagist keeps serving the first thing it
  indexed. If a *published* release is wrong, fix forward with the next patch
  version. Before anything is published — a run that died in setup, tests, or
  the split — the version does not exist yet anywhere, so re-tagging is the
  correct fix rather than burning a version number.
- **A workflow fix needs a new tag, not a re-run.** A tag-triggered run reads
  the workflow file from the tagged commit, so editing `release-laravel.yml` on
  `main` has no effect on `gh run rerun` of an existing tag. Commit the fix,
  then move the tag onto it (subject to the rule above).
- **Distinguish a transient failure from a real one before re-tagging.** A
  `429`/`503` from `codeload.github.com` is GitHub rate-limiting the runner, not
  a broken release; `gh run rerun <id> --failed` is the right response. If it
  recurs every run, the job is missing `COMPOSER_AUTH` — see the comment in
  `release-laravel.yml`.
- **Bump the manifest first for Python and npm.** `pyproject.toml` and
  `package.json` carry hard-coded versions, and a tag that disagrees with the
  manifest publishes the manifest's version. Laravel has no such field.
- **Move the `CHANGELOG.md` entry out of `## <version> - Unreleased`** in the
  same commit you tag, so the tagged tree documents itself.
