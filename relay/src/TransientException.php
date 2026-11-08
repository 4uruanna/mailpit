<?php

declare(strict_types=1);

namespace Relay;

use RuntimeException;

/** Erreur passagère (service injoignable, délai dépassé…) : le mail sera réessayé plus tard. */
final class TransientException extends RuntimeException
{
}
