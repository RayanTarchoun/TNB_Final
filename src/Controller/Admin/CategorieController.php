<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Categorie;
use App\Form\CategorieType;
use App\Repository\CategorieRepository;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestion des categories de produits (fruit, legume...).
 */
#[Route('/admin/categories')]
#[IsGranted('ROLE_ADMIN')]
class CategorieController extends AbstractController
{
    #[Route('', name: 'app_admin_categorie_index', methods: ['GET', 'POST'])]
    public function index(Request $request, CategorieRepository $categorieRepository): Response
    {
        // Le formulaire de creation est integre a la liste : les categories
        // sont peu nombreuses et rarement modifiees.
        $categorie = new Categorie();
        $formulaire = $this->createForm(CategorieType::class, $categorie);
        $formulaire->handleRequest($request);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            $categorieRepository->save($categorie);

            $this->addFlash('success', \sprintf('La categorie "%s" a ete creee.', $categorie->getNom()));

            return $this->redirectToRoute('app_admin_categorie_index');
        }

        return $this->render('admin/categorie/index.html.twig', [
            'categories' => $categorieRepository->avecNombreDeProduits(),
            'formulaire' => $formulaire,
        ]);
    }

    #[Route('/{id<\d+>}/modifier', name: 'app_admin_categorie_modifier', methods: ['GET', 'POST'])]
    public function modifier(
        #[MapEntity(id: 'id')] Categorie $categorie,
        Request $request,
        CategorieRepository $categorieRepository,
    ): Response {
        $formulaire = $this->createForm(CategorieType::class, $categorie);
        $formulaire->handleRequest($request);

        if ($formulaire->isSubmitted() && $formulaire->isValid()) {
            $categorieRepository->save($categorie);

            $this->addFlash('success', \sprintf('La categorie "%s" a ete mise a jour.', $categorie->getNom()));

            return $this->redirectToRoute('app_admin_categorie_index');
        }

        return $this->render('admin/categorie/form.html.twig', [
            'formulaire' => $formulaire,
            'categorie' => $categorie,
        ]);
    }
}
