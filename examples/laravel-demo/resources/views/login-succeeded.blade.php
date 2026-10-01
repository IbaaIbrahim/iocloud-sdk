@extends('layout')

@section('title', 'A subject token for IOCloud')

@section('content')
    <h1>A subject token for {{ $user->name }}</h1>
    <p class="lede">
        The portal signed this token with its private key and sent it nowhere. A real
        portal returns it to its frontend, whose chat client exchanges it with IOCloud
        for the platform session.
    </p>

    <table>
        <tbody>
        <tr>
            <th>Subject token</th>
            <td><code class="wrap">{{ $subjectToken }}</code></td>
        </tr>
        </tbody>
    </table>

    <div class="note">
        The chat client posts it to IOCloud's <code>/v1/federation/token</code>
        (RFC 8693, form-encoded: <code>grant_type</code>, <code>subject_token</code>,
        <code>subject_token_type</code>) and receives an opaque access token to send
        as <code>Authorization: Bearer …</code>. A subject token is short-lived and is
        exchanged once, so the client asks the portal for a fresh one whenever it
        needs a session.
    </div>
@endsection
