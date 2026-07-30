<?php

namespace App\Exceptions;

/**
 * A provisioning step cannot proceed; commands convert this into a clean
 * bail() message. Catch this class specifically — never bare RuntimeException,
 * which Guzzle's transport exceptions also extend and would be misclassified.
 */
class ProvisioningFailedException extends \RuntimeException
{
}
