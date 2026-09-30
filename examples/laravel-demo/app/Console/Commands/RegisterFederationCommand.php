<?php

namespace App\Console\Commands;

use App\Services\PartnerFederation;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use IOCloud\Laravel\Data\IdentityProvider;
use IOCloud\Laravel\Exceptions\IOCloudAPIException;
use IOCloud\Laravel\Exceptions\IOCloudConfigurationException;

/**
 * One-time setup: register this portal with IOCloud and name its tenant.
 *
 * Run once per environment, after `php artisan iocloud:keys`. The provider
 * belongs to one IOCloud application and logs users into that application's
 * tenants only. Logins fail until both steps are done — with `invalid_grant`
 * for an unknown issuer, and `invalid_target` while no tenant of the
 * application carries the portal's organisation id as its external id.
 */
final class RegisterFederationCommand extends Command
{
    protected $signature = 'demo:federation:register
        {--application= : IOCloud application UUID the identity provider belongs to}
        {--tenant= : IOCloud tenant UUID to give the portal organisation id}
        {--external-tenant= : The portal organisation id to set as its external id}';

    protected $description = 'Register this portal as an IOCloud identity provider and set its tenant\'s external id';

    public function handle(PartnerFederation $federation, Config $config): int
    {
        try {
            $provider = $federation->existingProvider();
            if ($provider !== null) {
                $this->components->info(
                    "Issuer already registered as provider {$provider->uuid}."
                );
            } else {
                // Only a new registration needs it: an existing provider already
                // belongs to its application.
                $applicationUuid = $this->resolveOption(
                    'application',
                    'demo.iocloud_application_uuid',
                    $config,
                );
                if ($applicationUuid === null) {
                    $this->components->error(
                        'No IOCloud application given. Pass --application=<uuid> or set'
                        .' DEMO_IOCLOUD_APPLICATION_UUID: every identity provider belongs'
                        .' to one application and logs users into its tenants only.'
                    );

                    return self::FAILURE;
                }

                $provider = $federation->registerAsIdentityProvider(
                    applicationUuid: $applicationUuid,
                    name: (string) $config->get('demo.provider_name'),
                    requireEmailVerified: (bool) $config->get('demo.require_email_verified'),
                    allowJitUsers: (bool) $config->get('demo.allow_jit_users'),
                );
                $this->components->info("Registered provider {$provider->uuid}.");
            }

            $this->describe($provider);

            return $this->setTenantExternalId($federation, $config);
        } catch (IOCloudConfigurationException $exception) {
            // Covers a missing signing key, no issuer, and absent partner
            // credentials — all local setup, reported without a stack trace.
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        } catch (IOCloudAPIException $exception) {
            $this->components->error(
                "IOCloud rejected the request: {$exception->getMessage()}"
            );

            return self::FAILURE;
        }
    }

    private function describe(IdentityProvider $provider): void
    {
        $this->components->twoColumnDetail('Application', $provider->applicationUuid);
        $this->components->twoColumnDetail('Issuer', $provider->issuer);
        $this->components->twoColumnDetail('JWKS URL', $provider->jwksUrl);
        $this->components->twoColumnDetail(
            'Requires verified email',
            $provider->requireEmailVerified ? 'yes' : 'no',
        );
        $this->components->twoColumnDetail(
            'Creates unknown users (JIT)',
            $provider->allowJitUsers ? 'yes' : 'no',
        );
    }

    private function setTenantExternalId(PartnerFederation $federation, Config $config): int
    {
        $tenantUuid = $this->resolveOption('tenant', 'demo.iocloud_tenant_uuid', $config);
        $externalTenantId = $this->resolveOption(
            'external-tenant',
            'demo.users.0.external_tenant_id',
            $config,
        );

        if ($tenantUuid === null) {
            $this->components->warn(
                'No IOCloud tenant given, so no external id was set. Pass'
                .' --tenant=<uuid> or set DEMO_IOCLOUD_TENANT_UUID; logins fail with'
                .' invalid_target until a tenant of the application carries it.'
            );

            return self::SUCCESS;
        }
        if ($externalTenantId === null) {
            $this->components->error('No external tenant id to set.');

            return self::FAILURE;
        }

        $federation->setTenantExternalId($tenantUuid, $externalTenantId);
        $this->components->info("Tenant {$tenantUuid} now carries the external id '{$externalTenantId}'.");

        return self::SUCCESS;
    }

    private function resolveOption(string $option, string $configKey, Config $config): ?string
    {
        $value = $this->option($option);
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        $configured = $config->get($configKey);

        return is_string($configured) && trim($configured) !== '' ? trim($configured) : null;
    }
}
