<?php

namespace IOCloud\Laravel\Exceptions;

/**
 * Partner-side federation is misconfigured: no signing key, bad PEM, no issuer.
 *
 * A {@see IOCloudConfigurationException}, so a handler that reports local setup
 * problems can catch the parent type and cover partner credentials too.
 */
class IOCloudFederationException extends IOCloudConfigurationException
{
}
