<?php

declare(strict_types=1);

namespace App\Services\Balises;

/**
 * Constantes partagées entre les fournisseurs de balises (PiouPiou, METAR,
 * Windy) et leurs jobs respectifs. Centralisées ici pour éviter la
 * dérive (3 définitions de DEAD_AFTER_DAYS auparavant).
 */
final class BaliseConstants
{
    /**
     * Une balise active depuis plus de N jours sans aucune lecture sur
     * la même période est désactivée automatiquement par les jobs
     * `Fetch*ReadingsJob`. Les balises créées il y a moins de N jours
     * sont préservées (laisse le temps au premier polling de tomber).
     */
    public const DEAD_AFTER_DAYS = 7;
}
