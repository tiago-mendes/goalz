<?php

use App\Actions\ResolveMonthlyIncome;
use App\Models\MonthlyIncome;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Monthly Income')] class extends Component {
    use WithPagination;

    public string $month = '';
    #[Locked]
    public string $selectedMonth = '';
    public ?string $default_monthly_income = null;
    public string $amount = '';

    public function boot(): void
    {
        Gate::authorize('viewAny', MonthlyIncome::class);
    }

    public function mount(): void
    {
        $this->default_monthly_income = auth()->user()->default_monthly_income;
        $this->month = now()->format('Y-m');
        $this->openMonth();
    }

    public function openMonth(): void
    {
        $this->validate(['month' => ['required', 'date_format:Y-m', 'after_or_equal:1000-01', 'before_or_equal:9999-12']]);
        $this->selectedMonth = $this->month;
        $income = app(ResolveMonthlyIncome::class)->handle(auth()->user(), ...$this->period());

        $this->amount = $income?->amount ?? '';
        $this->resetValidation();
        unset($this->income, $this->history);
    }

    public function saveDefault(): void
    {
        $validated = $this->validate(['default_monthly_income' => ['nullable', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,12})(?:\.[0-9]{1,2})?\z/']]);
        $user = auth()->user();
        $user->default_monthly_income = $validated['default_monthly_income'] === '' ? null : $validated['default_monthly_income'];
        $user->save();
        $this->default_monthly_income = $user->default_monthly_income;
        session()->flash('status', 'Default income saved. Existing monthly records are unchanged.');
    }

    public function saveMonth(): void
    {
        $validated = $this->validate(['amount' => ['required', 'string', 'regex:/\A(?:0|[1-9][0-9]{0,12})(?:\.[0-9]{1,2})?\z/']]);
        $income = auth()->user()->monthlyIncomes()->where($this->period())->first();
        Gate::authorize($income ? 'update' : 'create', $income ?? MonthlyIncome::class);

        if ($income === null) {
            $income = auth()->user()->monthlyIncomes()->firstOrCreate($this->period(), $validated);
        } else {
            $income->amount = $validated['amount'];
            $income->save();
        }

        $this->amount = $income->amount;
        unset($this->income, $this->history);
        session()->flash('status', 'Monthly income saved.');
    }

    #[Computed]
    public function income(): ?MonthlyIncome
    {
        return auth()->user()->monthlyIncomes()->where($this->period())->first();
    }

    #[Computed]
    public function history(): LengthAwarePaginator
    {
        return auth()->user()->monthlyIncomes()->orderByDesc('year')->orderByDesc('month')->paginate(12);
    }

    /** @return array{year: int, month: int} */
    private function period(): array
    {
        return ['year' => (int) substr($this->selectedMonth, 0, 4), 'month' => (int) substr($this->selectedMonth, 5, 2)];
    }
}; ?>

<section class="mx-auto w-full max-w-4xl space-y-6">
    <flux:heading size="xl" level="1">Monthly Income</flux:heading>
    @if (session('status'))
        <flux:callout>{{ session('status') }}</flux:callout>
    @endif

    <div class="space-y-4 rounded-xl border border-zinc-200 p-6 dark:border-zinc-700">
        <flux:heading size="lg" level="2">Default monthly income</flux:heading>
        <flux:text>Used when you first open a month without a saved income. Changing this leaves existing months unchanged.</flux:text>
        <form wire:submit="saveDefault" class="space-y-4">
            <flux:input wire:model="default_monthly_income" :label="'Default income ('.auth()->user()->currency.')'" placeholder="Not configured" inputmode="decimal" />
            <flux:text>Leave blank for no default. Zero is a valid income.</flux:text>
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled">Save default</flux:button>
        </form>
    </div>

    <div class="space-y-4 rounded-xl border border-zinc-200 p-6 dark:border-zinc-700">
        <form wire:submit="openMonth" class="flex flex-wrap items-end gap-3">
            <flux:input wire:model="month" label="Month" type="month" min="1000-01" max="9999-12" required />
            <flux:button type="submit" wire:loading.attr="disabled">Open month</flux:button>
        </form>
        <flux:heading size="lg" level="2">{{ Carbon::createFromFormat('!Y-m', $selectedMonth)->format('F Y') }}</flux:heading>
        <flux:text>{{ $this->income ? auth()->user()->currency.' '.$this->income->amount : 'No income configured for this month yet.' }}</flux:text>
        <form wire:submit="saveMonth" class="space-y-4">
            <flux:input wire:model="amount" :label="'Monthly income ('.auth()->user()->currency.')'" inputmode="decimal" placeholder="0.00" required />
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled">{{ $this->income ? 'Save monthly income' : 'Create monthly income' }}</flux:button>
        </form>
    </div>

    <flux:heading size="lg" level="2">History</flux:heading>
    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
        <table class="w-full text-left text-sm">
            <caption class="sr-only">Your saved monthly income records</caption>
            <thead class="bg-zinc-50 dark:bg-zinc-900"><tr><th scope="col" class="px-4 py-3">Month</th><th scope="col" class="px-4 py-3">Income</th></tr></thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($this->history as $record)
                    <tr wire:key="income-{{ $record->id }}">
                        <td class="px-4 py-3">{{ Carbon::create($record->year, $record->month, 1)->format('F Y') }}</td>
                        <td class="px-4 py-3">{{ auth()->user()->currency }} {{ $record->amount }}</td>
                    </tr>
                @empty
                    <tr><td colspan="2" class="px-4 py-6">No monthly income records yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $this->history->links() }}
</section>
