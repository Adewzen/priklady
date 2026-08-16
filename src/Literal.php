<?php

declare(strict_types=1);

namespace Priklady;

final class Literal implements Node
{
    public function __construct(private readonly int $scaledValue)
    {
    }

    public function scaledValue(): int
    {
        return $this->scaledValue;
    }
}
