<?php

namespace App\Console\Commands;

use App\Services\PartnerFederation;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use IOCloud\Laravel\Exceptions\IOCloudAPIException;
use IOCloud\Laravel\Exceptions\IOCloudConfigurationException;

/**
 * One-time setup: register this portal with IOCloud and map its tenant.
 *
 * Run once per environment, after `php artisan iocloud:keys`. Logins
 * fail until both steps are done — with `invalid_grant` for an unknown issuer,
 * and `invalid_target` for an unmapped tenant.
 */
final class RegisterFederationCommand extends Command
{
    protected $signature = 'demo:federation:register
        {--tenant= : IOCloud tenant UUID to map the external tenant onto}
        {--external-tenant= : The portal organisation id to map}';

    protected $description = 'Register this portal as an IOCloud identity provider and map its tenant';

    public function handle(PartnerFederation $federation, Config $config): int
    {
        try {
            $provider = $federation->existingProvider();
            if ($provider !== null) {
                $this->components->info(
                    "Issuer already registered as provider {$provider->uuid}."
                );
            } else {
                $provider = $federation->registerAsIdentityProvider(
                    name: (string) $config->get('demo.provider_name'),
                    requireEmailVerified: (bool) $config->get('demo.require_email_verified'),
                    allowJitUsers: (bool) $config->get('demo.allow_jit_users'),
                );
                $this->components->info("Registered provider {$provider->uuid}.");
            }

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

            return $this->mapTenant($federation, $config, $provider->uuid);
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

    private function mapTenant(
        PartnerFederation $federation,
        Config $config,
        string $providerUuid,
    ): int {
        $tenantUuid = $this->resolveOption('tenant', 'demo.iocloud_tenant_uuid', $config);
        $externalTenantId = $this->resolveOption(
            'external-tenant',
            'demo.users.0.external_tenant_id',
            $config,
        );

        if ($tenantUuid === null) {
            $this->components->warn(
                'No IOCloud tenant given, so no external tenant was mapped. Pass'
                .' --tenant=<uuid> or set DEMO_IOCLOUD_TENANT_UUID; logins fail with'
                .' invalid_target until the mapping exists.'
            );

            return self::SUCCESS;
        }
        if ($externalTenantId === null) {
            $this->components->error('No external tenant id to map.');

            return self::FAILURE;
        }

        $federation->mapTenant($providerUuid, $tenantUuid, $externalTenantId);
        $this->components->info("Mapped '{$externalTenantId}' onto tenant {$tenantUuid}.");

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
