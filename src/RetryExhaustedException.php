<?php

declare(strict_types=1);

namespace Priklady;

/**
 * Interní řídicí výjimka: aktuální uzel (nebo celý příklad) se nepodařilo sestavit
 * v rámci povoleného počtu pokusů. Zachytává ji rodič ve stromu (nebo dávkový cyklus)
 * a zkusí to znovu jinak.
 */
final class RetryExhaustedException extends \RuntimeException
{
}
