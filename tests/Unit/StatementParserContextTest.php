<?php

namespace Tests\Unit;

use App\StatementDocumentType;
use App\StatementInstitution;
use App\StatementParserContext;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class StatementParserContextTest extends TestCase
{
    public function test_represents_trusted_bank_statement_context(): void
    {
        $context = new StatementParserContext(
            documentType: StatementDocumentType::BankStatement,
            institution: StatementInstitution::Itau,
            accountId: 42,
        );

        $this->assertSame(StatementDocumentType::BankStatement, $context->documentType);
        $this->assertSame(StatementInstitution::Itau, $context->institution);
        $this->assertSame(42, $context->accountId);
        $this->assertNull($context->creditCardId);
        $this->assertNull($context->billDueYear);
        $this->assertNull($context->billDueMonth);
    }

    public function test_represents_trusted_credit_card_bill_context(): void
    {
        $context = new StatementParserContext(
            documentType: StatementDocumentType::CreditCardBill,
            institution: StatementInstitution::Itau,
            creditCardId: 84,
            billDueYear: 2026,
            billDueMonth: 10,
        );

        $this->assertSame(StatementDocumentType::CreditCardBill, $context->documentType);
        $this->assertSame(84, $context->creditCardId);
        $this->assertSame(2026, $context->billDueYear);
        $this->assertSame(10, $context->billDueMonth);
        $this->assertNull($context->accountId);
    }

    #[TestWith(['bank_statement', null, null, null, null])]
    #[TestWith(['bank_statement', 1, 2, null, null])]
    #[TestWith(['bank_statement', 1, null, 2026, 10])]
    #[TestWith(['credit_card_bill', 1, null, 2026, 10])]
    #[TestWith(['credit_card_bill', null, 2, null, null])]
    #[TestWith(['credit_card_bill', null, 2, 2026, 13])]
    public function test_rejects_incompatible_or_incomplete_context(
        string $documentType,
        ?int $accountId,
        ?int $creditCardId,
        ?int $billDueYear,
        ?int $billDueMonth,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The statement parser context is invalid.');

        new StatementParserContext(
            documentType: StatementDocumentType::from($documentType),
            institution: StatementInstitution::Itau,
            accountId: $accountId,
            creditCardId: $creditCardId,
            billDueYear: $billDueYear,
            billDueMonth: $billDueMonth,
        );
    }
}
