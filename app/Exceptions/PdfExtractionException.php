<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

final class PdfExtractionException extends RuntimeException implements ShouldntReport
{
    private function __construct(public readonly string $category)
    {
        parent::__construct('The PDF could not be extracted.');
    }

    public static function unavailableImport(): self
    {
        return new self('unavailable_import');
    }

    public static function incompleteContext(): self
    {
        return new self('incomplete_context');
    }

    public static function missingFile(): self
    {
        return new self('missing_file');
    }

    public static function unreadableFile(): self
    {
        return new self('unreadable_file');
    }

    public static function binaryUnavailable(): self
    {
        return new self('binary_unavailable');
    }

    public static function pageCountFailed(): self
    {
        return new self('page_count_failed');
    }

    public static function pageLimitExceeded(): self
    {
        return new self('page_limit_exceeded');
    }

    public static function outputLimitExceeded(): self
    {
        return new self('output_limit_exceeded');
    }

    public static function timedOut(): self
    {
        return new self('timed_out');
    }

    public static function extractionFailed(): self
    {
        return new self('extraction_failed');
    }
}
