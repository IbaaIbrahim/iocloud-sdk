<?php

namespace IOCloud\Laravel\Tests;

use Illuminate\Support\Facades\Http;
use IOCloud\Laravel\IOCloudClient;

final class ClientTopupsTest extends TestCase
{
    private const BRONZE_PLAN_UUID = '3f1b1f70-0000-4000-8000-0000000000b1';
    private const PACKAGE_UUID = '3f1b1f70-0000-4000-8000-0000000000c1';
    private const TENANT_UUID = '3f1b1f70-0000-4000-8000-0000000000d1';
    private const PURCHASE_UUID = '3f1b1f70-0000-4000-8000-0000000000e1';

    /** @return array<string, mixed> */
    private function packagePayload(array $overrides = []): array
    {
        return array_merge([
            'uuid' => self::PACKAGE_UUID,
            'name' => 'Booster 5K',
            'credits' => 5000,
            'price_cents' => 4900,
            'validity_days' => 90,
            'definer_type' => 'partners',
            'audience' => 'tenants',
            'status' => 'active',
            'created_at' => '2026-08-17T00:00:00Z',
            'updated_at' => '2026-08-17T00:00:00Z',
            'plans' => [[
                'plan_type' => 'tenant_plans',
                'plan_uuid' => self::BRONZE_PLAN_UUID,
                'plan_name' => 'Bronze',
            ]],
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function purchasePayload(array $overrides = []): array
    {
        return array_merge([
            'uuid' => self::PURCHASE_UUID,
            'package_uuid' => self::PACKAGE_UUID,
            'package_name' => 'Booster 5K',
            'tenant_uuid' => self::TENANT_UUID,
            'tenant_name' => 'Acme Ltd',
            'credits' => 5000,
            'valid_from' => '2026-08-17T00:00:00+00:00',
            'valid_to' => '2026-11-15T00:00:00+00:00',
            'status' => 'active',
            'payment_transaction_uuid' => null,
            'created_at' => '2026-08-17T00:00:00Z',
        ], $overrides);
    }

    private function fakePartnerToken(): array
    {
        return [
            'api.example.com/v1/partner/auth/token' => Http::response([
                'data' => [
                    'token' => [
                        'access_token' => 'partner-token',
                        'token_type' => 'Bearer',
                        'expires_at' => '2099-01-01T00:00:00Z',
                    ],
                ],
            ]),
        ];
    }

    public function test_it_lists_topup_packages(): void
    {
        Http::fake($this->fakePartnerToken() + [
            'api.example.com/v1/partner/topup-packages*' => Http::response([
                'data' => [
                    'list' => [$this->packagePayload()],
                    'pagination' => [
                        'page' => 1, 'total_pages' => 1, 'limit' => 25, 'total' => 1,
                    ],
                ],
            ]),
        ]);

        $packages = $this->app->make(IOCloudClient::class)->listTopupPackages();

        $this->assertCount(1, $packages);
        $this->assertSame('Booster 5K', $packages[0]->name);
        $this->assertSame(5000, $packages[0]->credits);
        $this->assertSame(90, $packages[0]->validityDays);
        $this->assertTrue($packages[0]->isActive());
        $this->assertCount(1, $packages[0]->plans);
        $this->assertSame('Bronze', $packages[0]->plans[0]->planName);
        $this->assertFalse($packages[0]->isOfferedToEveryPlan());
    }

    public function test_a_package_without_plans_is_offered_to_every_plan(): void
    {
        Http::fake($this->fakePartnerToken() + [
            'api.example.com/v1/partner/topup-packages*' => Http::response([
                'data' => ['list' => [$this->packagePayload(['plans' => []])]],
            ]),
        ]);

        $packages = $this->app->make(IOCloudClient::class)->listTopupPackages();

        $this->assertTrue($packages[0]->isOfferedToEveryPlan());
    }

    public function test_a_package_without_expiry_reports_null_validity(): void
    {
        Http::fake($this->fakePartnerToken() + [
            'api.example.com/v1/partner/topup-packages*' => Http::response([
                'data' => ['list' => [$this->packagePayload(['validity_days' => null])]],
            ]),
        ]);

        $packages = $this->app->make(IOCloudClient::class)->listTopupPackages();

        $this->assertNull($packages[0]->validityDays);
    }

    public function test_it_creates_a_package_scoped_to_tenant_plans(): void
    {
        Http::fake($this->fakePartnerToken() + [
            'api.example.com/v1/partner/topup-packages' => Http::response([
                'data' => ['package' => $this->packagePayload()],
            ], 201),
        ]);

        $package = $this->app->make(IOCloudClient::class)->createTopupPackage(
            name: 'Booster 5K',
            credits: 5000,
            priceCents: 4900,
            validityDays: 90,
            planUuids: [self::BRONZE_PLAN_UUID],
        );

        $this->assertSame(self::PACKAGE_UUID, $package->uuid);

        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/v1/partner/topup-packages')) {
                return false;
            }

            return $request['name'] === 'Booster 5K'
                && $request['credits'] === 5000
                && $request['price_cents'] === 4900
                && $request['validity_days'] === 90
                && $request['plan_uuids'] === [self::BRONZE_PLAN_UUID];
        });
    }

    public function test_creating_without_plans_sends_an_empty_list(): void
    {
        Http::fake($this->fakePartnerToken() + [
            'api.example.com/v1/partner/topup-packages' => Http::response([
                'data' => ['package' => $this->packagePayload()],
            ], 201),
        ]);

        $this->app->make(IOCloudClient::class)->createTopupPackage(
            name: 'Any',
            credits: 10,
            priceCents: 0,
        );

        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/v1/partner/topup-packages')) {
                return false;
            }

