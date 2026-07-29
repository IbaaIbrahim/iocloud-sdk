@extends('layout')

@section('title', 'Signed in to IOCloud')

@section('content')
    <h1>{{ $user->name }} is signed in to IOCloud</h1>
    <p class="lede">
        The portal signed a subject token with its private key; IOCloud verified it
        against the published JWKS and issued this platform session.
    </p>

    <table>
        <tbody>
        <tr>
            <th>Platform user</th>
            <td><code class="wrap">{{ $session->userUuid }}</code></td>
        </tr>
        <tr>
            <th>Name / email on the platform</th>
            <td>{{ $session->name }} &lt;{{ $session->email }}&gt;</td>
        </tr>
        <tr>
            <th>Token type</th>
            <td><code>{{ $session->tokenType }}</code></td>
        </tr>
        <tr>
            <th>Issued token type</th>
            <td><code class="wrap">{{ $session->issuedTokenType }}</code></td>
        </tr>
        <tr>
            <th>Expires</th>
            <td>
                in {{ $session->expiresIn }}s
                <span class="muted">({{ $session->expiresAt->format(DATE_ATOM) }})</span>
            </td>
        </tr>
        <tr>
            <th>Access token</th>
            <td><code class="wrap">{{ $session->accessToken }}</code></td>
        </tr>
        </tbody>
    </table>

    <div class="note">
        The access token is opaque — not a JWT. Send it as
        <code>Authorization: Bearer …</code> on the IOCloud job APIs. There are no
        refresh tokens: when it expires, sign and exchange a new subject token.
    </div>
@endsection
