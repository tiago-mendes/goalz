<?php

use App\Actions\CreateStatementImport;
use App\Models\Account;
use App\Models\CreditCard;
use App\Models\StatementImport;
use App\Models\User;
use App\StatementDocumentType;
use App\StatementInstitution;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rules\File;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Statement Imports')] class extends Component {
    use WithFileUploads;

    public $statement;

    public string $document_type = '';

    public string $institution = '';

    public string $account_id = '';

    public string $credit_card_id = '';

    public string $bill_due_month = '';

    public bool $include_inactive_sources = false;

    public function boot(): void
    {
        Gate::authorize('viewAny', StatementImport::class);
    }

    /** @return Collection<int, StatementImport> */
    #[Computed]
    public function imports(): Collection
    {
        return auth()->user()->statementImports()
            ->select([
                'id', 'user_id', 'original_filename', 'status', 'document_type', 'institution',
                'account_id', 'credit_card_id', 'bill_due_year', 'bill_due_month', 'created_at',
            ])
            ->with(['account:id,name', 'creditCard:id,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    /** @return Collection<int, Account> */
    #[Computed]
    public function accounts(): Collection
    {
        return auth()->user()->accounts()
            ->when(! $this->include_inactive_sources, fn ($query) => $query->where('is_active', true))
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'is_active']);
    }

    /** @return Collection<int, CreditCard> */
    #[Computed]
    public function creditCards(): Collection
    {
        return auth()->user()->creditCards()
            ->when(! $this->include_inactive_sources, fn ($query) => $query->where('is_active', true))
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'is_active']);
    }

    public function updatedDocumentType(): void
    {
        if ($this->document_type === StatementDocumentType::BankStatement->value) {
            $this->reset('credit_card_id', 'bill_due_month');
        } elseif ($this->document_type === StatementDocumentType::CreditCardBill->value) {
            $this->reset('account_id');
        } else {
            $this->reset('account_id', 'credit_card_id', 'bill_due_month');
        }

        $this->resetValidation();
    }

    public function updatedIncludeInactiveSources(): void
    {
        if (! $this->include_inactive_sources) {
            if ($this->account_id !== '' && ! auth()->user()->accounts()->whereKey($this->account_id)->where('is_active', true)->exists()) {
                $this->reset('account_id');
            }

            if ($this->credit_card_id !== '' && ! auth()->user()->creditCards()->whereKey($this->credit_card_id)->where('is_active', true)->exists()) {
                $this->reset('credit_card_id');
            }
        }

        $this->resetValidation();
    }

    public function createImport(CreateStatementImport $createStatementImport): void
    {
        Gate::authorize('create', StatementImport::class);

        $validated = $this->validate([
            'document_type' => ['required', Rule::enum(StatementDocumentType::class)],
            'institution' => ['required', Rule::enum(StatementInstitution::class)],
            'account_id' => [
                'nullable',
                'integer',
                Rule::requiredIf($this->document_type === StatementDocumentType::BankStatement->value),
                Rule::prohibitedIf($this->document_type !== StatementDocumentType::BankStatement->value),
            ],
            'credit_card_id' => [
                'nullable',
                'integer',
                Rule::requiredIf($this->document_type === StatementDocumentType::CreditCardBill->value),
                Rule::prohibitedIf($this->document_type !== StatementDocumentType::CreditCardBill->value),
            ],
            'bill_due_month' => [
                'nullable',
                Rule::requiredIf($this->document_type === StatementDocumentType::CreditCardBill->value),
                Rule::prohibitedIf($this->document_type !== StatementDocumentType::CreditCardBill->value),
                'date_format:Y-m',
                'after_or_equal:1000-01',
                'before_or_equal:9999-12',
            ],
            'include_inactive_sources' => ['required', 'boolean'],
            'statement' => ['required', File::types(['pdf'])->max(10 * 1024)],
        ], [
            'document_type.required' => 'Choose a document type.',
            'institution.required' => 'Choose an institution.',
            'account_id.required' => 'Choose an account.',
            'account_id.prohibited' => 'A credit card bill cannot use an account.',
            'credit_card_id.required' => 'Choose a credit card.',
            'credit_card_id.prohibited' => 'A bank statement cannot use a credit card.',
            'bill_due_month.required' => 'Choose a bill due month.',
            'bill_due_month.prohibited' => 'A bank statement cannot have a bill due month.',
            'bill_due_month.date_format' => 'Choose a valid bill due month.',
            'bill_due_month.after_or_equal' => 'Choose a valid bill due month.',
            'bill_due_month.before_or_equal' => 'Choose a valid bill due month.',
            'statement.required' => 'Choose a PDF statement to upload.',
            'statement.max' => 'The statement must not be larger than 10 MB.',
            'statement.mimes' => 'The statement must be a valid PDF file.',
        ]);

        /** @var User $user */
        $user = auth()->user();
        $documentType = StatementDocumentType::from($validated['document_type']);
        $institution = StatementInstitution::from($validated['institution']);
        $account = null;
        $creditCard = null;
        $billDueYear = null;
        $billDueMonth = null;

        if ($documentType === StatementDocumentType::BankStatement) {
            $account = $user->accounts()
                ->when(! $this->include_inactive_sources, fn ($query) => $query->where('is_active', true))
                ->find((int) $validated['account_id']);

            if ($account === null) {
                $this->addError('account_id', 'The selected account is unavailable.');

                return;
            }
        } else {
            $creditCard = $user->creditCards()
                ->when(! $this->include_inactive_sources, fn ($query) => $query->where('is_active', true))
                ->find((int) $validated['credit_card_id']);

            if ($creditCard === null) {
                $this->addError('credit_card_id', 'The selected credit card is unavailable.');

                return;
            }

            $dueMonth = CarbonImmutable::createFromFormat('!Y-m', $validated['bill_due_month']);
            $billDueYear = $dueMonth->year;
            $billDueMonth = $dueMonth->month;
        }

        /** @var UploadedFile $statement */
        $statement = $validated['statement'];

        try {
            $createStatementImport->handle(
                user: $user,
                file: $statement,
                documentType: $documentType,
                institution: $institution,
                account: $account,
                creditCard: $creditCard,
                billDueYear: $billDueYear,
                billDueMonth: $billDueMonth,
                allowInactiveSource: $this->include_inactive_sources,
            );
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);
            $this->addError('statement', 'The statement could not be uploaded. Please try again.');

            return;
        }

        $this->reset('statement');
        unset($this->imports);
        session()->flash('status', 'Statement uploaded securely.');
    }
}; ?>

