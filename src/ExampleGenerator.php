<?php

declare(strict_types=1);

namespace Priklady;

/**
 * Generuje aritmetické příklady rekurzí "od výsledku": zvolí se cílová hodnota uzlu,
 * k ní se najde dvojice operandů pro daný operátor, a rekurzivně se opakuje na levém
 * a pravém operandu, dokud se nevyčerpá rozpočet operací.
 *
 * Podrobný popis algoritmu a rozhodnutí viz DESIGN.md v kořeni projektu.
 */
final class ExampleGenerator
{
    /** Kolikrát na jedné úrovni zkusit jiný operátor/rozpad/operandy, než se selhání pošle o úroveň výš. */
    private const int MAX_NODE_RETRIES = 10;

    /** Celkový časový rozpočet na vygenerování celé dávky příkladů. */
    private const float MAX_BATCH_SECONDS = 2.0;

    private readonly int $scale;
    private readonly int $scaledMin;
    private readonly int $scaledMax;
    private readonly Serializer $serializer;

    public function __construct(
        private readonly GeneratorConfig $config,
        private readonly Rng $rng,
    ) {
        $this->scale = $config->scale();
        $this->scaledMin = $config->scaledMin();
        $this->scaledMax = $config->scaledMax();
        $this->serializer = new Serializer($config);
    }

    /** @return BinaryOp[] */
    public function generateBatch(): array
    {
        $deadline = microtime(true) + self::MAX_BATCH_SECONDS;
        $examples = [];

        while (count($examples) < $this->config->count) {
            if (microtime(true) > $deadline) {
                throw new GenerationFailedException(sprintf(
                    'Nepodařilo se vygenerovat všech %d příkladů v časovém limitu (vygenerováno %d). '
                        . 'Zkuste zadání zjednodušit — širší rozsah čísel, méně operací na příklad, '
                        . 'nebo povolte více jevů (záporná čísla, závorky).',
                    $this->config->count,
                    count($examples),
                ));
            }

            try {
                $examples[] = $this->generateOne();
            } catch (RetryExhaustedException) {
                continue; // celý příklad se nepovedl, zkusíme nový od úplného začátku
            }
        }

        return $examples;
    }

    private function generateOne(): BinaryOp
    {
        $target = $this->randomValue($this->scaledMin, $this->scaledMax, allowZero: true);
        $root = $this->build($target, $this->config->operationsCount);
        \assert($root instanceof BinaryOp);

        if (!$this->config->allowParentheses && $this->serializer->needsParenthesesSomewhere($root)) {
            throw new RetryExhaustedException('Strom vyžaduje závorky, které nejsou v zadání povolené.');
        }

        return $root;
    }

    private function build(int $target, int $opsBudget): Node
    {
        if ($opsBudget === 0) {
            return new Literal($target);
        }

        for ($attempt = 0; $attempt < self::MAX_NODE_RETRIES; $attempt++) {
            $operator = $this->config->operators[$this->rng->int(0, count($this->config->operators) - 1)];
            $remaining = $opsBudget - 1;
            $leftBudget = $this->rng->int(0, $remaining);
            $rightBudget = $remaining - $leftBudget;

            try {
                [$a, $b] = $this->pickOperands($operator, $target, $leftBudget === 0, $rightBudget === 0);
                $left = $this->build($a, $leftBudget);
                $right = $this->build($b, $rightBudget);

                return new BinaryOp($operator, $left, $right, $this->scale);
            } catch (RetryExhaustedException) {
                continue;
            }
        }

        throw new RetryExhaustedException("Nelze rozložit hodnotu {$target} na {$opsBudget} operací.");
    }

    /** @return array{0:int,1:int} */
    private function pickOperands(Operator $operator, int $target, bool $leftIsLeaf, bool $rightIsLeaf): array
    {
        return match ($operator) {
            Operator::Add => $this->pickAddOperands($target, $leftIsLeaf, $rightIsLeaf),
            Operator::Sub => $this->pickSubOperands($target, $leftIsLeaf, $rightIsLeaf),
            Operator::Mul => $this->pickMulOperands($target, $leftIsLeaf, $rightIsLeaf),
            Operator::Div => $this->pickDivOperands($target, $leftIsLeaf, $rightIsLeaf),
        };
    }

    /** a + b = target */
    private function pickAddOperands(int $target, bool $leftIsLeaf, bool $rightIsLeaf): array
    {
        $lowA = max($this->scaledMin, $target - $this->scaledMax);
        $highA = min($this->scaledMax, $target - $this->scaledMin);
        if ($lowA > $highA) {
            throw new RetryExhaustedException("Součet {$target} nelze rozložit v daném rozsahu.");
        }

        // Preferuj a <= target (tedy b = target - a >= 0), ať se omezí ošklivé
        // zápisy typu "40 + (-19)". Pár posledních pokusů rozsah uvolní, ať
        // se to nezacyklí, když je nezáporné b v daném rozsahu nedosažitelné.
        $preferredHighA = min($highA, $target);
        $preferNonNegativeB = $this->config->allowNegative
            && $this->config->avoidDoubleNegative
            && $preferredHighA >= $lowA;

        for ($i = 0; $i < self::MAX_NODE_RETRIES; $i++) {
            $useFullRange = !$preferNonNegativeB || $i >= self::MAX_NODE_RETRIES - 3;
            $a = $useFullRange
                ? $this->rng->int($lowA, $highA)
                : $this->rng->int($lowA, $preferredHighA);
            $b = $target - $a;
            if ($leftIsLeaf && $a === 0) {
                continue;
            }
            if ($rightIsLeaf && $b === 0) {
                continue;
            }
            return [$a, $b];
        }

        throw new RetryExhaustedException("Nelze najít vhodné sčítance pro {$target}.");
    }

