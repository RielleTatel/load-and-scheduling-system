<?php

namespace App\Services\Plantilla;

use RuntimeException;

/**
 * Thrown when a PDF yields no extractable text (e.g. a scanned image).
 * The upload flow catches this and drops the Chair onto an empty review
 * grid for manual entry — extraction failure never blocks the workflow.
 */
class ExtractionFailedException extends RuntimeException
{
}
