<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Commande;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Emails transactionnels lies au cycle de vie d'une commande
 * (parcours utilisateur Jalon 2, 5.2 : "message vert + email de confirmation").
 *
 * Un echec d'envoi ne doit jamais faire echouer la commande elle-meme :
 * les erreurs de transport sont journalisees, pas propagees.
 */
class NotificationCommandeService
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly string $mailExpediteur,
        private readonly string $mailExpediteurNom,
    ) {
    }

    public function confirmationCommande(Commande $commande): void
    {
        $this->envoyer(
            $commande,
            \sprintf('Votre commande %s est enregistree', $commande->getReference()),
            'email/commande_confirmation.html.twig'
        );
    }

    public function changementDeStatut(Commande $commande): void
    {
        $this->envoyer(
            $commande,
            \sprintf(
                'Commande %s : %s',
                $commande->getReference(),
                $commande->getStatut()->libelle()
            ),
            'email/commande_statut.html.twig'
        );
    }

    private function envoyer(Commande $commande, string $sujet, string $template): void
    {
        $destinataire = $commande->getUtilisateur()?->getEmail();

        if (null === $destinataire) {
            return;
        }

        $email = (new TemplatedEmail())
            ->from(new Address($this->mailExpediteur, $this->mailExpediteurNom))
            ->to($destinataire)
            ->subject($sujet)
            ->htmlTemplate($template)
            ->context(['commande' => $commande]);

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error("Echec de l'envoi de l'email de commande {reference} : {message}", [
                'reference' => $commande->getReference(),
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);
        }
    }
}
