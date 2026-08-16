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

    /**
     * Kolikrát se v RÁMCI PRÁVĚ STAVĚNÉHO příkladu už použilo "-1" jako činitel/dělitel
     * (viz pickMulOperands/pickDivOperands). Resetuje se na začátku generateOne(),
     * zvyšuje/vrací zpět v build() (viz komentář tam — musí se to dít hned po výběru
     * operandů, ne až po úspěšném sestavení celého podstromu). Pozn.: pokud se celý
     * tenhle uzel později zahodí kvůli selhání sourozence výš ve stromu, čítač se
     * nevrací zpět — důsledek je jen o něco konzervativnější chování, nikdy chybný výstup.
     */
    private int $negativeOneFactorsUsed = 0;

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
        $this->negativeOneFactorsUsed = 0;
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

        // Operátory se zkouší v jednou vylosovaném váženém pořadí (bez opakování),
        // KAŽDÝ dostane plnou sadu MAX_NODE_RETRIES pokusů (různé rozdělení rozpočtu),
        // než se přejde na dalšího v pořadí. Nejde losovat operátor znovu nezávisle
        // při každém pokusu (jak to dělal starší kód) — pokud je pro daný cíl jeden
        // operátor strukturálně neřešitelný (např. dělení bez záporných čísel u cíle
        // většího než polovina maxima), takové opakované losování by mu dávalo šanci
        // "vyhrát" znovu a znovu, zatímco by fakticky ubíral šance ostatním — a
        // realizovaný poměr operátorů by se pak neshodoval s nastavenými vahami.
        foreach ($this->weightedOperatorOrder() as $operator) {
            for ($attempt = 0; $attempt < self::MAX_NODE_RETRIES; $attempt++) {
                $remaining = $opsBudget - 1;
                $leftBudget = $this->rng->int(0, $remaining);
                $rightBudget = $remaining - $leftBudget;

                try {
                    [$a, $b] = $this->pickOperands($operator, $target, $leftBudget === 0, $rightBudget === 0);

                    // Počítadlo se musí zvýšit HNED (ne až po úspěšném sestavení potomků),
                    // jinak by uzel postavený "uvnitř" levé/pravé větve nevěděl, že tenhle
                    // uzel už -1 použil, a mohl by ho použít znovu — přesně věc, které se
                    // tu snažíme zabránit. Při selhání potomků se to musí vrátit zpět.
                    // Pozor: u násobení mohou být OBA operandy -1 naráz (např. cíl 1 → (-1)×(-1)),
                    // proto se počítá, ne jen "ano/ne".
                    $negOneCount = $this->countNegativeOneFactors($operator, $a, $b);
                    if ($negOneCount > 0) {
                        $this->negativeOneFactorsUsed += $negOneCount;
                    }

                    try {
                        $left = $this->build($a, $leftBudget);
                        $right = $this->build($b, $rightBudget);
                    } catch (RetryExhaustedException $e) {
                        if ($negOneCount > 0) {
                            $this->negativeOneFactorsUsed -= $negOneCount;
                        }
                        throw $e;
                    }

                    return new BinaryOp($operator, $left, $right, $this->scale);
                } catch (RetryExhaustedException) {
                    continue;
                }
            }
        }

        throw new RetryExhaustedException("Nelze rozložit hodnotu {$target} na {$opsBudget} operací.");
    }

    /**
     * Všechny povolené operátory ve váženě náhodném pořadí BEZ opakování (postupné
     * ruletové losování ze zmenšujícího se zbytku) — vyšší váha = větší šance být dřív.
     * Použití v build(): každý operátor dostane plnou sadu pokusů dřív, než přijde na
     * řadu další — viz komentář tam, proč to nejde losovat nezávisle při každém pokusu.
     *
     * Operátor s váhou 0 se do pořadí vůbec nezařadí (normalizovaná pravděpodobnost
     * 0 % = nikdy) — POKUD existuje aspoň jeden povolený operátor s kladnou váhou.
     * Když mají váhu 0 úplně všechny povolené operátory, spadne se na čistě náhodné
     * pořadí mezi nimi, ať generování nezůstane bez jakéhokoliv operátoru ke zkoušení.
     *
     * @return Operator[]
     */
    private function weightedOperatorOrder(): array
    {
        $operators = $this->config->operators;
        $weights = array_map(fn(Operator $op) => $this->config->operatorWeight($op), $operators);

        if (array_sum($weights) <= 0) {
            return $this->rng->shuffled($operators);
        }

        $remaining = array_values(array_filter($operators, fn(Operator $op) => $this->config->operatorWeight($op) > 0));
        $order = [];

        while ($remaining !== []) {
            $currentWeights = array_map(fn(Operator $op) => $this->config->operatorWeight($op), $remaining);
            $total = array_sum($currentWeights);

            $roll = $this->rng->int(1, $total);
            $cumulative = 0;
            foreach ($remaining as $i => $op) {
                $cumulative += $currentWeights[$i];
                if ($roll <= $cumulative) {
                    $order[] = $op;
                    array_splice($remaining, $i, 1);
                    continue 2;
                }
            }
        }

        return $order;
    }

    /** Náhodné číslo z rozsahu — s bias na počet cifer, nebo čistě uniformní, podle configu. */
    private function pickRangedInt(int $min, int $max): int
    {
        return $this->config->digitCountBiasEnabled
            ? $this->rng->intBiasedByDigits($min, $max)
            : $this->rng->int($min, $max);
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
            && $preferredHighA >= $lowA
            && $this->shouldPreferNonNegative();

        for ($i = 0; $i < self::MAX_NODE_RETRIES; $i++) {
            $useFullRange = !$preferNonNegativeB || $i >= self::MAX_NODE_RETRIES - 3;
            $a = $useFullRange
                ? $this->pickRangedInt($lowA, $highA)
                : $this->pickRangedInt($lowA, $preferredHighA);
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
        // a - b = 0 nutně znamená a = b, ať už se b zvolí jakkoliv — vždy triviální "a - a".
        // Bez týhle kontroly bias na počet cifer dělal z 0 jako mezivýsledku neúměrně
        // pravděpodobný cíl, takže se "a - a" objevovalo mnohem častěji, než by mělo.
        if ($target === 0) {
            throw new RetryExhaustedException('Odčítání s cílovým výsledkem 0 by bylo vždy triviální (a - a).');
        }

        $lowB = max($this->scaledMin, $this->scaledMin - $target);
        $highB = min($this->scaledMax, $this->scaledMax - $target);
        if ($lowB > $highB) {
            throw new RetryExhaustedException("Rozdíl {$target} nelze rozložit v daném rozsahu.");
        }

        // Preferuj b >= 0, ať se omezí ošklivé zápisy typu "40 - (-19)".
        $preferredLowB = max($lowB, 0);
        $preferNonNegativeB = $this->config->allowNegative
            && $preferredLowB <= $highB
            && $this->shouldPreferNonNegative();

        for ($i = 0; $i < self::MAX_NODE_RETRIES; $i++) {
            $useFullRange = !$preferNonNegativeB || $i >= self::MAX_NODE_RETRIES - 3;
            $b = $useFullRange
                ? $this->pickRangedInt($lowB, $highB)
                : $this->pickRangedInt($preferredLowB, $highB);
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
        if ($this->config->smallMultiplicationTable) {
            return $this->pickSmallTableMulOperands($target, $leftIsLeaf, $rightIsLeaf);
        }

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
            // "1" je jako činitel zakázané (aby úloha nebyla triviální), "-1" povolené,
            // ale jen omezeně — víc "-1" za sebou se navzájem vyruší a je to triviální znovu.
            if ($a === $scale || $b === $scale) {
                continue;
            }
            // Pozor: a i b mohou být -1 naráz (cíl 1 → (-1)×(-1)), proto se počítá,
            // ne jen "je aspoň jeden -1".
            $negOneCount = ($a === -$scale ? 1 : 0) + ($b === -$scale ? 1 : 0);
            if ($negOneCount > 0 && $this->negativeOneFactorsUsed + $negOneCount > $this->config->maxNegativeOneFactors) {
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

    /**
     * Varianta pickMulOperands pro omezení "malá násobilka": oba činitele smí být jen
     * 1–10 (případně -10..-1, pokud jsou povolená záporná čísla) — bez ohledu na to,
     * jak velký je $target. Necílí přímo na dělitele $target jako obecné násobení,
     * ale enumeruje všechny dvojice 1–10, jejichž součin dá $target.
     */
    private function pickSmallTableMulOperands(int $target, bool $leftIsLeaf, bool $rightIsLeaf): array
    {
        if ($this->scale !== 1) {
            throw new RetryExhaustedException('Malá násobilka nepodporuje desetinná čísla.');
        }

        $absTarget = abs($target);
        $candidates = [];
        for ($a = 1; $a <= 10; $a++) {
            if ($absTarget % $a !== 0) {
                continue;
            }
            $b = intdiv($absTarget, $a);
            if ($b < 1 || $b > 10) {
                continue;
            }
            if ($target >= 0) {
                $candidates[] = [$a, $b];
                if ($this->config->allowNegative) {
                    $candidates[] = [-$a, -$b];
                }
            } elseif ($this->config->allowNegative) {
                $candidates[] = [-$a, $b];
                $candidates[] = [$a, -$b];
            }
        }
        $candidates = $this->rng->shuffled($candidates);

        foreach ($candidates as [$a, $b]) {
            if ($a < $this->scaledMin || $a > $this->scaledMax) {
                continue;
            }
            if ($b < $this->scaledMin || $b > $this->scaledMax) {
                continue;
            }
            if ($a === 1 || $b === 1) {
                continue;
            }
            $negOneCount = ($a === -1 ? 1 : 0) + ($b === -1 ? 1 : 0);
            if ($negOneCount > 0 && $this->negativeOneFactorsUsed + $negOneCount > $this->config->maxNegativeOneFactors) {
                continue;
            }
            if ($leftIsLeaf && $a === 0) {
                continue;
            }
            if ($rightIsLeaf && $b === 0) {
                continue;
            }

            return [$a, $b];
        }

        throw new RetryExhaustedException("Součin {$target} není dosažitelný jako součin dvou čísel malé násobilky (1-10).");
    }

    /** a / b = target (ve škálovaných jednotkách: a = target*b/scale) */
    private function pickDivOperands(int $target, bool $leftIsLeaf, bool $rightIsLeaf): array
    {
        if ($target === 0) {
            throw new RetryExhaustedException('Podíl s cílovým výsledkem 0 zatím negenerujeme.');
        }
        // a / b = 1 nutně znamená a = b, ať už se b zvolí jakkoliv — vždy triviální "a ÷ a".
        // Stejný důvod jako u odčítání s cílem 0, viz pickSubOperands.
        if ($target === $this->scale) {
            throw new RetryExhaustedException('Dělení s cílovým výsledkem 1 by bylo vždy triviální (a ÷ a).');
        }
        if ($this->config->smallMultiplicationTable) {
            return $this->pickSmallTableDivOperands($target, $leftIsLeaf, $rightIsLeaf);
        }

        $scale = $this->scale;

        for ($i = 0; $i < self::MAX_NODE_RETRIES; $i++) {
            $b = $this->pickRangedInt($this->scaledMin, $this->scaledMax);
            if ($b === 0 || $b === $scale) {
                continue; // dělitel nesmí být 0 ani "1" (-1 povoleno, ale omezeně, viz níže)
            }
            if ($b === -$scale && $this->negativeOneFactorsUsed >= $this->config->maxNegativeOneFactors) {
                continue;
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

    /**
     * Varianta pickDivOperands pro omezení "malá násobilka": dělitel i výsledek (podíl)
     * musí být 1–10 (případně -10..-1), dělenec pak vyjde přirozeně až do 100.
     * $target === 0 a $target === $scale (tj. přesně 1) jsou zamítnuté už výš v
     * pickDivOperands, takže se sem nikdy nedostanou.
     */
    private function pickSmallTableDivOperands(int $target, bool $leftIsLeaf, bool $rightIsLeaf): array
    {
        if ($this->scale !== 1) {
            throw new RetryExhaustedException('Malá násobilka nepodporuje desetinná čísla.');
        }
        if (abs($target) > 10) {
            throw new RetryExhaustedException("Podíl s výsledkem {$target} přesahuje malou násobilku (1-10).");
        }

        $candidates = [];
        for ($b = 1; $b <= 10; $b++) {
            $a = $target * $b;
            $candidates[] = [$a, $b];
            if ($this->config->allowNegative) {
                $candidates[] = [-$a, -$b];
            }
        }
        $candidates = $this->rng->shuffled($candidates);

        foreach ($candidates as [$a, $b]) {
            if ($a < $this->scaledMin || $a > $this->scaledMax) {
                continue;
            }
            if ($b < $this->scaledMin || $b > $this->scaledMax) {
                continue;
            }
            if ($b === 1) {
                continue;
            }
            if ($b === -1 && $this->negativeOneFactorsUsed + 1 > $this->config->maxNegativeOneFactors) {
                continue;
            }
            if ($leftIsLeaf && $a === 0) {
                continue;
            }

            return [$a, $b];
        }

        throw new RetryExhaustedException("Podíl {$target} nelze v malé násobilce rozložit.");
    }

    /**
     * Kolikrát tenhle uzel použije "-1" jako činitel/dělitel (0, 1, nebo u násobení
     * i 2 — např. cíl 1 se rozloží jako (-1) × (-1), oba operandy naráz). U dělení se
     * počítá jen dělitel ($b) — "-1 ÷ 5" není triviální stejným způsobem jako "a ÷ (-1)".
     */
    private function countNegativeOneFactors(Operator $operator, int $a, int $b): int
    {
        return match ($operator) {
            Operator::Mul => ($a === -$this->scale ? 1 : 0) + ($b === -$this->scale ? 1 : 0),
            Operator::Div => $b === -$this->scale ? 1 : 0,
            default => 0,
        };
    }

    /**
     * Losuje, jestli se pro aktuální uzel + / - má zkusit preferovat nezáporné b
     * (viz pickAddOperands/pickSubOperands). Řízeno GeneratorConfig::doubleNegativeBiasPercent.
     */
    private function shouldPreferNonNegative(): bool
    {
        $percent = $this->config->doubleNegativeBiasPercent;
        if ($percent <= 0) {
            return false;
        }
        if ($percent >= 100) {
            return true;
        }
        return $this->rng->int(1, 100) <= $percent;
    }

    private function randomValue(int $min, int $max, bool $allowZero): int
    {
        for ($i = 0; $i < self::MAX_NODE_RETRIES; $i++) {
            $v = $this->pickRangedInt($min, $max);
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
