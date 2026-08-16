<?php

declare(strict_types=1);

namespace Priklady;

/**
 * Samostatný, jednoduchý generátor pro "malou násobilku" — jednotlivé násobilkové
 * a dělitelské fakty s činiteli 1-10 (tradiční rozsah malé násobilky v ČR).
 *
 * Nejde o speciální případ ExampleGenerator: ten používá JEDEN rozsah [min,max] jak
 * pro cílový výsledek, tak pro operandy (viz DESIGN.md — "rozsah platí pro každý
 * mezivýsledek"). Malá násobilka ale potřebuje činitele 1-10 a VÝSLEDEK klidně
 * až 100 (10×10) — dva různé rozsahy pro tutéž věc by vyžadovaly zásah do jádra
 * rekurzivního algoritmu kvůli jedné jednoduché, plochý funkci. Proto samostatná
 * třída, která znovupoužívá stejné Node/Operator/Rng/Serializer.
 */
final class SmallMultiplicationTableGenerator
{
    private const int MIN_FACTOR = 1;
    private const int MAX_FACTOR = 10;

    /** @param Operator[] $operators musí být podmnožina [Operator::Mul, Operator::Div] */
    public function __construct(
        private readonly int $count,
        private readonly array $operators,
        private readonly Rng $rng,
    ) {
        if ($operators === []) {
            throw new \InvalidArgumentException('Musí být povolené aspoň násobení, nebo dělení.');
        }
    }

    /** @return BinaryOp[] */
    public function generateBatch(): array
    {
        $examples = [];
        for ($i = 0; $i < $this->count; $i++) {
            $examples[] = $this->generateOne();
        }
        return $examples;
    }

    private function generateOne(): BinaryOp
    {
        $a = $this->rng->int(self::MIN_FACTOR, self::MAX_FACTOR);
        $b = $this->rng->int(self::MIN_FACTOR, self::MAX_FACTOR);
        $operator = $this->operators[$this->rng->int(0, count($this->operators) - 1)];

        if ($operator === Operator::Mul) {
            return new BinaryOp(Operator::Mul, new Literal($a), new Literal($b), scale: 1);
        }

        // Dělení je přesně inverzní fakt: a*b je dělenec, b dělitel, výsledek je a.
        $dividend = $a * $b;
        return new BinaryOp(Operator::Div, new Literal($dividend), new Literal($b), scale: 1);
    }
}
