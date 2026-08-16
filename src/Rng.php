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

    /**
     * Náhodné celé číslo z [$min, $max], ale místo čistě uniformního losování
     * (kde by u širokých rozsahů typu 0..1000 valná většina čísel vyšla trojciferná —
     * jen 1 % je jednociferných, 9 % dvouciferných, 90 % trojciferných) se nejdřív
     * rovnoměrně vylosuje "třída podle počtu cifer" a teprve uvnitř ní číslo.
     * Není to dokonalé (0 spadá do stejné třídy jako 1-9, kladná/záporná strana téže
     * třídy jsou dvě samostatné položky), ale výrazně to srovná zastoupení krátkých
     * čísel oproti čistě uniformnímu losování.
     */
    public function intBiasedByDigits(int $min, int $max): int
    {
        $buckets = self::digitBuckets($min, $max);
        [$low, $high] = $buckets[$this->int(0, count($buckets) - 1)];
        return $this->int($low, $high);
    }

    /**
     * @return list<array{0:int,1:int}> disjunktní [low,high] intervaly, jeden na
     * dosažitelnou dvojici (znaménko, počet cifer absolutní hodnoty) v rámci [min,max].
     */
    private static function digitBuckets(int $min, int $max): array
    {
        $buckets = [];
        $bound = max(abs($min), abs($max));

        // kladná strana + nula: [0,9], [10,99], [100,999], ...
        for ($decadeLow = 0, $decadeHigh = 9; $decadeLow <= $bound; $decadeLow = $decadeHigh + 1, $decadeHigh = $decadeHigh * 10 + 9) {
            $low = max($min, $decadeLow);
            $high = min($max, $decadeHigh);
            if ($low <= $high) {
                $buckets[] = [$low, $high];
            }
        }

        // záporná strana: [-9,-1], [-99,-10], [-999,-100], ...
        for ($decadeLow = 1, $decadeHigh = 9; $decadeLow <= $bound; $decadeLow = $decadeHigh + 1, $decadeHigh = $decadeHigh * 10 + 9) {
            $low = max($min, -$decadeHigh);
            $high = min($max, -$decadeLow);
            if ($low <= $high) {
                $buckets[] = [$low, $high];
            }
        }

        return $buckets;
    }
}
