<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\StatutCommande;

/**
 * Levee lorsqu'un changement de statut ne respecte pas le workflow
 * EN_ATTENTE -> PREPAREE -> RECUPEREE (dossier chap. VII.2.3).
 *
 * Empeche par exemple de marquer "recuperee" une commande qui n'a jamais
 * ete preparee.
 */
class TransitionStatutInvalideException extends \DomainException
{
    public function __construct(
        private readonly StatutCommande $depuis,
        private readonly StatutCommande $vers,
    ) {
        parent::__construct(\sprintf(
            'Transition de statut invalide : %s -> %s.',
            $depuis->value,
            $vers->value
        ));
    }

    public function getDepuis(): StatutCommande
    {
        return $this->depuis;
    }

    public function getVers(): StatutCommande
    {
        return $this->vers;
    }

    public function messageUtilisateur(): string
    {
        if ($this->depuis->estFinal()) {
            return \sprintf(
                'La commande est "%s" : son statut ne peut plus etre modifie.',
                $this->depuis->libelle()
            );
        }

        $autorises = array_map(
            static fn (StatutCommande $statut): string => $statut->libelle(),
            $this->depuis->transitionsAutorisees()
        );

        return \sprintf(
            'Passage impossible de "%s" a "%s". Statuts autorises : %s.',
            $this->depuis->libelle(),
            $this->vers->libelle(),
            implode(', ', $autorises)
        );
    }
}
