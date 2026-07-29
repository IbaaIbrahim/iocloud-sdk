<?php

namespace App\Http\Controllers;

use App\Http\Requests\FederatedLoginRequest;
use App\Services\PartnerFederation;
use Illuminate\Contracts\View\View;
use IOCloud\Laravel\Exceptions\IOCloudFederationException;
use IOCloud\Laravel\Exceptions\IOCloudTokenExchangeException;

/**
 * "Continue to IOCloud" — the one endpoint a partner has to write.
 *
 * A production portal would redirect into IOCloud with the session token rather
 * than render it; the demo shows it so the exchange is visible.
 */
final class FederatedLoginController extends Controller
{
    public function __invoke(
        FederatedLoginRequest $request,
        PartnerFederation $federation,
    ): View {
        $user = $federation->requireUser($request->subject());

        try {
            $session = $federation->logIn($user);
        } catch (IOCloudTokenExchangeException $exception) {
            // The platform never says which check failed — its audit log does.
            return view('login-rejected', [
                'user' => $user,
                'error' => $exception->error,
                'errorDescription' => $exception->errorDescription,
            ]);
        } catch (IOCloudFederationException $exception) {
            return view('login-rejected', [
                'user' => $user,
                'error' => 'federation_not_configured',
                'errorDescription' => $exception->getMessage(),
            ]);
        }

        return view('login-succeeded', [
            'user' => $user,
            'session' => $session,
        ]);
    }
}