            // An empty list is the "every tenant" case, not an omission.
            return $request['plan_uuids'] === []
                && $request['validity_days'] === null;
        });
    }

    public function test_clearing_the_scoping_sends_plan_uuids(): void
    {
        $uuid = self::PACKAGE_UUID;
        Http::fake($this->fakePartnerToken() + [
            "api.example.com/v1/partner/topup-packages/{$uuid}" => Http::response([
                'data' => ['package' => $this->packagePayload(['plans' => []])],
            ]),
        ]);

        $this->app->make(IOCloudClient::class)
            ->updateTopupPackage(packageUuid: $uuid, planUuids: []);

        Http::assertSent(function ($request) use ($uuid) {
            if (! str_ends_with($request->url(), "/topup-packages/{$uuid}")) {
                return false;
            }

            return $request->data() === ['plan_uuids' => []];
        });
    }

    public function test_updating_status_alone_leaves_the_scoping_untouched(): void
    {
        $uuid = self::PACKAGE_UUID;
        Http::fake($this->fakePartnerToken() + [
            "api.example.com/v1/partner/topup-packages/{$uuid}" => Http::response([
                'data' => ['package' => $this->packagePayload(['status' => 'inactive'])],
            ]),
        ]);

        $package = $this->app->make(IOCloudClient::class)
            ->updateTopupPackage(packageUuid: $uuid, status: 'inactive');

        $this->assertFalse($package->isActive());

        Http::assertSent(function ($request) use ($uuid) {
            if (! str_ends_with($request->url(), "/topup-packages/{$uuid}")) {
                return false;
            }

            return $request->data() === ['status' => 'inactive'];
        });
    }

    public function test_it_grants_a_tenant_topup_and_activates_by_default(): void
    {
        Http::fake($this->fakePartnerToken() + [
            'api.example.com/v1/partner/topups/tenant/purchases' => Http::response([
                'data' => [
                    'purchase' => $this->purchasePayload(),
                    'provisioning' => ['pool_created' => true, 'pool_credits' => 5000],
                ],
            ], 201),
        ]);

        $result = $this->app->make(IOCloudClient::class)->grantTenantTopup(
            tenantUuid: self::TENANT_UUID,
            packageUuid: self::PACKAGE_UUID,
            reference: 'invoice INV-2026-0042',
        );

        $this->assertTrue($result->purchase->isActive());
        $this->assertSame('Acme Ltd', $result->purchase->tenantName);
        // Unlike a plan, a top-up gives the tenant a pool of its own.
        $this->assertTrue($result->provisioned->poolCreated);
        $this->assertSame(5000, $result->provisioned->poolCredits);

        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/v1/partner/topups/tenant/purchases')) {
                return false;
            }

            return $request['activate_now'] === true
                && $request['reference'] === 'invoice INV-2026-0042';
        });
    }

    public function test_a_pending_grant_provisions_nothing(): void
    {
        Http::fake($this->fakePartnerToken() + [
            'api.example.com/v1/partner/topups/tenant/purchases' => Http::response([
                'data' => [
                    'purchase' => $this->purchasePayload(['status' => 'pending']),
                    'provisioning' => null,
                ],
            ], 201),
        ]);

        $result = $this->app->make(IOCloudClient::class)->grantTenantTopup(
            tenantUuid: self::TENANT_UUID,
            packageUuid: self::PACKAGE_UUID,
            activateNow: false,
        );

        $this->assertFalse($result->purchase->isActive());
        $this->assertNull($result->provisioned);
    }

    public function test_activating_an_already_active_topup_provisions_nothing(): void
    {
        $uuid = self::PURCHASE_UUID;
        Http::fake($this->fakePartnerToken() + [
            "api.example.com/v1/partner/topups/tenant/purchases/{$uuid}/activate" =>
                Http::response([
                    'data' => [
                        'purchase' => $this->purchasePayload(),
                        'provisioning' => null,
                    ],
                ]),
        ]);

        $result = $this->app->make(IOCloudClient::class)
            ->activateTenantTopup($uuid, 'bank transfer');

        $this->assertTrue($result->purchase->isActive());
        $this->assertNull($result->provisioned);
    }

    public function test_it_lists_tenant_topups(): void
    {
        Http::fake($this->fakePartnerToken() + [
            'api.example.com/v1/partner/topups/tenant/purchases' => Http::response([
                'data' => ['purchases' => [$this->purchasePayload()]],
            ]),
        ]);

        $purchases = $this->app->make(IOCloudClient::class)->listTenantTopups();

        $this->assertCount(1, $purchases);
        $this->assertSame(self::TENANT_UUID, $purchases[0]->tenantUuid);
        $this->assertSame(5000, $purchases[0]->credits);
        $this->assertNotNull($purchases[0]->validTo);
    }

    public function test_a_purchase_that_never_expires_has_no_valid_to(): void
    {
        Http::fake($this->fakePartnerToken() + [
            'api.example.com/v1/partner/topups/tenant/purchases' => Http::response([
                'data' => ['purchases' => [$this->purchasePayload(['valid_to' => null])]],
            ]),
        ]);

        $purchases = $this->app->make(IOCloudClient::class)->listTenantTopups();

        $this->assertNull($purchases[0]->validTo);
    }
}
