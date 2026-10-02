<?php

namespace App;

use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

final readonly class ParsedStatementTransaction
{
    public function __construct(
        public ?CarbonImmutable $transactionDate,
        public string $rawDescription,
        public BigDecimal $amount,
    ) {}
}
