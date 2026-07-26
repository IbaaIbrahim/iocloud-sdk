<?php

namespace IOCloud\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use IOCloud\Laravel\IOCloudClient;

/** @see IOCloudClient */
final class IOCloud extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return IOCloudClient::class;
    }
}
