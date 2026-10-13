<?php

namespace App\Services\Teachers;

use RuntimeException;

/**
 * Who a name refers to changed between preflight and commit — another
 * import or an Admin decision landed in between. Nothing was written.
 */
class IdentityChangedException extends RuntimeException
{
    public function __construct(public readonly string $name)
    {
        parent::__construct("The directory changed while \"{$name}\" was being matched. Nothing was imported — confirm again.");
    }
}
