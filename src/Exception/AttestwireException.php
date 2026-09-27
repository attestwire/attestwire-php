<?php

declare(strict_types=1);

namespace Attestwire\Exception;

use RuntimeException;

/**
 * Base class for everything this package raises.
 *
 * A finding — even a fatal one, "this invoice is not compliant" — is never an
 * exception: Client::validate() returns a ValidationResult with valid=false
 * and the findings that explain why. These are for when validation could not
 * run at all: no API key, a network failure, or an HTTP error status.
 */
class AttestwireException extends RuntimeException
{
}
