<?php

declare(strict_types=1);

namespace Priklady;

interface Node
{
    /**
     * Hodnota uzlu ve "škálovaných" celých číslech — viz GeneratorConfig::scale().
     * Skutečná hodnota je scaledValue() / scale().
     */
    public function scaledValue(): int;
}
