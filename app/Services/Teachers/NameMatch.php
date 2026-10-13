<?php

namespace App\Services\Teachers;

enum NameMatch
{
    /** Confident enough to treat as one person. */
    case Same;
    /** Plausibly one person, but a human should decide. */
    case Possible;
    case Different;
}
