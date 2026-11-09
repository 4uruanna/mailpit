<?php

declare(strict_types=1);

namespace App\Log;

/**
 * Résultat de LogRotator::rotate().
 */
enum LogRotation
{
    /** Rien à faire : pas de fichier de log, rotation désactivée, fichier absent ou sous la limite. */
    case NotNeeded;

    /** Le fichier a été renommé en « .1 » : le log repart de zéro. */
    case Rotated;

    /** Une autre requête archive le fichier en ce moment (verrou pris) : rien à faire. */
    case Busy;

    /** Archivage impossible (droits du dossier…) : le log continue dans le même fichier. */
    case Failed;
}
