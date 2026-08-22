<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\StatutCommande;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Le workflow de commande est la regle metier la plus structurante du
 * back-office : il merite d'etre verrouille par des tests.
 */
#[CoversClass(StatutCommande::class)]
class StatutCommandeTest extends TestCase
{
    public function testLeWorkflowNominalEstAutorise(): void
    {
        self::assertTrue(StatutCommande::EN_ATTENTE->peutEvoluerVers(StatutCommande::PREPAREE));
        self::assertTrue(StatutCommande::PREPAREE->peutEvoluerVers(StatutCommande::RECUPEREE));
    }

    /**
     * Cas explicitement cite dans le dossier (chap. X.1) : on ne peut pas
     * sauter l'etape de preparation.
     */
    public function testOnNePeutPasPasserDirectementDeEnAttenteARecuperee(): void
    {
        self::assertFalse(StatutCommande::EN_ATTENTE->peutEvoluerVers(StatutCommande::RECUPEREE));
    }

    public function testUneCommandeEstAnnulableTantQuElleNEstPasRecuperee(): void
    {
        self::assertTrue(StatutCommande::EN_ATTENTE->peutEvoluerVers(StatutCommande::ANNULEE));
        self::assertTrue(StatutCommande::PREPAREE->peutEvoluerVers(StatutCommande::ANNULEE));
        self::assertFalse(StatutCommande::RECUPEREE->peutEvoluerVers(StatutCommande::ANNULEE));
    }

    #[DataProvider('fournirStatutsFinaux')]
    public function testUnStatutFinalNAccepteAucuneTransition(StatutCommande $statut): void
    {
        self::assertTrue($statut->estFinal());
        self::assertSame([], $statut->transitionsAutorisees());

        foreach (StatutCommande::cases() as $cible) {
            self::assertFalse($statut->peutEvoluerVers($cible));
        }
    }

    /**
     * @return iterable<string, array{StatutCommande}>
     */
    public static function fournirStatutsFinaux(): iterable
    {
        yield 'recuperee' => [StatutCommande::RECUPEREE];
        yield 'annulee' => [StatutCommande::ANNULEE];
    }

    public function testAucunStatutNePeutBoucleSurLuiMeme(): void
    {
        foreach (StatutCommande::cases() as $statut) {
            self::assertFalse(
                $statut->peutEvoluerVers($statut),
                \sprintf('Le statut %s ne devrait pas pouvoir revenir sur lui-meme.', $statut->value)
            );
        }
    }

    public function testSeuleLAnnulationLibereLeStock(): void
    {
        self::assertTrue(StatutCommande::ANNULEE->libereLeStock());

        self::assertFalse(StatutCommande::EN_ATTENTE->libereLeStock());
        self::assertFalse(StatutCommande::PREPAREE->libereLeStock());
        self::assertFalse(StatutCommande::RECUPEREE->libereLeStock());
    }

    public function testChaqueStatutExposeUnLibelleEtUnBadge(): void
    {
        foreach (StatutCommande::cases() as $statut) {
            self::assertNotSame('', $statut->libelle());
            self::assertStringStartsWith('badge-statut-', $statut->classeBadge());
        }
    }
}
