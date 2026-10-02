<?php

namespace App;

use InvalidArgumentException;

final readonly class ExtractedPdf
{
    public int $pageCount;

    /** @param list<string> $pages */
    public function __construct(public array $pages)
    {
        if ($pages === []) {
            throw new InvalidArgumentException('Extracted PDF pages must be a non-empty list.');
        }

        $this->pageCount = count($pages);
    }

    public function fullText(): string
    {
        return implode("\f", $this->pages);
    }
}
