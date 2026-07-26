<?php

namespace IOCloud\Laravel\Tests;

use Illuminate\Support\Facades\Http;
use IOCloud\Laravel\IOCloudClient;

final class IOCloudClientTest extends TestCase
{
    public function test_it_caches_a_fresh_partner_token(): void
    {
        Http::fake([
            'api.example.com/v1/partner/auth/token' => Http::response([
                'data' => [
                    'token' => [
                        'access_token' => 'partner-token',
                        'token_type' => 'Bearer',
                        'expires_at' => '2099-01-01T00:00:00Z',
                    ],
                ],
            ]),
        ]);

        $client = $this->app->make(IOCloudClient::class);
        $first = $client->issuePartnerToken();
        $second = $client->issuePartnerToken();

        $this->assertSame($first, $second);
        Http::assertSentCount(1);
    }
}
