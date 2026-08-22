<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Produit;
use App\Entity\Stock;
use App\Form\ProduitType;
use App\Repository\ProduitRepository;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * CRUD produits reserve a l'administrateur (CDCF 3.3.2).
 */
#[Route('/admin/produits')]
#[IsGranted('ROLE_ADMIN')]
class ProduitController extends AbstractController
{
    #[Route('', name: 'app_admin_produit_index', methods: ['GET'])]
    public function index(ProduitRepository $produitRepository): Response
    {
        return $this->render('admin/produit/index.html.twig', [
            'produits' => $produitRepository->findPourAdministration(),
        ]);
    }

    #[Route('/nouveau', name: 'app_admin_produit_nouveau', methods: ['GET', 'POST'])]
    public function nouveau(Request $request, ProduitRepository $produitRepository): Response
    {
        $produit = new Produit();

        // Tout produit possede un stock des sa creation : la relation 1:1 est
        // obligatoire des deux cotes dans le MCD.
        $produit->setStock(new Stock());

        $formulaire = $this->createForm(ProduitType::class, $produit);
        $formulaire->handleRequest($request);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            $produitRepository->save($produit);

            $this->addFlash('success', \sprintf('Le produit "%s" a ete cree.', $produit->getNom()));

            return $this->redirectToRoute('app_admin_stock_index');
        }

        return $this->render('admin/produit/form.html.twig', [
            'formulaire' => $formulaire,
            'produit' => $produit,
            'creation' => true,
        ]);
    }

    #[Route('/{id<\d+>}/modifier', name: 'app_admin_produit_modifier', methods: ['GET', 'POST'])]
    public function modifier(
        #[MapEntity(id: 'id')] Produit $produit,
        Request $request,
        ProduitRepository $produitRepository,
    ): Response {
        $formulaire = $this->createForm(ProduitType::class, $produit);
        $formulaire->handleRequest($request);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            $produitRepository->save($produit);

            $this->addFlash('success', \sprintf('Le produit "%s" a ete mis a jour.', $produit->getNom()));

            return $this->redirectToRoute('app_admin_produit_index');
        }

        return $this->render('admin/produit/form.html.twig', [
            'formulaire' => $formulaire,
            'produit' => $produit,
            'creation' => false,
        ]);
    }

    /**
     * Activation / desactivation rapide depuis la liste (CDCF 3.3.2).
     */
    #[Route('/{id<\d+>}/basculer', name: 'app_admin_produit_basculer', methods: ['POST'])]
    public function basculerDisponibilite(
        #[MapEntity(id: 'id')] Produit $produit,
        Request $request,
        ProduitRepository $produitRepository,
    ): Response {
        if ($this->isCsrfTokenValid('basculer_produit'.$produit->getId(), (string) $request->request->get('_token'))) {
            $produit->setDisponible(!$produit->isDisponible());
            $produitRepository->save($produit);

            $this->addFlash('success', \sprintf(
                'Le produit "%s" est desormais %s.',
                $produit->getNom(),
                $produit->isDisponible() ? 'actif' : 'desactive'
            ));
        }

        return $this->redirectToRoute('app_admin_produit_index');
    }

    #[Route('/{id<\d+>}/supprimer', name: 'app_admin_produit_supprimer', methods: ['POST'])]
    public function supprimer(
        #[MapEntity(id: 'id')] Produit $produit,
        Request $request,
        ProduitRepository $produitRepository,
    ): Response {
        if (!$this->isCsrfTokenValid('supprimer_produit'.$produit->getId(), (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_admin_produit_index');
        }

        $nom = (string) $produit->getNom();

        // Supprimer un produit deja commande effacerait l'historique et
        // violerait la cle etrangere de ligne_commande : on le desactive.
        if ($produitRepository->estReferenceParUneCommande($produit)) {
            $produit->setDisponible(false);
            $produitRepository->save($produit);

            $this->addFlash('warning', \sprintf(
                'Le produit "%s" apparait dans des commandes passees : il a ete desactive plutot que supprime.',
                $nom
            ));

            return $this->redirectToRoute('app_admin_produit_index');
        }

        $produitRepository->remove($produit);
        $this->addFlash('success', \sprintf('Le produit "%s" a ete supprime.', $nom));

        return $this->redirectToRoute('app_admin_produit_index');
    }
}
