<?php

namespace App\Contracts;

use App\ExtractedPdf;

interface PdfTextExtractor
{
    public function extract(string $absolutePdfPath): ExtractedPdf;
}
