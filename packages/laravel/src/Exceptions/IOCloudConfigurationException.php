<?php

namespace IOCloud\Laravel\Exceptions;

/**
 * The SDK is missing configuration a call needs, so no request was made.
 *
 * Distinct from {@see IOCloudAPIException}, which means IOCloud answered and
 * refused. Catching this type covers every local setup problem — missing partner
 * credentials, no signing key, no issuer.
 */
class IOCloudConfigurationException extends IOCloudException
{
}
