<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Utilisateur;
use App\Repository\UtilisateurRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Cree ou promeut le compte administrateur du back-office.
 *
 * C'est la commande a utiliser lors d'une premiere mise en production :
 * charger les fixtures purgerait la base, ce qui est acceptable en
 * developpement mais destructeur sur un serveur reel.
 *
 *   php bin/console app:creer-administrateur gerant@tarchoun.fr
 */
#[AsCommand(
    name: 'app:creer-administrateur',
    description: 'Cree un compte administrateur, ou promeut un compte existant.',
)]
class CreerAdministrateurCommand extends Command
{
    public function __construct(
        private readonly UtilisateurRepository $utilisateurRepository,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ValidatorInterface $validateur,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, "Adresse email de l'administrateur")
            ->addArgument('prenom', InputArgument::OPTIONAL, 'Prenom', 'Administrateur')
            ->addArgument('nom', InputArgument::OPTIONAL, 'Nom', 'TNB')
            ->addOption(
                'mot-de-passe',
                'p',
                InputOption::VALUE_REQUIRED,
                'Mot de passe. Omettez-le pour une saisie masquee, plus sure : '
                .'un mot de passe passe en argument reste dans l\'historique du shell.'
            )
            ->setHelp(<<<'AIDE'
                Cree le compte administrateur du back-office.

                Si l'adresse existe deja, le compte est promu au role
                administrateur et son mot de passe est reinitialise.

                  <info>php bin/console app:creer-administrateur gerant@tarchoun.fr</info>
                  <info>php bin/console app:creer-administrateur gerant@tarchoun.fr Hamadi Tarchoun</info>
                AIDE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $console = new SymfonyStyle($input, $output);

        $email = mb_strtolower(trim((string) $input->getArgument('email')));
        $motDePasse = $input->getOption('mot-de-passe');

        if (null === $motDePasse) {
            if (!$input->isInteractive()) {
                $console->error('Fournissez --mot-de-passe en mode non interactif.');

                return Command::INVALID;
            }

            // Le repli visible est conserve (comportement par defaut de
            // Symfony) : sur un terminal incapable de masquer la saisie, il
            // vaut mieux afficher le mot de passe qu'interrompre un
            // deploiement en cours.
            $question = (new Question("Mot de passe de l'administrateur : "))
                ->setHidden(true)
                ->setHiddenFallback(true);

            $motDePasse = (string) $console->askQuestion($question);
        }

        if (\strlen($motDePasse) < 8 || !preg_match('/^(?=.*[A-Za-z])(?=.*\d).+$/', $motDePasse)) {
            $console->error('Le mot de passe doit contenir au moins 8 caracteres, dont une lettre et un chiffre.');

            return Command::INVALID;
        }

        $utilisateur = $this->utilisateurRepository->findOneByEmail($email);
        $creation = null === $utilisateur;

        if ($creation) {
            $utilisateur = (new Utilisateur())
                ->setEmail($email)
                ->setPrenom((string) $input->getArgument('prenom'))
                ->setNom((string) $input->getArgument('nom'));
        }

        $utilisateur
            ->setRole(Utilisateur::ROLE_ADMIN)
            ->setActif(true)
            ->setMotDePasse($this->hasher->hashPassword($utilisateur, $motDePasse));

        $erreurs = $this->validateur->validate($utilisateur);

        if (\count($erreurs) > 0) {
            $console->error('Le compte est invalide :');
            foreach ($erreurs as $erreur) {
                $console->writeln(\sprintf('  - %s : %s', $erreur->getPropertyPath(), $erreur->getMessage()));
            }

            return Command::INVALID;
        }

        $this->utilisateurRepository->save($utilisateur);

        $console->success($creation
            ? \sprintf('Administrateur "%s" cree.', $email)
            : \sprintf('Le compte "%s" a ete promu administrateur et son mot de passe reinitialise.', $email));

        $console->writeln('  Connexion : <info>/connexion</info>, puis back-office sur <info>/admin</info>.');

        return Command::SUCCESS;
    }
}
