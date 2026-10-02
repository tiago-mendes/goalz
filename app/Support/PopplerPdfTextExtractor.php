<?php

namespace App\Support;

use App\Contracts\PdfTextExtractor;
use App\Exceptions\PdfExtractionException;
use App\ExtractedPdf;
use InvalidArgumentException;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

final readonly class PopplerPdfTextExtractor implements PdfTextExtractor
{
    public function __construct(
        private string $pdftotextBinary,
        private string $pdfinfoBinary,
        private float $timeoutSeconds,
        private int $maxPages,
        private int $maxExtractedBytes,
        private int $maxMetadataBytes,
        private int $maxDiagnosticBytes,
    ) {
        if (
            $timeoutSeconds <= 0
            || $maxPages < 1
            || $maxExtractedBytes < 1
            || $maxMetadataBytes < 1
            || $maxDiagnosticBytes < 1
        ) {
            throw new InvalidArgumentException('PDF extraction limits must be positive.');
        }
    }

    public function extract(string $absolutePdfPath): ExtractedPdf
    {
        $pdfPath = $this->resolveReadableFile($absolutePdfPath);
        $this->requireExecutable($this->pdfinfoBinary);
        $this->requireExecutable($this->pdftotextBinary);

        $metadata = $this->runBounded(
            [$this->pdfinfoBinary, $pdfPath],
            $this->maxMetadataBytes,
            PdfExtractionException::pageCountFailed(),
        );
        $pageCount = $this->parsePageCount($metadata);

        if ($pageCount > $this->maxPages) {
            throw PdfExtractionException::pageLimitExceeded();
        }

        $text = $this->runBounded(
            [$this->pdftotextBinary, '-layout', '-enc', 'UTF-8', $pdfPath, '-'],
            $this->maxExtractedBytes,
            PdfExtractionException::extractionFailed(),
        );

        if (! mb_check_encoding($text, 'UTF-8')) {
            throw PdfExtractionException::extractionFailed();
        }

        return new ExtractedPdf($this->splitPages($text, $pageCount));
    }

    private function resolveReadableFile(string $path): string
    {
        if (! file_exists($path)) {
            throw PdfExtractionException::missingFile();
        }

        if (! is_file($path) || ! is_readable($path)) {
            throw PdfExtractionException::unreadableFile();
        }

        $resolvedPath = realpath($path);

        if ($resolvedPath === false) {
            throw PdfExtractionException::unreadableFile();
        }

        return $resolvedPath;
    }

    private function requireExecutable(string $binary): void
    {
        if (! is_file($binary) || ! is_executable($binary)) {
            throw PdfExtractionException::binaryUnavailable();
        }
    }

    private function parsePageCount(string $metadata): int
    {
        if (preg_match('/^Pages:\s*([0-9]+)\s*$/m', $metadata, $matches) !== 1) {
            throw PdfExtractionException::pageCountFailed();
        }

        $pageCount = filter_var($matches[1], FILTER_VALIDATE_INT);

        if (! is_int($pageCount) || $pageCount < 1) {
            throw PdfExtractionException::pageCountFailed();
        }

        return $pageCount;
    }

    /** @return list<string> */
    private function splitPages(string $text, int $pageCount): array
    {
        $pages = explode("\f", str_replace(["\r\n", "\r"], "\n", $text));

        while (count($pages) > $pageCount && trim((string) end($pages)) === '') {
            array_pop($pages);
        }

        if (count($pages) !== $pageCount) {
            throw PdfExtractionException::extractionFailed();
        }

        return $pages;
    }

    /**
     * @param  list<string>  $command
     */
    private function runBounded(array $command, int $stdoutLimit, PdfExtractionException $failure): string
    {
        $process = new Process($command, env: ['LC_ALL' => 'C', 'LANG' => 'C']);
        $process->setTimeout($this->timeoutSeconds);
        $process->disableOutput();

        $stdout = '';
        $stderrBytes = 0;

        // Symfony's callback type omits user exceptions, but limit checks intentionally abort the process.
        try {
            $exitCode = $process->run(function (string $type, string $chunk) use (&$stdout, &$stderrBytes, $stdoutLimit): void {
                if ($type === Process::OUT) {
                    $this->appendBoundedOutput($stdout, $chunk, $stdoutLimit);

                    return;
                }

                $this->countBoundedDiagnostics($stderrBytes, $chunk);
            });
        } catch (PdfExtractionException $exception) { // @phpstan-ignore catch.neverThrown
            $this->stopProcess($process);

            throw $exception;
        } catch (ProcessTimedOutException) {
            $this->stopProcess($process);

            throw PdfExtractionException::timedOut();
        } catch (ProcessStartFailedException) {
            $this->stopProcess($process);

            throw PdfExtractionException::binaryUnavailable();
        } catch (Throwable) {
            $this->stopProcess($process);

            throw $failure;
        }

        if ($exitCode !== 0) {
            throw $failure;
        }

        return $stdout;
    }

    private function appendBoundedOutput(string &$output, string $chunk, int $limit): void
    {
        if (strlen($chunk) > $limit - strlen($output)) {
            throw PdfExtractionException::outputLimitExceeded();
        }

        $output .= $chunk;
    }

    private function countBoundedDiagnostics(int &$diagnosticBytes, string $chunk): void
    {
        $diagnosticBytes += strlen($chunk);

        if ($diagnosticBytes > $this->maxDiagnosticBytes) {
            throw PdfExtractionException::outputLimitExceeded();
        }
    }

    private function stopProcess(Process $process): void
    {
        try {
            $process->stop(0);
        } catch (Throwable) {
            // The sanitized extraction failure remains authoritative.
        }
    }
}
