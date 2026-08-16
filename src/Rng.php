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

        if ($max >= 0) {
            foreach (self::decadeBuckets(max($min, 0), $max) as $bucket) {
                $buckets[] = $bucket;
            }
        }

        if ($min < 0) {
            $absLow = $max < 0 ? -$max : 1;
            $absHigh = -$min;
            foreach (self::decadeBuckets($absLow, $absHigh) as [$low, $high]) {
                $buckets[] = [-$high, -$low];
            }
        }

        return $buckets;
    }

    /**
     * Rozdělí [$low, $high] (obě strany >= 0) na dekádové koše [0,9], [10,99], [100,999], ...
     * Poslední koš — typicky useknutý hranicí rozsahu (např. max=1000 dá koš jen s jedinou
     * hodnotou 1000) — se sloučí s předchozím, pokud je nepřiměřeně malý (< 1/10 jeho
     * velikosti). Bez toho by takový téměř prázdný koš dostal STEJNOU 1/N váhu jako koš
     * s stovkami hodnot, a jeho jediná (nebo pár) hodnota by se losovala silně nadprůměrně
     * často — přesně tenhle bug způsoboval, že se v příkladech s rozsahem do 1000
     * neúměrně často objevovalo právě číslo 1000 (a "1000 - 1000" apod.).
     *
     * @return list<array{0:int,1:int}>
     */
    private static function decadeBuckets(int $low, int $high): array
    {
        $buckets = [];
        for ($decadeLow = 0, $decadeHigh = 9; $decadeLow <= $high; $decadeLow = $decadeHigh + 1, $decadeHigh = $decadeHigh * 10 + 9) {
            $l = max($low, $decadeLow);
            $h = min($high, $decadeHigh);
            if ($l <= $h) {
                $buckets[] = [$l, $h];
            }
        }

        $count = count($buckets);
        if ($count >= 2) {
            $lastSize = $buckets[$count - 1][1] - $buckets[$count - 1][0] + 1;
            $prevSize = $buckets[$count - 2][1] - $buckets[$count - 2][0] + 1;
            if ($lastSize * 10 < $prevSize) {
                $buckets[$count - 2][1] = $buckets[$count - 1][1];
                array_pop($buckets);
            }
        }

        return $buckets;
    }
}
