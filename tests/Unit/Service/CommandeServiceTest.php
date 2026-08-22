<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Commande;
use App\Entity\LigneCommande;
use App\Entity\Produit;
use App\Entity\Stock;
use App\Entity\Utilisateur;
use App\Enum\StatutCommande;
use App\Enum\UniteVente;
use App\Exception\PanierVideException;
use App\Exception\StockInsuffisantException;
use App\Exception\TransitionStatutInvalideException;
use App\Model\LignePanier;
use App\Repository\CommandeRepository;
use App\Service\CommandeService;
use App\Service\StockService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests du scenario central du projet : la transformation d'un panier en
 * commande, et le respect du workflow de statut.
 *
 * Les collaborateurs sont montes dans setUp() ; selon le test ils servent de
 * bouchons ou de mocks verifies, d'ou l'attribut ci-dessous.
 */
#[CoversClass(CommandeService::class)]
#[AllowMockObjectsWithoutExpectations]
class CommandeServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private CommandeRepository&MockObject $commandeRepository;
    private StockService&MockObject $stockService;
    private CommandeService $commandeService;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->commandeRepository = $this->createMock(CommandeRepository::class);
        $this->stockService = $this->createMock(StockService::class);

        $this->commandeRepository->method('referenceExiste')->willReturn(false);

        $this->commandeService = new CommandeService(
            $this->entityManager,
            $this->commandeRepository,
            $this->stockService,
        );
    }

    private function creerProduit(string $nom, float $prix, float $stock = 100.0): Produit
    {
        $produit = (new Produit())
            ->setNom($nom)
            ->setPrix($prix)
            ->setUniteVente(UniteVente::KG);

        $entite = (new Stock())
            ->setQuantiteAchetee($stock)
            ->setQuantiteDisponible($stock);

        $produit->setStock($entite);

        return $produit;
    }

    private function creerClient(): Utilisateur
    {
        return (new Utilisateur())
            ->setEmail('client@tarchoun.fr')
            ->setPrenom('Sophie')
            ->setNom('Bernard')
            ->setMotDePasse('hache');
    }

    // ----- creerCommande -----

    public function testCreerCommandeConstruitLesLignesEtLeTotal(): void
    {
        $tomates = $this->creerProduit('Tomates grappe', 2.50);
        $pommes = $this->creerProduit('Pommes Gala', 3.20);

        $lignes = [
            new LignePanier($tomates, 2.0), // 5.00
            new LignePanier($pommes, 1.5),  // 4.80
        ];

        $this->entityManager->expects(self::once())->method('beginTransaction');
        $this->entityManager->expects(self::once())->method('persist');
        $this->entityManager->expects(self::once())->method('flush');
        $this->entityManager->expects(self::once())->method('commit');
        $this->entityManager->expects(self::never())->method('rollback');

        $commande = $this->commandeService->creerCommande($this->creerClient(), $lignes);

        self::assertCount(2, $commande->getLignes());
        self::assertSame('9.80', $commande->getMontantTotal());
        self::assertSame(StatutCommande::EN_ATTENTE, $commande->getStatut());
        self::assertMatchesRegularExpression('/^TNB-\d{8}-[0-9A-F]{4}$/', (string) $commande->getReference());
    }

    public function testCreerCommandeFigeLePrixDuProduit(): void
    {
        $produit = $this->creerProduit('Fraises Gariguette', 5.90);

        $commande = $this->commandeService->creerCommande(
            $this->creerClient(),
            [new LignePanier($produit, 2.0)]
        );

        // Le tarif evolue apres la commande : la ligne ne bouge pas.
        $produit->setPrix(7.50);

        /** @var LigneCommande $ligne */
        $ligne = $commande->getLignes()->first();

        self::assertSame(5.90, $ligne->getPrixUnitaireFloat());
        self::assertSame(11.8, $ligne->getSousTotalFloat());
    }

    public function testCreerCommandeDecrementeChaqueLigne(): void
    {
        $tomates = $this->creerProduit('Tomates grappe', 2.50);
        $carottes = $this->creerProduit('Carottes', 1.80);

        $decrements = [];
        $this->stockService
            ->method('decrementer')
            ->willReturnCallback(function (Produit $produit, float $quantite) use (&$decrements): Stock {
                $decrements[(string) $produit->getNom()] = $quantite;

                return $produit->getStock() ?? new Stock();
            });

        $this->commandeService->creerCommande($this->creerClient(), [
            new LignePanier($tomates, 2.0),
            new LignePanier($carottes, 3.0),
        ]);

        self::assertSame(['Tomates grappe' => 2.0, 'Carottes' => 3.0], $decrements);
    }

    public function testCreerCommandeRefuseUnPanierVide(): void
    {
        $this->entityManager->expects(self::never())->method('beginTransaction');

        $this->expectException(PanierVideException::class);

        $this->commandeService->creerCommande($this->creerClient(), []);
    }

    /**
     * Le controle prealable echoue avant meme l'ouverture de la transaction :
     * aucune ecriture n'est tentee.
     */
    public function testCreerCommandeEchoueSiUneLigneDepasseLeStock(): void
    {
        $produit = $this->creerProduit('Poires Conference', 3.10, 1.0);

        $this->stockService
            ->method('verifierDisponibilite')
            ->willThrowException(new StockInsuffisantException($produit, 5.0, 1.0));

        $this->entityManager->expects(self::never())->method('beginTransaction');
        $this->entityManager->expects(self::never())->method('flush');

        $this->expectException(StockInsuffisantException::class);

        $this->commandeService->creerCommande($this->creerClient(), [new LignePanier($produit, 5.0)]);
    }

    /**
     * Si le stock s'epuise entre le controle et l'ecriture, la transaction
     * est annulee : la commande n'est pas enregistree a moitie.
     */
    public function testUnEchecPendantLeDecrementAnnuleLaTransaction(): void
    {
        $produit = $this->creerProduit('Raisin Muscat', 4.50);

        $this->stockService
            ->method('decrementer')
            ->willThrowException(new StockInsuffisantException($produit, 2.0, 0.5));

        $this->entityManager->expects(self::once())->method('beginTransaction');
        $this->entityManager->expects(self::once())->method('rollback');
        $this->entityManager->expects(self::never())->method('commit');

        $this->expectException(StockInsuffisantException::class);

        $this->commandeService->creerCommande($this->creerClient(), [new LignePanier($produit, 2.0)]);
    }

    // ----- changerStatut -----

    public function testChangerStatutSuitLeWorkflow(): void
    {
        $commande = (new Commande())->setStatut(StatutCommande::EN_ATTENTE);

        $this->entityManager->expects(self::once())->method('commit');

        $this->commandeService->changerStatut($commande, StatutCommande::PREPAREE);

        self::assertSame(StatutCommande::PREPAREE, $commande->getStatut());
    }

    public function testChangerStatutRejetteUneTransitionInvalide(): void
    {
        $commande = (new Commande())->setStatut(StatutCommande::EN_ATTENTE);

        $this->entityManager->expects(self::never())->method('beginTransaction');
        $this->entityManager->expects(self::never())->method('flush');

        try {
            $this->commandeService->changerStatut($commande, StatutCommande::RECUPEREE);
            self::fail('Une TransitionStatutInvalideException etait attendue.');
        } catch (TransitionStatutInvalideException $exception) {
            self::assertSame(StatutCommande::EN_ATTENTE, $exception->getDepuis());
            self::assertSame(StatutCommande::RECUPEREE, $exception->getVers());
            self::assertStringContainsString('Preparee', $exception->messageUtilisateur());
        }

        // Le statut d'origine est preserve.
        self::assertSame(StatutCommande::EN_ATTENTE, $commande->getStatut());
    }

    public function testAnnulerRestitueLeStockDeChaqueLigne(): void
    {
        $tomates = $this->creerProduit('Tomates grappe', 2.50);

        $commande = (new Commande())->setStatut(StatutCommande::PREPAREE);
        $commande->addLigne(
            (new LigneCommande())
                ->setProduit($tomates)
                ->setPrixUnitaire(2.50)
                ->setQuantite(3.0)
        );

        $this->stockService
            ->expects(self::once())
            ->method('restituer')
            ->with($tomates, 3.0);

        $this->commandeService->changerStatut($commande, StatutCommande::ANNULEE);

        self::assertSame(StatutCommande::ANNULEE, $commande->getStatut());
    }

    public function testUnPassageAPrepareeNeToucheAuStock(): void
    {
        $commande = (new Commande())->setStatut(StatutCommande::EN_ATTENTE);
        $commande->addLigne(
            (new LigneCommande())
                ->setProduit($this->creerProduit('Carottes', 1.80))
                ->setPrixUnitaire(1.80)
                ->setQuantite(2.0)
        );

        $this->stockService->expects(self::never())->method('restituer');

        $this->commandeService->changerStatut($commande, StatutCommande::PREPAREE);
    }

    // ----- annulerParClient -----

    public function testUnClientNePeutAnnulerQueSesPropresCommandes(): void
    {
        $commande = (new Commande())
            ->setUtilisateur($this->creerClient())
            ->setStatut(StatutCommande::EN_ATTENTE);

        $autreClient = (new Utilisateur())
            ->setEmail('intrus@example.fr')
            ->setPrenom('Marc')
            ->setNom('Dubois')
            ->setMotDePasse('hache');

        $this->expectException(\LogicException::class);

        $this->commandeService->annulerParClient($commande, $autreClient);
    }

    public function testAnnulerParClientFonctionneSurSaPropreCommande(): void
    {
        $client = $this->creerClient();
        $commande = (new Commande())
            ->setUtilisateur($client)
            ->setStatut(StatutCommande::EN_ATTENTE);

        $this->commandeService->annulerParClient($commande, $client);

        self::assertSame(StatutCommande::ANNULEE, $commande->getStatut());
    }

    // ----- genererReference -----

    public function testLaReferenceTientDansLaColonneVarchar20(): void
    {
        $reference = $this->commandeService->genererReference();

        self::assertLessThanOrEqual(20, \strlen($reference));
    }

    public function testLaReferencePorteLaDateDeLaCommande(): void
    {
        $date = new \DateTimeImmutable('2026-06-15');

        self::assertStringStartsWith('TNB-20260615-', $this->commandeService->genererReference($date));
    }

    public function testLaReferenceEstRegenereeEnCasDeCollision(): void
    {
        $appels = 0;
        $repository = $this->createMock(CommandeRepository::class);
        $repository->method('referenceExiste')->willReturnCallback(
            static function () use (&$appels): bool {
                return 1 === ++$appels;
            }
        );

        $service = new CommandeService($this->entityManager, $repository, $this->stockService);
        $service->genererReference();

        self::assertSame(2, $appels, 'La reference doit etre retiree tant qu\'elle est deja prise.');
    }
}
