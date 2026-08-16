<?php

declare(strict_types=1);

namespace Priklady;

/**
 * Tenký obal nad mt_rand/mt_srand. Pozor: mt_srand nastavuje GLOBÁLNÍ stav
 * generátoru náhodných čísel v PHP procesu — vytvoření instance Rng tedy
 * ovlivní i jakýkoliv jiný kód v tomtéž requestu, který by mt_rand používal.
 * Pro naše účely (jeden Rng na request) to nevadí.
 */
final class Rng
{
    public function __construct(int $seed)
    {
        mt_srand($seed);
    }

    /** Náhodné celé číslo z uzavřeného intervalu [$min, $max]. */
    public function int(int $min, int $max): int
    {
        return mt_rand($min, $max);
    }

    /** Fisher–Yates zamíchání pole pomocí tohoto (seedovaného) generátoru. */
    public function shuffled(array $items): array
    {
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = $this->int(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }
        return $items;
    }
}
