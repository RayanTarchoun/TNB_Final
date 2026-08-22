<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Levee lorsqu'une validation de commande est tentee sans aucun article.
 *
 * Garantit l'invariant du MCD : une commande comporte au moins une ligne
 * (association "Composer", cardinalite 1,N).
 */
class PanierVideException extends \RuntimeException
{
    public function __construct(string $message = 'Votre panier est vide.')
    {
        parent::__construct($message);
    }
}
