<?php

namespace Tests\Unit;

use App\Exceptions\PdfExtractionException;
use App\Support\PopplerPdfTextExtractor;
use PHPUnit\Framework\TestCase;

class PopplerPdfTextExtractorTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_extracts_ordered_pages_from_synthetic_pdf_with_real_poppler(): void
    {
        $this->requirePoppler();

        $document = $this->realExtractor()->extract($this->fixturePath());

        $this->assertSame(2, $document->pageCount);
        $this->assertCount(2, $document->pages);
        $this->assertStringContainsString('CARD STATEMENT - SYNTHETIC', $document->pages[0]);
        $this->assertMatchesRegularExpression('/Due date: 20\/10\/2026\s+01\/09\/2026 TEST MERCHANT A R\$ 123,45/', $document->pages[0]);
        $this->assertStringContainsString('SYNTHETIC PAGE TWO', $document->pages[1]);
        $this->assertTrue(mb_check_encoding($document->fullText(), 'UTF-8'));
    }

    public function test_passes_layout_and_utf8_as_separate_process_arguments(): void
    {
        $pdfinfo = $this->executable("#!/bin/sh\nprintf 'Pages: 1\\n'\n");
        $pdftotext = $this->executable(<<<'SH'
#!/bin/sh
if [ "$1" != "-layout" ] || [ "$2" != "-enc" ] || [ "$3" != "UTF-8" ] || [ "$5" != "-" ]; then
    exit 42
fi
printf 'synthetic page\f'
SH);

        $document = $this->extractor($pdftotext, $pdfinfo)->extract($this->inputPdf());

        $this->assertSame(['synthetic page'], $document->pages);
    }

    public function test_unusual_path_is_passed_as_one_argument_without_shell_evaluation(): void
    {
        $this->requirePoppler();
        $path = sys_get_temp_dir().'/goalz synthetic ; statement '.bin2hex(random_bytes(6)).'.pdf';
        copy($this->fixturePath(), $path);
        $this->temporaryFiles[] = $path;

        $document = $this->realExtractor()->extract($path);

        $this->assertSame(2, $document->pageCount);
    }

    public function test_rejects_page_count_over_configured_limit_before_text_extraction(): void
    {
        $pdfinfo = $this->executable("#!/bin/sh\nprintf 'Pages: 101\\n'\n");
        $pdftotext = $this->executable("#!/bin/sh\nexit 99\n");

        $this->assertExtractionFailure(
            'page_limit_exceeded',
            fn () => $this->extractor($pdftotext, $pdfinfo, maxPages: 100)->extract($this->inputPdf()),
        );
    }

    public function test_rejects_pdfinfo_output_without_deterministic_page_count(): void
    {
        $pdfinfo = $this->executable("#!/bin/sh\nprintf 'Title: Synthetic only\\n'\n");
        $pdftotext = $this->executable("#!/bin/sh\nprintf 'unused\\f'\n");

        $this->assertExtractionFailure(
            'page_count_failed',
            fn () => $this->extractor($pdftotext, $pdfinfo)->extract($this->inputPdf()),
        );
    }

    public function test_rejects_extracted_output_over_configured_byte_limit(): void
    {
        $pdfinfo = $this->executable("#!/bin/sh\nprintf 'Pages: 1\\n'\n");
        $pdftotext = $this->executable("#!/bin/sh\nprintf '12345678901\\f'\n");

        $this->assertExtractionFailure(
            'output_limit_exceeded',
            fn () => $this->extractor($pdftotext, $pdfinfo, maxExtractedBytes: 10)->extract($this->inputPdf()),
        );
    }

    public function test_converts_process_timeout_to_sanitized_exception(): void
    {
        $pdfinfo = $this->executable("#!/bin/sh\nsleep 1\nprintf 'Pages: 1\\n'\n");
        $pdftotext = $this->executable("#!/bin/sh\nprintf 'unused\\f'\n");

        $this->assertExtractionFailure(
            'timed_out',
            fn () => $this->extractor($pdftotext, $pdfinfo, timeoutSeconds: 0.05)->extract($this->inputPdf()),
        );
    }

    public function test_rejects_missing_configured_binary_with_sanitized_exception(): void
    {
        $missingBinary = sys_get_temp_dir().'/missing-pdfinfo-'.bin2hex(random_bytes(6));

        $this->assertExtractionFailure(
            'binary_unavailable',
            fn () => $this->extractor('/usr/bin/pdftotext', $missingBinary)->extract($this->inputPdf()),
        );
    }

    public function test_converts_nonzero_poppler_exit_to_sanitized_exception(): void
    {
        $pdfinfo = $this->executable("#!/bin/sh\nprintf 'Pages: 1\\n'\n");
        $pdftotext = $this->executable("#!/bin/sh\nprintf 'private diagnostic' >&2\nexit 2\n");

        $this->assertExtractionFailure(
            'extraction_failed',
            fn () => $this->extractor($pdftotext, $pdfinfo)->extract($this->inputPdf()),
        );
    }

    public function test_rejects_unbounded_process_diagnostics_without_retaining_them(): void
    {
        $pdfinfo = $this->executable("#!/bin/sh\nprintf 'Pages: 1\\n'\n");
        $pdftotext = $this->executable("#!/bin/sh\nprintf 'private diagnostic' >&2\nprintf 'synthetic page\\f'\n");

        $this->assertExtractionFailure(
            'output_limit_exceeded',
            fn () => $this->extractor($pdftotext, $pdfinfo, maxDiagnosticBytes: 5)->extract($this->inputPdf()),
        );
    }

    public function test_rejects_missing_input_file_before_starting_poppler(): void
    {
        $path = sys_get_temp_dir().'/missing-statement-'.bin2hex(random_bytes(6)).'.pdf';

        $this->assertExtractionFailure(
            'missing_file',
            fn () => $this->realExtractor()->extract($path),
        );
    }

    private function realExtractor(): PopplerPdfTextExtractor
    {
        return $this->extractor('/usr/bin/pdftotext', '/usr/bin/pdfinfo');
    }

    private function extractor(
        string $pdftotext,
        string $pdfinfo,
        float $timeoutSeconds = 2,
        int $maxPages = 100,
        int $maxExtractedBytes = 5_242_880,
        int $maxDiagnosticBytes = 65_536,
    ): PopplerPdfTextExtractor {
        return new PopplerPdfTextExtractor(
            pdftotextBinary: $pdftotext,
            pdfinfoBinary: $pdfinfo,
            timeoutSeconds: $timeoutSeconds,
            maxPages: $maxPages,
            maxExtractedBytes: $maxExtractedBytes,
            maxMetadataBytes: 65_536,
            maxDiagnosticBytes: $maxDiagnosticBytes,
        );
    }

    private function inputPdf(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'goalz-pdf-input-');
        $this->assertNotFalse($path);
        file_put_contents($path, '%PDF-1.4 synthetic test input');
        $this->temporaryFiles[] = $path;

        return $path;
    }

    private function executable(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'goalz-process-');
        $this->assertNotFalse($path);
        file_put_contents($path, $contents);
        chmod($path, 0700);
        $this->temporaryFiles[] = $path;

        return $path;
    }

    private function fixturePath(): string
    {
        return dirname(__DIR__).'/Fixtures/synthetic-statement.pdf';
    }

    private function requirePoppler(): void
    {
        if (! is_executable('/usr/bin/pdftotext') || ! is_executable('/usr/bin/pdfinfo')) {
            $this->markTestSkipped('Poppler is not available.');
        }
    }

    /** @param callable(): mixed $callback */
    private function assertExtractionFailure(string $category, callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected PDF extraction to fail.');
        } catch (PdfExtractionException $exception) {
            $this->assertSame($category, $exception->category);
            $this->assertSame('The PDF could not be extracted.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }
}
