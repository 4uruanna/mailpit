<?php

declare(strict_types=1);

namespace Relay;

use RuntimeException;

/**
 * Refus que réessayer ne changera pas (ex. l'API juge le message invalide) :
 * le mail est marqué du tag RELAY_TAG_FAILED dans Mailpit.
 */
final class PermanentException extends RuntimeException
{
}
