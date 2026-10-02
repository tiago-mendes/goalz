<?php

return [
    'storage_disk' => 'statement-imports',

    'pdftotext_binary' => env('PDFTOTEXT_BINARY', '/usr/bin/pdftotext'),
    'pdfinfo_binary' => env('PDFINFO_BINARY', '/usr/bin/pdfinfo'),

    'timeout_seconds' => (float) env('PDF_EXTRACTION_TIMEOUT_SECONDS', 15),
    'max_pages' => (int) env('PDF_EXTRACTION_MAX_PAGES', 100),
    'max_extracted_bytes' => (int) env('PDF_EXTRACTION_MAX_BYTES', 5 * 1024 * 1024),
    'max_metadata_bytes' => (int) env('PDF_EXTRACTION_MAX_METADATA_BYTES', 64 * 1024),
    'max_diagnostic_bytes' => (int) env('PDF_EXTRACTION_MAX_DIAGNOSTIC_BYTES', 64 * 1024),
];
