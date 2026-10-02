<?php

namespace App\Contracts;

use App\ExtractedPdf;
use App\ParsedStatement;
use App\StatementParserContext;

interface StatementParser
{
    public function parse(ExtractedPdf $document, StatementParserContext $context): ParsedStatement;
}
