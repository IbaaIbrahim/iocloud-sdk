<?php

namespace IOCloud\Laravel\Tests;

use DateTimeImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use IOCloud\Laravel\Data\StandingEntry;
use IOCloud\Laravel\Exceptions\IOCloudAPIException;
use IOCloud\Laravel\Facades\IOCloud;
use IOCloud\Laravel\IOCloudClient;

final class ClientStandingContextTest extends TestCase
{
    private const TENANT_UUID = '3f1b1f70-0000-4000-8000-0000000000d1';
    private const USER_UUID = '3f1b1f70-0000-4000-8000-0000000000f1';
    private const ENTRY_UUID = '3f1b1f70-0000-4000-8000-0000000000a1';
    private const JOB_UUID = '3f1b1f70-0000-4000-8000-0000000000a9';

    private const WORKSPACE_PATH = '/v1/partner/tenants/'.self::TENANT_UUID.'/standing-context';
    private const PERSONAL_PATH = '/v1/partner/tenants/'.self::TENANT_UUID
        .'/users/'.self::USER_UUID.'/standing-context';

    /** @return array<string, mixed> */
    private function entryPayload(array $overrides = []): array
    {
        return array_merge([
            'uuid' => self::ENTRY_UUID,
            'scope' => 'tenant',
            'kind' => 'instruction',
            'body' => 'Answer in formal German.',
            'status' => 'active',
            'expires_on' => '2026-12-31',
            'expired' => false,
            'source' => 'api',
            'source_job_uuid' => null,
            'created_at' => '2026-10-24T09:00:00Z',
            'updated_at' => '2026-10-24T09:30:00Z',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function numberedEntry(int $index, array $overrides = []): array
    {
        return $this->entryPayload(array_merge([
            'uuid' => sprintf('3f1b1f70-0000-4000-8000-%012d', $index),
        ], $overrides));
    }

    /** @return array<string, mixed> */
    private function listing(array $items, int $total, int $offset = 0): array
    {
        return [
            'code' => 'STANDING_CONTEXT_LISTED',
            'message' => '',
            'data' => [
                'entries' => [
                    'items' => $items,
                    'pagination' => [
                        'total' => $total,
                        'page' => intdiv($offset, 100) + 1,
                        'per_page' => 100,
                        'pages' => intdiv($total + 99, 100),
                    ],
                ],
            ],
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

    /** @return array<string, string> */
    private function query(Request $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query;
    }

    private function path(Request $request): string
    {
        return (string) parse_url($request->url(), PHP_URL_PATH);
    }

    /** @return list<Request> */
    private function standingRequests(): array
    {
        return Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/standing-context'))
            ->map(fn (array $pair): Request => $pair[0])
            ->values()
            ->all();
    }

    private function client(): IOCloudClient
    {
        return $this->app->make(IOCloudClient::class);
    }

    public function test_it_lists_a_workspaces_entries_and_parses_them(): void
    {
        $agentMemory = $this->numberedEntry(2, [
            'kind' => 'memory',
            'body' => 'Audits follow ISO 9001.',
            'expires_on' => null,
            'source' => 'agent',
            'source_job_uuid' => self::JOB_UUID,
        ]);
        Http::fake($this->fakePartnerToken() + [
            'api.example.com'.self::WORKSPACE_PATH.'*' => Http::response(
                $this->listing([$this->entryPayload(), $agentMemory], 2)
            ),
        ]);

        $entries = $this->client()->listStandingEntries(self::TENANT_UUID);

        $requests = $this->standingRequests();
        $this->assertCount(1, $requests);
        $this->assertSame('GET', $requests[0]->method());
        $this->assertSame(self::WORKSPACE_PATH, $this->path($requests[0]));
        $this->assertTrue($requests[0]->hasHeader('Authorization', 'Bearer partner-token'));
        $this->assertSame(
            ['include_retired' => 'false', 'limit' => '100', 'offset' => '0'],
            $this->query($requests[0]),
        );

        $this->assertCount(2, $entries);
        $instruction = $entries[0];
        $this->assertInstanceOf(StandingEntry::class, $instruction);
        $this->assertSame(self::ENTRY_UUID, $instruction->uuid);
        $this->assertSame('tenant', $instruction->scope);
        $this->assertSame('instruction', $instruction->kind);
        $this->assertSame('Answer in formal German.', $instruction->body);
        $this->assertTrue($instruction->isActive());
        $this->assertSame('2026-12-31', $instruction->expiresOn);
        $this->assertFalse($instruction->expired);
        $this->assertSame('api', $instruction->source);
        $this->assertNull($instruction->sourceJobUuid);
        $this->assertEquals(new DateTimeImmutable('2026-10-24T09:00:00Z'), $instruction->createdAt);
        $this->assertEquals(new DateTimeImmutable('2026-10-24T09:30:00Z'), $instruction->updatedAt);

        $this->assertSame('memory', $entries[1]->kind);
        $this->assertNull($entries[1]->expiresOn);
        $this->assertSame('agent', $entries[1]->source);
        $this->assertSame(self::JOB_UUID, $entries[1]->sourceJobUuid);
    }

    public function test_an_expired_entry_is_listed_and_flagged(): void
    {
        Http::fake($this->fakePartnerToken() + [
            'api.example.com'.self::WORKSPACE_PATH.'*' => Http::response($this->listing([
                $this->entryPayload(['expires_on' => '2026-01-31', 'expired' => true]),
            ], 1)),
        ]);

        $entries = $this->client()->listStandingEntries(self::TENANT_UUID);

        $this->assertTrue($entries[0]->expired);
        $this->assertTrue($entries[0]->isActive());
    }

    public function test_it_reads_a_users_entries_across_pages_with_retired_ones(): void
    {
        $firstPage = [];
        for ($index = 0; $index < 100; $index++) {
            $firstPage[] = $this->numberedEntry($index, ['scope' => 'user']);
        }
        $lastPage = [$this->numberedEntry(100, ['scope' => 'user', 'status' => 'retired'])];

        Http::fake($this->fakePartnerToken() + [
            'api.example.com'.self::PERSONAL_PATH.'*' => function (Request $request) use ($firstPage, $lastPage) {
                $offset = (int) $this->query($request)['offset'];

                return Http::response($this->listing($offset === 0 ? $firstPage : $lastPage, 101, $offset));
            },
        ]);

        $entries = $this->client()->listStandingEntries(
            tenantUuid: self::TENANT_UUID,
            userUuid: self::USER_UUID,
            includeRetired: true,
        );

        $requests = $this->standingRequests();
        $this->assertSame(
            [self::PERSONAL_PATH, self::PERSONAL_PATH],
            array_map(fn (Request $request): string => $this->path($request), $requests),
        );
        $this->assertSame(
            [
                ['include_retired' => 'true', 'limit' => '100', 'offset' => '0'],
                ['include_retired' => 'true', 'limit' => '100', 'offset' => '100'],
            ],
            array_map(fn (Request $request): array => $this->query($request), $requests),
        );
        $this->assertCount(101, $entries);
        $this->assertSame('user', $entries[0]->scope);
        $this->assertSame('retired', $entries[100]->status);
        $this->assertFalse($entries[100]->isActive());
    }

    public function test_it_stops_reading_at_the_page_cap(): void
    {
        Http::fake($this->fakePartnerToken() + [
            'api.example.com'.self::WORKSPACE_PATH.'*' => function (Request $request) {
                $offset = (int) $this->query($request)['offset'];
                $items = [];
                for ($index = 0; $index < 100; $index++) {
                    $items[] = $this->numberedEntry($offset + $index);
                }

                return Http::response($this->listing($items, 1_000_000, $offset));
            },
        ]);

        $entries = $this->client()->listStandingEntries(self::TENANT_UUID);

        $this->assertCount(50, $this->standingRequests());
        $this->assertCount(5000, $entries);
    }

    public function test_an_empty_listing_is_one_request(): void
    {
        Http::fake($this->fakePartnerToken() + [
            'api.example.com'.self::WORKSPACE_PATH.'*' => Http::response($this->listing([], 0)),
        ]);

        $entries = $this->client()->listStandingEntries(self::TENANT_UUID);

        $this->assertSame([], $entries);
        $this->assertCount(1, $this->standingRequests());
    }

    public function test_it_gets_one_entry_from_either_family(): void
    {
        Http::fake($this->fakePartnerToken() + [
            'api.example.com/v1/partner/tenants/*' => Http::response(['data' => ['entry' => $this->entryPayload()]]),
        ]);

        $workspace = $this->client()->getStandingEntry(self::TENANT_UUID, self::ENTRY_UUID);
        $this->client()->getStandingEntry(
            tenantUuid: self::TENANT_UUID,
            entryUuid: self::ENTRY_UUID,
            userUuid: self::USER_UUID,
        );

        $this->assertSame(self::ENTRY_UUID, $workspace->uuid);
        $this->assertSame(
            [
                ['GET', self::WORKSPACE_PATH.'/'.self::ENTRY_UUID],
                ['GET', self::PERSONAL_PATH.'/'.self::ENTRY_UUID],
            ],
            array_map(
                fn (Request $request): array => [$request->method(), $this->path($request)],
                $this->standingRequests(),
            ),
        );
    }

    public function test_it_creates_a_workspace_instruction_with_an_expiry(): void
    {
        Http::fake($this->fakePartnerToken() + [
            'api.example.com'.self::WORKSPACE_PATH => Http::response(['data' => ['entry' => $this->entryPayload()]], 201),
        ]);

        $entry = $this->client()->createStandingEntry(
            tenantUuid: self::TENANT_UUID,
            kind: 'instruction',
            body: 'Answer in formal German.',
            expiresOn: new DateTimeImmutable('2026-12-31'),
        );

        $this->assertSame('2026-12-31', $entry->expiresOn);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $this->path($request) === self::WORKSPACE_PATH
            && $request->data() === [
                'kind' => 'instruction',
                'body' => 'Answer in formal German.',
                'expires_on' => '2026-12-31',
            ]);
    }

    public function test_it_creates_a_personal_memory_without_an_expiry(): void
    {
        Http::fake($this->fakePartnerToken() + [
            'api.example.com'.self::PERSONAL_PATH => Http::response([
                'data' => ['entry' => $this->entryPayload(['scope' => 'user', 'kind' => 'memory'])],
            ], 201),
        ]);

        $entry = IOCloud::createStandingEntry(
            tenantUuid: self::TENANT_UUID,
            kind: 'memory',
            body: 'Manages the Hamburg site.',
            userUuid: self::USER_UUID,
        );

        $this->assertSame('user', $entry->scope);
        Http::assertSent(fn (Request $request): bool => $this->path($request) === self::PERSONAL_PATH
            && $request->data() === ['kind' => 'memory', 'body' => 'Manages the Hamburg site.']);
    }

    public function test_an_update_sends_a_null_expiry_only_when_clearing_it(): void
    {
        Http::fake($this->fakePartnerToken() + [
            'api.example.com/v1/partner/tenants/*' => Http::response(['data' => ['entry' => $this->entryPayload()]]),
        ]);

        $client = $this->client();
        $client->updateStandingEntry(self::TENANT_UUID, self::ENTRY_UUID, clearExpiresOn: true);
        $client->updateStandingEntry(self::TENANT_UUID, self::ENTRY_UUID, body: 'Answer in formal English.');
        $client->updateStandingEntry(
            tenantUuid: self::TENANT_UUID,
            entryUuid: self::ENTRY_UUID,
            userUuid: self::USER_UUID,
            expiresOn: '2027-01-31',
            status: 'retired',
        );

        $requests = $this->standingRequests();
        $this->assertSame(
            [
                ['PATCH', self::WORKSPACE_PATH.'/'.self::ENTRY_UUID],
                ['PATCH', self::WORKSPACE_PATH.'/'.self::ENTRY_UUID],
                ['PATCH', self::PERSONAL_PATH.'/'.self::ENTRY_UUID],
            ],
            array_map(fn (Request $request): array => [$request->method(), $this->path($request)], $requests),
        );
        $this->assertSame(['expires_on' => null], $requests[0]->data());
        $this->assertSame(['body' => 'Answer in formal English.'], $requests[1]->data());
        $this->assertArrayNotHasKey('expires_on', $requests[1]->data());
        $this->assertSame(['expires_on' => '2027-01-31', 'status' => 'retired'], $requests[2]->data());
    }

    public function test_clearing_and_setting_the_expiry_at_once_is_refused(): void
    {
        Http::fake();

        try {
            $this->client()->updateStandingEntry(
                self::TENANT_UUID,
                self::ENTRY_UUID,
                expiresOn: '2027-01-31',
                clearExpiresOn: true,
            );
            $this->fail('expected the conflicting expiry to be refused');
        } catch (InvalidArgumentException) {
            Http::assertNothingSent();
        }
    }

    public function test_it_deletes_an_entry_from_either_family(): void
    {
        Http::fake($this->fakePartnerToken() + [
            'api.example.com/v1/partner/tenants/*' => Http::response([
                'code' => 'STANDING_ENTRY_DELETED',
                'message' => 'Deleted.',
                'data' => ['entry_uuid' => self::ENTRY_UUID],
            ]),
        ]);

        $this->client()->deleteStandingEntry(self::TENANT_UUID, self::ENTRY_UUID);
        $this->client()->deleteStandingEntry(self::TENANT_UUID, self::ENTRY_UUID, userUuid: self::USER_UUID);

        $this->assertSame(
            [
                ['DELETE', self::WORKSPACE_PATH.'/'.self::ENTRY_UUID],
                ['DELETE', self::PERSONAL_PATH.'/'.self::ENTRY_UUID],
            ],
            array_map(
                fn (Request $request): array => [$request->method(), $this->path($request)],
                $this->standingRequests(),
            ),
        );
    }

    private function refusal(int $status, array $body): IOCloudAPIException
    {
        Http::fake($this->fakePartnerToken() + [
            'api.example.com'.self::WORKSPACE_PATH => Http::response($body, $status),
        ]);

        try {
            $this->client()->createStandingEntry(self::TENANT_UUID, kind: 'memory', body: 'x');
        } catch (IOCloudAPIException $exception) {
            return $exception;
        }

        $this->fail('expected the entry to be refused');
    }

    public function test_a_full_context_carries_its_code_and_reason(): void
    {
        $exception = $this->refusal(409, [
            'code' => 'STANDING_CONTEXT_FULL',
            'message' => 'There is no room for another memory.',
            'error' => ['reason' => 'STANDING_CONTEXT_FULL'],
        ]);

        $this->assertSame(409, $exception->statusCode);
        $this->assertSame('STANDING_CONTEXT_FULL', $exception->errorCode);
        $this->assertSame('STANDING_CONTEXT_FULL', $exception->reason);
        $this->assertSame([], $exception->fields);
    }

    public function test_a_too_long_entry_is_told_apart_by_its_reason(): void
    {
        $exception = $this->refusal(422, [
            'code' => 'STANDING_ENTRY_INVALID',
            'message' => 'A memory is at most 300 characters.',
            'error' => ['reason' => 'STANDING_ENTRY_TOO_LONG'],
        ]);

        $this->assertSame('STANDING_ENTRY_INVALID', $exception->errorCode);
        $this->assertSame('STANDING_ENTRY_TOO_LONG', $exception->reason);
    }

    public function test_a_validation_error_carries_its_fields(): void
    {
        $exception = $this->refusal(422, [
            'code' => 'VALIDATION_ERROR',
            'message' => 'Request validation failed.',
            'error' => ['fields' => ['body.kind' => ["Input should be 'instruction' or 'memory'"]]],
        ]);

        $this->assertSame('VALIDATION_ERROR', $exception->errorCode);
        $this->assertNull($exception->reason);
        $this->assertSame(['body.kind' => ["Input should be 'instruction' or 'memory'"]], $exception->fields);
    }

    public function test_a_refusal_without_details_has_no_reason(): void
    {
        $exception = $this->refusal(404, [
            'code' => 'USER_NOT_FOUND',
            'message' => 'No such user.',
            'error' => null,
        ]);

        $this->assertSame('USER_NOT_FOUND', $exception->errorCode);
        $this->assertNull($exception->reason);
        $this->assertSame([], $exception->fields);
    }
}
