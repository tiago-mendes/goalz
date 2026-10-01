<?php

namespace App\Actions;

use App\Models\StatementImport;
use App\Models\User;
use App\StatementImportStatus;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class CreateStatementImport
{
    public function handle(User $user, UploadedFile $file): StatementImport
    {
        $disk = 'statement-imports';
        $directory = (string) $user->getKey();
        $filename = Str::uuid().'.pdf';
        $path = Storage::disk($disk)->putFileAs($directory, $file, $filename);

        if ($path === false) {
            throw new RuntimeException('The statement file could not be stored.');
        }

        try {
            $statementImport = new StatementImport;
            $statementImport->user()->associate($user);
            $statementImport->original_filename = Str::limit($file->getClientOriginalName(), 255, '');
            $statementImport->storage_disk = $disk;
            $statementImport->storage_path = $path;
            $statementImport->status = StatementImportStatus::Uploaded;
            $statementImport->save();

            return $statementImport;
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);

            throw $exception;
        }
    }
}
