<?php

namespace App\Actions;

use App\Contracts\PdfTextExtractor;
use App\Exceptions\PdfExtractionException;
use App\ExtractedPdf;
use App\Models\StatementImport;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Throwable;

final readonly class ExtractStatementImportPdf
{
    public function __construct(private PdfTextExtractor $extractor) {}

    public function handle(User $user, StatementImport $statementImport): ExtractedPdf
    {
        if (! $user->exists || $statementImport->getKey() === null) {
            throw PdfExtractionException::unavailableImport();
        }

        $ownedImport = $user->statementImports()->whereKey($statementImport->getKey())->first();

        if ($ownedImport === null) {
            throw PdfExtractionException::unavailableImport();
        }

        if (! $ownedImport->hasImportContext()) {
            throw PdfExtractionException::incompleteContext();
        }

        $privateDisk = (string) config('statement-imports.storage_disk', 'statement-imports');

        if ($ownedImport->storage_disk !== $privateDisk) {
            throw PdfExtractionException::missingFile();
        }

        try {
            $disk = Storage::disk($privateDisk);

            if (! $disk->exists($ownedImport->storage_path)) {
                throw PdfExtractionException::missingFile();
            }

            $absolutePath = $disk->path($ownedImport->storage_path);
            $diskRoot = realpath($disk->path(''));
            $resolvedPath = realpath($absolutePath);

            if (
                $diskRoot === false
                || $resolvedPath === false
                || ! str_starts_with($resolvedPath, rtrim($diskRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)
            ) {
                throw PdfExtractionException::missingFile();
            }
        } catch (PdfExtractionException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw PdfExtractionException::missingFile();
        }

        return $this->extractor->extract($resolvedPath);
    }
}
