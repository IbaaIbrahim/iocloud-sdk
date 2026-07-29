<?php

namespace App\Support;

use Illuminate\Contracts\Config\Repository as Config;

/**
 * The partner's user store, stubbed out with configuration.
 *
 * A real portal would query its own users table here. Nothing about the IOCloud
 * integration changes: it needs a stable subject, a tenant id, and optionally an
 * email and display name.
 */
final readonly class DemoUserDirectory
{
    public function __construct(private Config $config)
    {
    }

    /** @return list<DemoUser> */
    public function all(): array
    {
        return array_values(array_map(
            DemoUser::fromArray(...),
            (array) $this->config->get('demo.users', []),
        ));
    }

    public function find(string $subject): ?DemoUser
    {
        foreach ($this->all() as $user) {
            if ($user->subject === $subject) {
                return $user;
            }
        }

        return null;
    }
}
