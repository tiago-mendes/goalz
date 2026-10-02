<?php

namespace App;

use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

final readonly class ParsedStatement
{
    /**
     * @param  list<string>  $warnings
     * @param  list<ParsedStatementTransaction>  $transactions
     */
    public function __construct(
        public StatementDocumentType $documentType,
        public StatementInstitution $institution,
        public ?CarbonImmutable $periodStart = null,
        public ?CarbonImmutable $periodEnd = null,
        public ?CarbonImmutable $dueDate = null,
        public ?BigDecimal $reportedTotal = null,
        public ?string $maskedIdentifier = null,
        public array $warnings = [],
        public array $transactions = [],
    ) {}
}
