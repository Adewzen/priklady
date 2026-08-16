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
        public readonly bool $avoidDoubleNegative = true,
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
