<?php

namespace App;

use InvalidArgumentException;

final readonly class StatementParserContext
{
    public function __construct(
        public StatementDocumentType $documentType,
        public StatementInstitution $institution,
        public ?int $accountId = null,
        public ?int $creditCardId = null,
        public ?int $billDueYear = null,
        public ?int $billDueMonth = null,
    ) {
        $validBankStatement = $documentType === StatementDocumentType::BankStatement
            && $accountId !== null
            && $creditCardId === null
            && $billDueYear === null
            && $billDueMonth === null;

        $validCreditCardBill = $documentType === StatementDocumentType::CreditCardBill
            && $accountId === null
            && $creditCardId !== null
            && $billDueYear !== null
            && $billDueYear >= 1000
            && $billDueYear <= 9999
            && $billDueMonth !== null
            && $billDueMonth >= 1
            && $billDueMonth <= 12;

        if (! $validBankStatement && ! $validCreditCardBill) {
            throw new InvalidArgumentException('The statement parser context is invalid.');
        }
    }
}
