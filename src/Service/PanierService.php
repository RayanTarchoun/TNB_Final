<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Produit;
use App\Exception\StockInsuffisantException;
use App\Model\LignePanier;
use App\Repository\ProduitRepository;
use Symfony\Component\HttpFoundation\Exception\SessionNotFoundException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Gestion du panier, stocke en session (CDCF 3.3.4).
 *
 * Seuls les identifiants et les quantites sont conserves en session : les
 * produits sont rehydrates depuis la base a chaque lecture, de sorte que le
 * prix et le stock affiches sont toujours les valeurs courantes.
 */
class PanierService
{
    private const CLE_SESSION = 'panier';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ProduitRepository $produitRepository,
        private readonly StockService $stockService,
    ) {
    }

    /**
     * Ajoute une quantite au panier et renvoie la nouvelle quantite cumulee.
     *
     * @throws StockInsuffisantException si le cumul depasse le stock restant
     */
    public function ajouter(Produit $produit, float $quantite = 1.0): float
    {
        $quantite = $this->normaliser($quantite);

        if ($quantite <= 0) {
            throw new \InvalidArgumentException('La quantite ajoutee doit etre strictement positive.');
        }

        $lignes = $this->lignesBrutes();
        $identifiant = (int) $produit->getId();
        $nouvelleQuantite = $this->normaliser(($lignes[$identifiant] ?? 0.0) + $quantite);

        $this->stockService->verifierDisponibilite($produit, $nouvelleQuantite);

        $lignes[$identifiant] = $nouvelleQuantite;
        $this->enregistrer($lignes);

        return $nouvelleQuantite;
    }

    /**
     * Fixe la quantite d'un produit. Une quantite nulle retire la ligne.
     *
     * @throws StockInsuffisantException
     */
    public function definirQuantite(Produit $produit, float $quantite): void
    {
        $quantite = $this->normaliser($quantite);
        $identifiant = (int) $produit->getId();
        $lignes = $this->lignesBrutes();

        if ($quantite <= 0) {
            unset($lignes[$identifiant]);
            $this->enregistrer($lignes);

            return;
        }

        $this->stockService->verifierDisponibilite($produit, $quantite);

        $lignes[$identifiant] = $quantite;
        $this->enregistrer($lignes);
    }

    public function supprimer(int $produitId): void
    {
        $lignes = $this->lignesBrutes();
        unset($lignes[$produitId]);
        $this->enregistrer($lignes);
    }

    public function vider(): void
    {
        $this->session()->remove(self::CLE_SESSION);
    }

    /**
     * Contenu du panier, produits rehydrates depuis la base.
     *
     * Les produits supprimes ou desactives entre-temps sont retires
     * silencieusement du panier.
     *
     * @return list<LignePanier>
     */
    public function getContenu(): array
    {
        $lignes = $this->lignesBrutes();

        if ([] === $lignes) {
            return [];
        }

        $produits = $this->produitRepository->findBy(['id' => array_keys($lignes)]);

        /** @var array<int, Produit> $indexParId */
        $indexParId = [];
        foreach ($produits as $produit) {
            $indexParId[(int) $produit->getId()] = $produit;
        }

        $contenu = [];
        $lignesValides = [];

        foreach ($lignes as $identifiant => $quantite) {
            $produit = $indexParId[$identifiant] ?? null;

            if (null === $produit || !$produit->isDisponible()) {
                continue;
            }

            $contenu[] = new LignePanier($produit, (float) $quantite);
            $lignesValides[$identifiant] = (float) $quantite;
        }

        if (\count($lignesValides) !== \count($lignes)) {
            $this->enregistrer($lignesValides);
        }

        return $contenu;
    }

    public function getTotal(): float
    {
        $total = 0.0;
        foreach ($this->getContenu() as $ligne) {
            $total += $ligne->getSousTotal();
        }

        return round($total, 2);
    }

    /**
     * Nombre de lignes distinctes : c'est la valeur du badge du header.
     */
    public function getNombreArticles(): int
    {
        return \count($this->lignesBrutes());
    }

    public function estVide(): bool
    {
        return [] === $this->lignesBrutes();
    }

    public function contient(Produit $produit): bool
    {
        return \array_key_exists((int) $produit->getId(), $this->lignesBrutes());
    }

    public function getQuantite(Produit $produit): float
    {
        return (float) ($this->lignesBrutes()[(int) $produit->getId()] ?? 0.0);
    }

    /**
     * Lignes dont la quantite n'est plus couverte par le stock.
     *
     * Permet d'alerter le client sur la page panier avant meme qu'il ne
     * tente de valider (parcours utilisateur Jalon 2, 5.2 etape 3).
     *
     * @return list<LignePanier>
     */
    public function lignesIndisponibles(): array
    {
        return array_values(array_filter(
            $this->getContenu(),
            static fn (LignePanier $ligne): bool => !$ligne->estDisponible()
        ));
    }

    /**
     * Aligne chaque ligne sur le stock encore disponible et renvoie les
     * produits qui ont ete ajustes ou retires.
     *
     * @return list<string> noms des produits ajustes
     */
    public function ajusterAuStock(): array
    {
        $ajustes = [];

        foreach ($this->lignesIndisponibles() as $ligne) {
            $disponible = $ligne->getQuantiteDisponible();
            $ajustes[] = (string) $ligne->produit->getNom();

            $lignes = $this->lignesBrutes();
            $identifiant = (int) $ligne->produit->getId();

            if ($disponible <= 0) {
                unset($lignes[$identifiant]);
            } else {
                $lignes[$identifiant] = $this->normaliser($disponible);
            }

            $this->enregistrer($lignes);
        }

        return $ajustes;
    }

    // ----- Acces bas niveau a la session -----

    private function session(): SessionInterface
    {
        return $this->requestStack->getSession();
    }

    /**
     * @return array<int, float> identifiant produit => quantite
     */
    private function lignesBrutes(): array
    {
        try {
            /** @var array<int, float> $lignes */
            $lignes = $this->session()->get(self::CLE_SESSION, []);
        } catch (SessionNotFoundException) {
            // Hors requete HTTP (console) ou sur une page d'erreur rendue
            // sans session : le panier est simplement considere vide.
            return [];
        }

        return $lignes;
    }

    /**
     * @param array<int, float> $lignes
     */
    private function enregistrer(array $lignes): void
    {
        if ([] === $lignes) {
            $this->session()->remove(self::CLE_SESSION);

            return;
        }

        $this->session()->set(self::CLE_SESSION, $lignes);
    }

    /**
     * Les quantites sont arrondies au centieme, comme les DECIMAL(10,2)
     * de la base : le panier et la commande manipulent les memes valeurs.
     */
    private function normaliser(float $quantite): float
    {
        return round($quantite, 2);
    }
}
