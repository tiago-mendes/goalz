<?php

namespace App;

enum StatementImportStatus: string
{
    case Uploaded = 'uploaded';
    case Processing = 'processing';
    case ReadyForReview = 'ready_for_review';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Uploaded => 'Uploaded',
            self::Processing => 'Processing',
            self::ReadyForReview => 'Ready for review',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
        };
    }
}
