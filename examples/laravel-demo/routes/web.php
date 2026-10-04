<?php

use App\Http\Controllers\FederatedLoginController;
use App\Http\Controllers\PortalController;
use Illuminate\Support\Facades\Route;
use IOCloud\Laravel\Facades\IOCloud;

Route::get('/', PortalController::class)->name('portal');
Route::post('/federated-login', FederatedLoginController::class)->name('federated-login');

/*
| The JWKS endpoint IOCloud fetches this portal's public signing keys from.
|
| One line: the SDK builds the document from the generated keypair. The path is
| ours to choose, under `/.well-known/` — it only has to match the `jwks_path`
| registered with IOCloud, which fetches it from the issuer's origin.
*/
Route::get('/.well-known/jwks.json', fn (): array => IOCloud::jwks())
    ->name('jwks');
