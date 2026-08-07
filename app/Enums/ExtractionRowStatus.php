<?php

namespace App\Enums;

enum ExtractionRowStatus: string
{
    case Extracted = 'extracted';
    case Flagged = 'flagged';
    case Confirmed = 'confirmed';
}
