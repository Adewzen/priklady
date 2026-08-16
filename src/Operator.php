<?php

declare(strict_types=1);

namespace Priklady;

enum Operator: string
{
    case Add = 'add';
    case Sub = 'sub';
    case Mul = 'mul';
    case Div = 'div';

    /** Znak pro tisk v zadání. */
    public function symbol(): string
    {
        return match ($this) {
            self::Add => '+',
            self::Sub => '-',
            self::Mul => '×',
            self::Div => '÷',
        };
    }

    /** Třída priority: 1 = sčítání/odčítání, 2 = násobení/dělení. */
    public function precedence(): int
    {
        return match ($this) {
            self::Add, self::Sub => 1,
            self::Mul, self::Div => 2,
        };
    }

    public function isCommutative(): bool
    {
        return match ($this) {
            self::Add, self::Mul => true,
            self::Sub, self::Div => false,
        };
    }
}
