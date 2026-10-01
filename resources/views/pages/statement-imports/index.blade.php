<?php

use App\Actions\CreateStatementImport;
use App\Models\StatementImport;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\File;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Statement Imports')] class extends Component {
    use WithFileUploads;

    public $statement;

    public function boot(): void
    {
        Gate::authorize('viewAny', StatementImport::class);
    }

    /** @return Collection<int, StatementImport> */
    #[Computed]
    public function imports(): Collection
    {
        return auth()->user()->statementImports()
            ->select(['id', 'user_id', 'original_filename', 'status', 'source', 'created_at'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    public function upload(CreateStatementImport $createStatementImport): void
    {
        Gate::authorize('create', StatementImport::class);

        $validated = $this->validate([
            'statement' => ['required', File::types(['pdf'])->max(10 * 1024)],
        ], [
            'statement.required' => 'Choose a PDF statement to upload.',
            'statement.max' => 'The statement must not be larger than 10 MB.',
            'statement.mimes' => 'The statement must be a valid PDF file.',
        ]);

        /** @var UploadedFile $statement */
        $statement = $validated['statement'];

        try {
            $createStatementImport->handle(auth()->user(), $statement);
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
        <flux:text class="mt-2">Upload PDF statements and track their import status.</flux:text>
    </div>

    @if (session('status'))
        <flux:callout>{{ session('status') }}</flux:callout>
    @endif

    <form wire:submit="upload" class="space-y-4 rounded-xl border border-zinc-200 p-6 dark:border-zinc-700">
        <div>
            <flux:heading size="lg">Upload a statement</flux:heading>
            <flux:text class="mt-1">PDF statements are stored privately and are not imported into Expenses until you review and confirm transactions.</flux:text>
        </div>

        <flux:input type="file" wire:model="statement" label="PDF statement" accept="application/pdf,.pdf" />
        <flux:error name="statement" />

        <flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="statement,upload">
            <span wire:loading.remove wire:target="statement,upload">Upload statement</span>
            <span wire:loading wire:target="statement,upload">Uploading…</span>
        </flux:button>
    </form>

    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-left text-sm">
            <caption class="sr-only">Your statement imports</caption>
            <thead class="bg-zinc-50 dark:bg-zinc-900">
                <tr>
                    <th scope="col" class="px-4 py-3">Filename</th>
                    <th scope="col" class="px-4 py-3">Source</th>
                    <th scope="col" class="px-4 py-3">Status</th>
                    <th scope="col" class="px-4 py-3">Uploaded at</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($this->imports as $statementImport)
                    <tr wire:key="statement-import-{{ $statementImport->id }}">
                        <td class="px-4 py-3">{{ $statementImport->original_filename }}</td>
                        <td class="px-4 py-3">{{ $statementImport->source ?? 'Not detected' }}</td>
                        <td class="px-4 py-3"><flux:badge color="blue">{{ $statementImport->status->label() }}</flux:badge></td>
                        <td class="px-4 py-3">{{ $statementImport->created_at?->format('Y-m-d H:i') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-8 text-center">
                            <flux:heading size="lg">No statements imported yet.</flux:heading>
                            <flux:text class="mt-1">Upload a PDF statement to begin.</flux:text>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>
