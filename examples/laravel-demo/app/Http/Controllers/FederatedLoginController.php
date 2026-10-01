<?php

namespace App\Http\Controllers;

use App\Http\Requests\FederatedLoginRequest;
use App\Services\PartnerFederation;
use Illuminate\Contracts\View\View;
use IOCloud\Laravel\Exceptions\IOCloudFederationException;

/**
 * "Continue to IOCloud" — the one endpoint a partner has to write.
 *
 * It signs a subject token for the user. A production portal returns that token
 * to its frontend, whose chat client exchanges it with IOCloud; the demo renders
 * it, so it can be inspected and exchanged by hand.
 */
final class FederatedLoginController extends Controller
{
    public function __invoke(
        FederatedLoginRequest $request,
        PartnerFederation $federation,
    ): View {
        $user = $federation->requireUser($request->subject());

        try {
            $subjectToken = $federation->signSubjectToken($user);
        } catch (IOCloudFederationException $exception) {
            return view('login-rejected', [
                'user' => $user,
                'error' => 'federation_not_configured',
                'errorDescription' => $exception->getMessage(),
            ]);
        }

        return view('login-succeeded', [
            'user' => $user,
            'subjectToken' => $subjectToken,
        ]);
    }
}
