@extends('layout')

@section('content')
    <h1>Acme Portal</h1>
    <p class="lede">
        A partner system whose users continue into IOCloud without a second login.
        Everything cryptographic here comes from the IOCloud Laravel SDK.
    </p>

    <h2>Signed in as</h2>
    @if (isset($federation['error']))
        <div class="bad">{{ $federation['error'] }}</div>
    @endif

    <table>
        <thead>
        <tr>
            <th>User</th>
            <th>Subject (user claim)</th>
            <th>Email verified</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        @foreach ($users as $user)
            <tr>
                <td>
                    {{ $user->name }}<br>
                    <span class="muted">{{ $user->email }}</span>
                </td>
                <td><code class="wrap">{{ $user->subject }}</code></td>
                <td>{{ $user->emailVerified ? 'yes' : 'no' }}</td>
                <td>
                    <form method="POST" action="{{ route('federated-login') }}">
                        @csrf
                        <input type="hidden" name="subject" value="{{ $user->subject }}">
                        <button type="submit">Continue to IOCloud</button>
                    </form>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <h2>What the SDK publishes</h2>
    @if (isset($federation['error']))
        <p class="muted">Not available until federation is configured.</p>
    @else
        <table>
            <tbody>
            <tr>
                <th>Issuer (<code>iss</code>)</th>
                <td><code class="wrap">{{ $federation['issuer'] }}</code></td>
            </tr>
            <tr>
                <th>Audience (<code>aud</code>)</th>
                <td><code>{{ $federation['audience'] }}</code></td>
            </tr>
            <tr>
                <th>JWKS URL</th>
                <td>
                    <a href="{{ route('jwks') }}"><code class="wrap">{{ $federation['jwks_url'] }}</code></a>
                    <br><span class="muted">
                        Published by one line in <code>routes/web.php</code>:
                        <code>return IOCloud::jwks();</code>
                    </span>
                </td>
            </tr>
            <tr>
                <th>Key id (<code>kid</code>)</th>
                <td><code class="wrap">{{ $federation['kid'] }}</code></td>
            </tr>
            </tbody>
        </table>

        <div class="note">
            Set up once: <code>php artisan iocloud:keys</code>, then
            <code>php artisan demo:federation:register --application=&lt;iocloud-application-uuid&gt; --tenant=&lt;iocloud-tenant-uuid&gt;</code>
        </div>
    @endif
@endsection
