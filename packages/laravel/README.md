# IOCloud Laravel SDK

```bash
composer require iocloud/laravel-sdk
```

Publish the configuration if it needs customization:

```bash
php artisan vendor:publish --tag=iocloud-config
```

Configure the client:

```dotenv
IOCLOUD_BASE_URL=https://api.example.com
IOCLOUD_CLIENT_ID=partner-client-id
IOCLOUD_CLIENT_SECRET=partner-client-secret
```

The package is auto-discovered by Laravel. Resolve the client with dependency
injection or use the facade:

```php
use IOCloud\Laravel\Facades\IOCloud;

$tenant = IOCloud::createTenant(
    applicationUuid: '11111111-1111-1111-1111-111111111111',
    name: 'Acme workspace',
    slug: 'acme',
    contactEmail: 'ops@acme.example',
);
```

Partner and tenant tokens are cached until shortly before expiration. A `401`
causes one token refresh and retry. API failures throw
`IOCloudAPIException`; authentication failures throw
`IOCloudAuthenticationException`.
