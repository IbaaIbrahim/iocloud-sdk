# IOCloud Node.js SDK

```bash
npm install @iocloud/sdk
```

```ts
import { IOCloudClient } from "@iocloud/sdk";

const client = new IOCloudClient({
  clientId: process.env.IOCLOUD_CLIENT_ID!,
  clientSecret: process.env.IOCLOUD_CLIENT_SECRET!,
  baseUrl: process.env.IOCLOUD_BASE_URL!,
});

const tenant = await client.createTenant({
  applicationUuid: "11111111-1111-1111-1111-111111111111",
  name: "Acme workspace",
  slug: "acme",
  contactEmail: "ops@acme.example",
});
```

Partner and tenant tokens are cached until shortly before expiration. An
authenticated request that returns `401` is retried once with a refreshed
token. API failures throw `IOCloudAPIError`; authentication failures throw
`IOCloudAuthenticationError`.
