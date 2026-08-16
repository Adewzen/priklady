<?php

declare(strict_types=1);

namespace Priklady;

final class BinaryOp implements Node
{
    private readonly int $scaledValue;

    public function __construct(
        public readonly Operator $operator,
        public readonly Node $left,
        public readonly Node $right,
        int $scale,
    ) {
        $l = $left->scaledValue();
        $r = $right->scaledValue();

        $this->scaledValue = match ($operator) {
            Operator::Add => $l + $r,
            Operator::Sub => $l - $r,
            // Násobení dvou "škálovaných" hodnot přináší navíc jeden faktor $scale navíc, dělíme ho pryč.
            Operator::Mul => self::exactDiv($l * $r, $scale),
            // Podíl škálovaných hodnot je bezrozměrný poměr, škálu je třeba vrátit zpět vynásobením.
            Operator::Div => self::exactDiv($l * $scale, $r),
        };
    }

    public function scaledValue(): int
    {
        return $this->scaledValue;
    }

    /**
     * Dělení musí vyjít přesně — operandy jsou voleny generátorem tak, aby to platilo.
     * Pokud ne, je to chyba v algoritmu volby operandů, ne běžný stav pro retry.
     */
    private static function exactDiv(int $numerator, int $denominator): int
    {
        $result = intdiv($numerator, $denominator);
        if ($result * $denominator !== $numerator) {
            throw new \LogicException('Neceločíselný mezivýsledek — chyba v generátoru operandů.');
        }
        return $result;
    }
}
