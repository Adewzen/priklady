<?php

declare(strict_types=1);

namespace Priklady;

final class GeneratorConfig
{
    /** @param Operator[] $operators */
    public function __construct(
        public readonly int $count,
        public readonly int $operationsCount,
        public readonly float $min,
        public readonly float $max,
        public readonly array $operators,
        public readonly bool $allowDecimals,
        public readonly int $decimalPlaces,
        public readonly bool $allowNegative,
        public readonly bool $allowParentheses,
        public readonly bool $allowOperatorPriority,
        public readonly bool $showResults,
        public readonly int $seed,
        /** 0 = žádný bias (čistě náhodné), 100 = maximální snaha vyhnout se "a + (-b)" / "a - (-b)". */
        public readonly int $doubleNegativeBiasPercent = 70,
        /** Kolikrát smí "-1" vystupovat jako činitel/dělitel v jednom příkladu (víc -1 za sebou se navzájem ruší — triviální). */
        public readonly int $maxNegativeOneFactors = 1,
    ) {
        if ($operators === []) {
            throw new \InvalidArgumentException('Musí být povolen alespoň jeden operátor.');
        }
        if ($count < 1) {
            throw new \InvalidArgumentException('Počet příkladů musí být alespoň 1.');
        }
        if ($operationsCount < 1) {
            throw new \InvalidArgumentException('Počet operací musí být alespoň 1.');
        }
        if ($min > $max) {
            throw new \InvalidArgumentException('Minimum nesmí být větší než maximum.');
        }
        if ($decimalPlaces < 0 || $decimalPlaces > 2) {
            throw new \InvalidArgumentException('Počet desetinných míst musí být 0 až 2.');
        }
        if ($doubleNegativeBiasPercent < 0 || $doubleNegativeBiasPercent > 100) {
            throw new \InvalidArgumentException('Míra biasu proti dvojitým znaménkům musí být 0 až 100.');
        }
        if ($maxNegativeOneFactors < 0) {
            throw new \InvalidArgumentException('Maximální počet -1 jako činitele nesmí být záporný.');
        }
    }

    /** Škálovací faktor pro převod na celá čísla (10^desetinná místa), 1 pokud jsou desetinná čísla vypnutá. */
    public function scale(): int
    {
        return $this->allowDecimals ? (10 ** $this->decimalPlaces) : 1;
    }

    public function scaledMin(): int
    {
        $floor = $this->allowNegative ? $this->min : max($this->min, 0.0);
        return (int) round($floor * $this->scale());
    }

    public function scaledMax(): int
    {
        return (int) round($this->max * $this->scale());
    }

    /** Jsou mezi povolenými operátory zastoupené obě třídy priority (sčítání/odčítání i násobení/dělení)? */
    public function usesMultiplePrecedenceClasses(): bool
    {
        $classes = [];
        foreach ($this->operators as $operator) {
            $classes[$operator->precedence()] = true;
        }
        return count($classes) > 1;
    }
}
