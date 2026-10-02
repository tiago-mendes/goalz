<?php

namespace Tests\Unit;

use App\ParsedStatement;
use App\ParsedStatementTransaction;
use App\StatementDocumentType;
use App\StatementInstitution;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class ParsedStatementTest extends TestCase
{
    public function test_represents_normalized_document_data_with_exact_money(): void
    {
        $transaction = new ParsedStatementTransaction(
            transactionDate: CarbonImmutable::parse('2026-09-01'),
            rawDescription: 'TEST MERCHANT A',
            amount: BigDecimal::of('123.45'),
        );
        $statement = new ParsedStatement(
            documentType: StatementDocumentType::CreditCardBill,
            institution: StatementInstitution::Itau,
            periodStart: CarbonImmutable::parse('2026-09-01'),
            periodEnd: CarbonImmutable::parse('2026-09-30'),
            dueDate: CarbonImmutable::parse('2026-10-20'),
            reportedTotal: BigDecimal::of('123.45'),
            maskedIdentifier: '**** 1234',
            warnings: ['Synthetic warning'],
            transactions: [$transaction],
        );

        $this->assertSame('123.45', (string) $statement->reportedTotal);
        $this->assertSame([$transaction], $statement->transactions);
        $this->assertSame('123.45', (string) $transaction->amount);
        $this->assertSame('TEST MERCHANT A', $transaction->rawDescription);
    }
}
