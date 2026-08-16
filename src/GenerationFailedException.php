<?php

declare(strict_types=1);

namespace Priklady;

/**
 * Vyhozeno, když se nepodaří vygenerovat požadovaný počet příkladů v časovém limitu.
 * Volající (index.php) ji zachytí a zobrazí uživateli chybovou hlášku.
 */
final class GenerationFailedException extends \RuntimeException
{
}
