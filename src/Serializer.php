<?php

declare(strict_types=1);

namespace Priklady;

/**
 * Vykreslí strom výrazu do čitelného řetězce.
 *
 * Přidávání závorek se řídí jediným pravidlem requiresParens(), které se chová
 * jinak podle toho, jestli je v zadání povolená priorita operátorů:
 *  - priorita zapnutá: minimální (matematicky nutné) závorky podle běžných pravidel.
 *  - priorita vypnutá: kdykoliv se ve stromu potkají dvě různé třídy priority
 *    (sčítání/odčítání vs. násobení/dělení), vždy se explicitně oddělí závorkou, i kdyby nebyla matematicky
 *    nutná — žák tak nikdy nemusí prioritu znát, aby úlohu správně přečetl.
 * V obou režimech navíc platí pravidlo asociativity: u neasociativních operátorů
 * (-, ÷) se závorkuje pravý operand se stejnou třídou priority jako rodič.
 */
final class Serializer
{
    public function __construct(private readonly GeneratorConfig $config)
    {
    }

    public function render(Node $node): string
    {
        return $this->printNode($node, isLeading: true);
    }

    public function renderValue(Node $node): string
    {
        return $this->formatNumber($node->scaledValue());
    }

    /** Existuje ve stromu místo, kde by bylo bez závorek nejednoznačné/nesprávné číst výraz? */
    public function needsParenthesesSomewhere(Node $node): bool
    {
        if (!$node instanceof BinaryOp) {
            return false;
        }
        if ($this->requiresParens($node->left, $node->operator, isRightChild: false)) {
            return true;
        }
        if ($this->requiresParens($node->right, $node->operator, isRightChild: true)) {
            return true;
        }
        return $this->needsParenthesesSomewhere($node->left) || $this->needsParenthesesSomewhere($node->right);
    }

    private function printNode(Node $node, bool $isLeading): string
    {
        if ($node instanceof Literal) {
            return $this->formatNumber($node->scaledValue());
        }

        \assert($node instanceof BinaryOp);

        $left = $this->printChild($node, $node->left, isRightChild: false, isLeading: $isLeading);
        $right = $this->printChild($node, $node->right, isRightChild: true, isLeading: false);

        return "{$left} {$node->operator->symbol()} {$right}";
    }

    private function printChild(BinaryOp $parent, Node $child, bool $isRightChild, bool $isLeading): string
    {
        $needsParens = $this->requiresParens($child, $parent->operator, $isRightChild)
            || ($child instanceof Literal && $child->scaledValue() < 0 && !$isLeading);

        $rendered = $this->printNode($child, $isLeading && !$needsParens);

        return $needsParens ? "({$rendered})" : $rendered;
    }

    private function requiresParens(Node $child, Operator $parentOp, bool $isRightChild): bool
    {
        if (!$child instanceof BinaryOp) {
            return false;
        }

        $childPrec = $child->operator->precedence();
        $parentPrec = $parentOp->precedence();

        if ($this->config->allowOperatorPriority) {
            if ($childPrec < $parentPrec) {
                return true;
            }
            return $childPrec === $parentPrec && $isRightChild && !$parentOp->isCommutative();
        }

        if ($childPrec !== $parentPrec) {
            return true;
        }
        return $isRightChild && !$parentOp->isCommutative();
    }

    private function formatNumber(int $scaledValue): string
    {
        $scale = $this->config->scale();
        if ($scale === 1) {
            return (string) $scaledValue;
        }

        $sign = $scaledValue < 0 ? '-' : '';
        $abs = abs($scaledValue);
        $whole = intdiv($abs, $scale);
        $frac = $abs % $scale;
        $decimals = str_pad((string) $frac, $this->config->decimalPlaces, '0', STR_PAD_LEFT);

        return "{$sign}{$whole},{$decimals}";
    }
}
