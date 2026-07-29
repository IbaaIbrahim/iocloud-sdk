<?php

namespace App\Http\Controllers;

use App\Support\DemoUserDirectory;
use Illuminate\Contracts\View\View;
use IOCloud\Laravel\Exceptions\IOCloudFederationException;
use IOCloud\Laravel\IOCloudClient;

/**
 * The portal's front page: who can log in, and what the SDK has published.
 *
 * The federation values come straight from the SDK, so the page doubles as a
 * check that key, issuer, and JWKS URL all agree with what was registered.
 */
final class PortalController extends Controller
{
    public function __invoke(DemoUserDirectory $users, IOCloudClient $iocloud): View
    {
        return view('portal', [
            'users' => $users->all(),
            'federation' => $this->federationSummary($iocloud),
        ]);
    }

    /** @return array<string, string> */
    private function federationSummary(IOCloudClient $iocloud): array
    {
        try {
            return $iocloud->federationDetails();
        } catch (IOCloudFederationException $exception) {
            return ['error' => $exception->getMessage()];
        }
    }
}
