<?php
declare(strict_types=1);

namespace HelpdeskForm\Exceptions;

/**
 * Exception whose message is safe to display to end users (e.g. validation
 * errors, throttle notices). Anything that is not a UserFacingException is
 * treated as internal and surfaced to the client as a generic message.
 */
class UserFacingException extends \RuntimeException
{
}
