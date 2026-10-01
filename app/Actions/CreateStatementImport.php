<?php

namespace App\Actions;

use App\Models\Account;
use App\Models\CreditCard;
use App\Models\StatementImport;
use App\Models\User;
use App\StatementDocumentType;
use App\StatementImportStatus;
use App\StatementInstitution;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class CreateStatementImport
{
    public function handle(
        User $user,
        UploadedFile $file,
        StatementDocumentType $documentType,
        StatementInstitution $institution,
        ?Account $account = null,
        ?CreditCard $creditCard = null,
        ?int $billDueYear = null,
        ?int $billDueMonth = null,
        bool $allowInactiveSource = false,
    ): StatementImport {
        [$ownedAccount, $ownedCreditCard] = $this->validateContext(
            $user,
            $documentType,
            $account,
            $creditCard,
            $billDueYear,
            $billDueMonth,
            $allowInactiveSource,
        );

        $filePath = $file->getRealPath();
        $fileSha256 = $filePath === false ? false : hash_file('sha256', $filePath);

        if ($fileSha256 === false) {
            throw new RuntimeException('The statement file could not be hashed.');
        }

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
            $statementImport->document_type = $documentType;
            $statementImport->institution = $institution;
            $statementImport->account()->associate($ownedAccount);
            $statementImport->creditCard()->associate($ownedCreditCard);
            $statementImport->bill_due_year = $billDueYear;
            $statementImport->bill_due_month = $billDueMonth;
            $statementImport->file_sha256 = $fileSha256;
            $statementImport->save();

            return $statementImport;
        } catch (Throwable $exception) {
            Storage::disk($disk)->delete($path);

            throw $exception;
        }
    }

    /** @return array{Account|null, CreditCard|null} */
    private function validateContext(
        User $user,
        StatementDocumentType $documentType,
        ?Account $account,
        ?CreditCard $creditCard,
        ?int $billDueYear,
        ?int $billDueMonth,
        bool $allowInactiveSource,
    ): array {
        if (! $user->exists || ! User::query()->whereKey($user->getKey())->exists()) {
            throw ValidationException::withMessages(['statement' => 'The statement owner is unavailable.']);
        }

        if ($documentType === StatementDocumentType::BankStatement) {
            if ($account === null) {
                throw ValidationException::withMessages(['account_id' => 'Choose an account.']);
            }

            if ($creditCard !== null) {
                throw ValidationException::withMessages(['credit_card_id' => 'A bank statement cannot use a credit card.']);
            }

            if ($billDueYear !== null || $billDueMonth !== null) {
                throw ValidationException::withMessages(['bill_due_month' => 'A bank statement cannot have a bill due month.']);
            }

            $ownedAccount = $user->accounts()->whereKey($account->getKey())->first();

            if ($ownedAccount === null || (! $ownedAccount->is_active && ! $allowInactiveSource)) {
                throw ValidationException::withMessages(['account_id' => 'The selected account is unavailable.']);
            }

            return [$ownedAccount, null];
        }

        if ($account !== null) {
            throw ValidationException::withMessages(['account_id' => 'A credit card bill cannot use an account.']);
        }

        if ($creditCard === null) {
            throw ValidationException::withMessages(['credit_card_id' => 'Choose a credit card.']);
        }

        if ($billDueYear === null || $billDueYear < 1000 || $billDueYear > 9999) {
            throw ValidationException::withMessages(['bill_due_month' => 'Choose a valid bill due month.']);
        }

        if ($billDueMonth === null || $billDueMonth < 1 || $billDueMonth > 12) {
            throw ValidationException::withMessages(['bill_due_month' => 'Choose a valid bill due month.']);
        }

        $ownedCreditCard = $user->creditCards()->whereKey($creditCard->getKey())->first();

        if ($ownedCreditCard === null || (! $ownedCreditCard->is_active && ! $allowInactiveSource)) {
            throw ValidationException::withMessages(['credit_card_id' => 'The selected credit card is unavailable.']);
        }

        return [null, $ownedCreditCard];
    }
}