    /** a - b = target  =>  a = target + b */
    private function pickSubOperands(int $target, bool $leftIsLeaf, bool $rightIsLeaf): array
    {
        $lowB = max($this->scaledMin, $this->scaledMin - $target);
        $highB = min($this->scaledMax, $this->scaledMax - $target);
        if ($lowB > $highB) {
            throw new RetryExhaustedException("Rozdíl {$target} nelze rozložit v daném rozsahu.");
        }

        // Preferuj b >= 0, ať se omezí ošklivé zápisy typu "40 - (-19)".
        $preferredLowB = max($lowB, 0);
        $preferNonNegativeB = $this->config->allowNegative
            && $this->config->avoidDoubleNegative
            && $preferredLowB <= $highB;

        for ($i = 0; $i < self::MAX_NODE_RETRIES; $i++) {
            $useFullRange = !$preferNonNegativeB || $i >= self::MAX_NODE_RETRIES - 3;
            $b = $useFullRange
                ? $this->rng->int($lowB, $highB)
                : $this->rng->int($preferredLowB, $highB);
            $a = $target + $b;
            if ($leftIsLeaf && $a === 0) {
                continue;
            }
            if ($rightIsLeaf && $b === 0) {
                continue;
            }
            return [$a, $b];
        }

        throw new RetryExhaustedException("Nelze najít vhodného menšitele pro {$target}.");
    }

    /** a * b = target (ve škálovaných jednotkách: a*b = target*scale) */
    private function pickMulOperands(int $target, bool $leftIsLeaf, bool $rightIsLeaf): array
    {
        $scale = $this->scale;
        $product = $target * $scale;
        if ($product === 0) {
            throw new RetryExhaustedException('Součin s cílovým výsledkem 0 negenerujeme.');
        }

        $divisors = $this->divisorsOf(abs($product));
        $candidates = [];
        foreach ($divisors as $d) {
            $candidates[] = $d;
            if ($this->config->allowNegative) {
                $candidates[] = -$d;
            }
        }
        $candidates = $this->rng->shuffled($candidates);

        foreach ($candidates as $a) {
            if ($a === 0 || $product % $a !== 0) {
                continue;
            }
            $b = intdiv($product, $a);

            if ($a < $this->scaledMin || $a > $this->scaledMax) {
                continue;
            }
            if ($b < $this->scaledMin || $b > $this->scaledMax) {
                continue;
            }
            // "1" je jako činitel zakázané (aby úloha nebyla triviální), "-1" povolené.
            if ($a === $scale || $b === $scale) {
                continue;
            }
            if ($leftIsLeaf && $a === 0) {
                continue;
            }
            if ($rightIsLeaf && $b === 0) {
                continue;
            }
            // Nejvýš jeden z činitelů smí nést desetinná místa, ať se přesnost nekumuluje.
            if ($scale > 1 && ($a % $scale !== 0) && ($b % $scale !== 0)) {
                continue;
            }

            return [$a, $b];
        }

        throw new RetryExhaustedException("Součin {$target} nelze v daném rozsahu rozložit na dva činitele.");
    }

    /** a / b = target (ve škálovaných jednotkách: a = target*b/scale) */
    private function pickDivOperands(int $target, bool $leftIsLeaf, bool $rightIsLeaf): array
    {
        if ($target === 0) {
            throw new RetryExhaustedException('Podíl s cílovým výsledkem 0 zatím negenerujeme.');
        }

        $scale = $this->scale;

        for ($i = 0; $i < self::MAX_NODE_RETRIES; $i++) {
            $b = $this->rng->int($this->scaledMin, $this->scaledMax);
            if ($b === 0 || $b === $scale) {
                continue; // dělitel nesmí být 0 ani "1" (-1 povoleno)
            }

            $numerator = $target * $b;
            if ($numerator % $scale !== 0) {
                continue;
            }
            $a = intdiv($numerator, $scale);

            if ($a < $this->scaledMin || $a > $this->scaledMax) {
                continue;
            }
            if ($leftIsLeaf && $a === 0) {
                continue;
            }
            if ($scale > 1 && ($a % $scale !== 0) && ($b % $scale !== 0)) {
                continue;
            }

            return [$a, $b];
        }

        throw new RetryExhaustedException("Podíl {$target} nelze v daném rozsahu rozložit.");
    }

    private function randomValue(int $min, int $max, bool $allowZero): int
    {
        for ($i = 0; $i < self::MAX_NODE_RETRIES; $i++) {
            $v = $this->rng->int($min, $max);
            if ($allowZero || $v !== 0) {
                return $v;
            }
        }

        throw new RetryExhaustedException('Nelze zvolit nenulovou počáteční hodnotu v daném rozsahu.');
    }

    /** @return int[] kladní dělitelé čísla n (n musí být > 0) */
    private function divisorsOf(int $n): array
    {
        $divisors = [];
        for ($i = 1; $i * $i <= $n; $i++) {
            if ($n % $i === 0) {
                $divisors[] = $i;
                $other = intdiv($n, $i);
                if ($other !== $i) {
                    $divisors[] = $other;
                }
            }
        }
        return $divisors;
    }
}
