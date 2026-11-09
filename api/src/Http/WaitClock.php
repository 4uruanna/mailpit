<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Horloge d'une requête qui attend (GET /v1/messages?wait=…) : temps écoulé, pause entre deux
 * vérifications, client parti. SystemWaitClock en production ; les tests en passent une fausse à
 * Kernel::create, pour ne pas attendre réellement jusqu'à 60 secondes.
 */
interface WaitClock
{
    /** Temps monotone, en secondes (insensible aux changements de l'heure système). */
    public function now(): float;

    /** Pause de $seconds secondes. */
    public function sleep(float $seconds): void;

    /**
     * Le client a fermé la connexion. Souvent indétectable : PHP ne l'apprend qu'en écrivant
     * la réponse, et rien n'est envoyé pendant l'attente.
     */
    public function clientGone(): bool;
}
