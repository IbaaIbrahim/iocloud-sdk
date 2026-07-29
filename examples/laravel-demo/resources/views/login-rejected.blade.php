@extends('layout')

@section('title', 'IOCloud rejected the login')

@section('content')
    <h1>IOCloud rejected the login for {{ $user->name }}</h1>

    <div class="bad">
        <strong><code>{{ $error }}</code></strong><br>
        {{ $errorDescription }}
    </div>

    <h2>What each error means</h2>
    <table>
        <tbody>
        <tr>
            <th><code>invalid_grant</code></th>
            <td>
                The subject token was not accepted: unknown or disabled issuer,
                signature mismatch, wrong audience, expired or replayed token,
                missing claims, an unverified email where the provider requires
                one, or an unknown subject with JIT provisioning off.
            </td>
        </tr>
        <tr>
            <th><code>invalid_target</code></th>
            <td>
                The token's tenant claim is not mapped to an IOCloud tenant, or the
                mapped tenant is not active. Run
                <code>php artisan demo:federation:register --tenant=&lt;uuid&gt;</code>.
            </td>
        </tr>
        <tr>
            <th><code>federation_not_configured</code></th>
            <td>
                This portal has no signing key or issuer yet. Run
                <code>php artisan iocloud:keys</code> and set
                <code>IOCLOUD_FEDERATION_ISSUER</code>.
            </td>
        </tr>
        </tbody>
    </table>

    <p class="muted">
        The response body is deliberately vague about which check failed. The
        precise reason is in IOCloud's <code>user.federated_login_failed</code>
        audit event.
    </p>
@endsection