<section class="mx-auto w-full max-w-6xl space-y-6">
    <div>
        <flux:heading size="xl" level="1">Statement Imports</flux:heading>
        <flux:text class="mt-2">Upload PDF statements with their financial context and track their import status.</flux:text>
    </div>

    @if (session('status'))
        <flux:callout>{{ session('status') }}</flux:callout>
    @endif

    <form wire:submit="createImport" class="space-y-4 rounded-xl border border-zinc-200 p-6 dark:border-zinc-700">
        <div>
            <flux:heading size="lg">Upload a statement</flux:heading>
            <flux:text class="mt-1">Choose the document context before upload. PDFs remain private, and no Expenses are created in this phase.</flux:text>
        </div>

        <flux:select wire:model.live="document_type" label="Document Type" placeholder="Choose a document type">
            @foreach (StatementDocumentType::cases() as $documentType)
                <flux:select.option value="{{ $documentType->value }}" wire:key="document-type-{{ $documentType->value }}">{{ $documentType->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:error name="document_type" />

        <flux:select wire:model="institution" label="Institution" placeholder="Choose an institution">
            @foreach (StatementInstitution::cases() as $statementInstitution)
                <flux:select.option value="{{ $statementInstitution->value }}" wire:key="institution-{{ $statementInstitution->value }}">{{ $statementInstitution->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:error name="institution" />

        @if ($document_type === StatementDocumentType::BankStatement->value)
            <div class="space-y-3">
                <flux:select wire:model="account_id" label="Account" placeholder="Choose an account">
                    @foreach ($this->accounts as $account)
                        <flux:select.option value="{{ $account->id }}" wire:key="statement-account-{{ $account->id }}">{{ $account->name }}{{ $account->is_active ? '' : ' (Inactive)' }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="account_id" />
                <flux:text>Bank statements are associated with one of your Accounts.</flux:text>
                <flux:checkbox wire:model.live="include_inactive_sources" label="Include historical/inactive sources" />
            </div>
        @elseif ($document_type === StatementDocumentType::CreditCardBill->value)
            <div class="space-y-3">
                <flux:select wire:model="credit_card_id" label="Credit Card" placeholder="Choose a credit card">
                    @foreach ($this->creditCards as $creditCard)
                        <flux:select.option value="{{ $creditCard->id }}" wire:key="statement-credit-card-{{ $creditCard->id }}">{{ $creditCard->name }}{{ $creditCard->is_active ? '' : ' (Inactive)' }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="credit_card_id" />
                <flux:text>Credit card bills are associated with one of your Credit Cards.</flux:text>
                <flux:checkbox wire:model.live="include_inactive_sources" label="Include historical/inactive sources" />
            </div>

            <flux:input type="month" wire:model="bill_due_month" label="Bill Due Month" min="1000-01" max="9999-12" />
            <flux:error name="bill_due_month" />
        @endif

        <flux:input type="file" wire:model="statement" label="PDF Statement" accept="application/pdf,.pdf" />
        <flux:error name="statement" />

        <flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="statement,createImport">
            <span wire:loading.remove wire:target="statement,createImport">Upload statement</span>
            <span wire:loading wire:target="statement,createImport">Uploading…</span>
        </flux:button>
    </form>

    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-left text-sm">
            <caption class="sr-only">Your statement imports</caption>
            <thead class="bg-zinc-50 dark:bg-zinc-900">
                <tr>
                    <th scope="col" class="px-4 py-3">Filename</th>
                    <th scope="col" class="px-4 py-3">Document Type</th>
                    <th scope="col" class="px-4 py-3">Institution</th>
                    <th scope="col" class="px-4 py-3">Account / Credit Card</th>
                    <th scope="col" class="px-4 py-3">Due Month</th>
                    <th scope="col" class="px-4 py-3">Status</th>
                    <th scope="col" class="px-4 py-3">Uploaded at</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($this->imports as $statementImport)
                    @php($hasContext = $statementImport->hasImportContext())
                    <tr wire:key="statement-import-{{ $statementImport->id }}">
                        <td class="px-4 py-3">{{ $statementImport->original_filename }}</td>
                        <td class="px-4 py-3">{{ $hasContext ? $statementImport->document_type->label() : 'Context required' }}</td>
                        <td class="px-4 py-3">{{ $hasContext ? $statementImport->institution->label() : '—' }}</td>
                        <td class="px-4 py-3">
                            @if ($hasContext)
                                {{ $statementImport->document_type === StatementDocumentType::BankStatement ? $statementImport->account?->name : $statementImport->creditCard?->name }}
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            {{ $hasContext && $statementImport->document_type === StatementDocumentType::CreditCardBill ? CarbonImmutable::createFromDate($statementImport->bill_due_year, $statementImport->bill_due_month, 1)->format('F Y') : '—' }}
                        </td>
                        <td class="px-4 py-3"><flux:badge color="blue">{{ $statementImport->status->label() }}</flux:badge></td>
                        <td class="px-4 py-3">{{ $statementImport->created_at?->format('Y-m-d H:i') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center">
                            <flux:heading size="lg">No statements imported yet.</flux:heading>
                            <flux:text class="mt-1">Upload a PDF statement to begin.</flux:text>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
