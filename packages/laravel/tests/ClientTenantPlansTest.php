<?php

namespace IOCloud\Laravel\Tests;

use Illuminate\Support\Facades\Http;
use IOCloud\Laravel\IOCloudClient;

final class ClientTenantPlansTest extends TestCase
{
    /** @return array<string, mixed> */
    private function subscriptionPayload(): array
    {
        return [
            'uuid' => '3f1b1f70-0000-4000-8000-000000000001',
            'status' => 'paid',
            'plan_type' => 'tenant_plans',
            'billing_cycle' => 'monthly',
            'subscribed_from' => '2026-08-04T00:00:00+00:00',
            'subscribed_to' => '2026-09-03T00:00:00+00:00',
            'payment_transaction_uuid' => null,
            'created_at' => '2026-08-04T00:00:00Z',
        ];
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

    public function test_it_lists_tenant_plans(): void
    {
        Http::fake($this->fakePartnerToken() + [
            'api.example.com/v1/partner/plans/tenant*' => Http::response([
                'data' => [
                    'list' => [[
                        'uuid' => '3f1b1f70-0000-4000-8000-00000000000a',
                        'name' => 'Growth',
                        'monthly_price_cents' => 1900,
                        'yearly_price_cents' => 19000,
                        'tpm' => 100,
                        'rpm' => 20,
                        'credits' => 2000,
                        'user_credits_cap' => 500,
                        'user_tpm' => 10,
                        'user_rpm' => 5,
                    ]],
                    'pagination' => [
                        'page' => 1, 'total_pages' => 1, 'limit' => 25, 'total' => 1,
                    ],
                ],
            ]),
        ]);

        $plans = $this->app->make(IOCloudClient::class)->listTenantPlans();

        $this->assertCount(1, $plans);
        $this->assertSame('Growth', $plans[0]->name);
        $this->assertSame(2000, $plans[0]->credits);
        $this->assertSame(500, $plans[0]->userCreditsCap);
    }

    public function test_it_subscribes_a_tenant_and_activates_by_default(): void
    {
        Http::fake($this->fakePartnerToken() + [
            'api.example.com/v1/partner/plans/tenant/subscriptions' => Http::response([
                'data' => [
                    'subscription' => $this->subscriptionPayload(),
                    'provisioning' => [
                        'pool_created' => false,
                        'pool_credits' => 0,
                        'caps_created' => [
                            ['child' => 'tenant', 'id' => 7, 'cap' => 2000],
                            ['child' => 'user', 'id' => 11, 'cap' => 500],
                        ],
                    ],
                ],
            ], 201),
        ]);

        $result = $this->app->make(IOCloudClient::class)->subscribeTenant(
            tenantUuid: '3f1b1f70-0000-4000-8000-0000000000ff',
            planUuid: '3f1b1f70-0000-4000-8000-00000000000a',
            reference: 'invoice INV-1',
        );

        $this->assertTrue($result->subscription->isActive());
        $this->assertNotNull($result->subscription->subscribedTo);
        // Tenants get caps, never a pool of their own.
        $this->assertFalse($result->provisioned->poolCreated);
        $this->assertCount(2, $result->provisioned->capsCreated);
        $this->assertSame(2000, $result->provisioned->capsCreated[0]['cap']);

        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/v1/partner/plans/tenant/subscriptions')) {
                return false;
            }

            return $request['activate_now'] === true
                && $request['billing_cycle'] === 'monthly'
                && $request['reference'] === 'invoice INV-1';
        });
    }

    public function test_a_pending_subscription_has_no_window_or_provisioning(): void
    {
        Http::fake($this->fakePartnerToken() + [
            'api.example.com/v1/partner/plans/tenant/subscriptions' => Http::response([
                'data' => [
                    'subscription' => array_merge($this->subscriptionPayload(), [
                        'status' => 'pending_payment',
                        'subscribed_from' => null,
                        'subscribed_to' => null,
                    ]),
                    'provisioning' => null,
                ],
            ], 201),
        ]);

        $result = $this->app->make(IOCloudClient::class)->subscribeTenant(
            tenantUuid: '3f1b1f70-0000-4000-8000-0000000000ff',
            planUuid: '3f1b1f70-0000-4000-8000-00000000000a',
            activateNow: false,
        );

        $this->assertFalse($result->subscription->isActive());
        $this->assertNull($result->subscription->subscribedFrom);
        $this->assertNull($result->provisioned);
    }

    public function test_it_activates_a_pending_tenant_subscription(): void
    {
        $uuid = '3f1b1f70-0000-4000-8000-000000000001';
        Http::fake($this->fakePartnerToken() + [
            "api.example.com/v1/partner/plans/tenant/subscriptions/{$uuid}/activate" =>
                Http::response([
                    'data' => [
                        'subscription' => $this->subscriptionPayload(),
                        'provisioning' => [
                            'pool_created' => false,
                            'pool_credits' => 0,
                            'caps_created' => [
                                ['child' => 'tenant', 'id' => 7, 'cap' => 2000],
                            ],
                        ],
                    ],
                ]),
        ]);

        $result = $this->app->make(IOCloudClient::class)
            ->activateTenantSubscription($uuid, 'bank transfer');

        $this->assertTrue($result->subscription->isActive());
        $this->assertSame(2000, $result->provisioned->capsCreated[0]['cap']);
    }

    public function test_it_lists_tenant_subscriptions(): void
    {
        Http::fake($this->fakePartnerToken() + [
            'api.example.com/v1/partner/plans/tenant/subscriptions' => Http::response([
                'data' => ['subscriptions' => [$this->subscriptionPayload()]],
            ]),
        ]);

        $subscriptions = $this->app->make(IOCloudClient::class)
            ->listTenantSubscriptions();

        $this->assertCount(1, $subscriptions);
        $this->assertSame('monthly', $subscriptions[0]->billingCycle);
        $this->assertNull($subscriptions[0]->paymentTransactionUuid);
    }
}
