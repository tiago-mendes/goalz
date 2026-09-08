<?php

namespace App\Reports;

use App\AccountType;
use App\Models\Account;
use App\Models\AccountBalanceSnapshot;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

class AssetsReport
{
    /**
     * @return array{
     *     accounts: Collection<int, Account>,
     *     totalAssets: string,
     *     allocatedAssets: string,
     *     freeAssets: string,
     *     overallocatedAssets: string,
     *     overallocatedAccounts: int,
     *     accountTypes: list<array{key: string, name: string, amount: string, percentage: string, color: string}>,
     *     distribution: list<array{key: int, name: string, type: string, balance: string, share: string}>,
     *     accountEvolution: list<array{key: int, label: string, balance: string}>,
     *     selectedAccount: Account|null,
     */
    public function handle(User $user, CarbonImmutable $from, CarbonImmutable $to, ?int $accountId = null): array
    {
        $accounts = $this->accounts($user);
        $activeAccounts = $accounts->where('is_active', true);
        $totalAssets = BigDecimal::zero();
        $allocatedAssets = BigDecimal::zero();
        $freeAssets = BigDecimal::zero();
        $overallocatedAssets = BigDecimal::zero();
        $overallocatedAccounts = 0;

        foreach ($activeAccounts as $account) {
            $totalAssets = $totalAssets->plus($account->current_balance);
            $allocatedAssets = $allocatedAssets->plus($account->allocatedAmount());
            $freeAssets = $freeAssets->plus($account->availableAmount());
            $overallocatedAssets = $overallocatedAssets->plus($account->overallocatedAmount());
            $overallocatedAccounts += BigDecimal::of($account->overallocatedAmount())->isPositive() ? 1 : 0;
        }

        $accountTypes = array_map(function (AccountType $type) use ($activeAccounts, $totalAssets): array {
            $amount = BigDecimal::zero();

            foreach ($activeAccounts->where('type', $type) as $account) {
                $amount = $amount->plus($account->current_balance);
            }

            return [
                'key' => $type->value,
                'name' => $type->label(),
                'amount' => (string) $amount->toScale(2),
                'percentage' => $this->percentage($amount, $totalAssets),
                'color' => $this->typeColor($type),
            ];
        }, AccountType::cases());

        $distribution = $activeAccounts->map(fn (Account $account): array => [
            'key' => $account->id,
            'name' => $account->name,
            'type' => $account->type->label(),
            'balance' => (string) BigDecimal::of($account->current_balance)->toScale(2),
            'share' => $this->percentage(BigDecimal::of($account->current_balance), $totalAssets),
        ])->values()->all();

        $selectedAccount = $accountId === null ? null : $accounts->firstWhere('id', $accountId);
        $accountEvolution = $selectedAccount === null
            ? []
            : $selectedAccount->balanceSnapshots()
                ->whereBetween('recorded_at', [$from->startOfMonth(), $to->endOfMonth()])
                ->orderBy('recorded_at')
                ->orderBy('id')
                ->get(['id', 'balance', 'recorded_at'])
                ->map(fn (AccountBalanceSnapshot $snapshot): array => [
                    'key' => $snapshot->id,
                    'label' => $snapshot->recorded_at->format('M j, Y H:i'),
                    'balance' => (string) BigDecimal::of($snapshot->balance)->toScale(2),
                ])->all();

        return [
            'accounts' => $accounts,
            'totalAssets' => (string) $totalAssets->toScale(2),
            'allocatedAssets' => (string) $allocatedAssets->toScale(2),
            'freeAssets' => (string) $freeAssets->toScale(2),
            'overallocatedAssets' => (string) $overallocatedAssets->toScale(2),
            'overallocatedAccounts' => $overallocatedAccounts,
            'accountTypes' => $accountTypes,
            'distribution' => $distribution,
            'accountEvolution' => $accountEvolution,
            'selectedAccount' => $selectedAccount,
        ];
    }

    /** @return Collection<int, Account> */
    public function accounts(User $user): Collection
    {
        return $user->accounts()
            ->with('goalAccountAllocations')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    private function percentage(BigDecimal $amount, BigDecimal $total): string
    {
        if ($total->isZero()) {
            return '0.0';
        }

        return (string) $amount->multipliedBy(100)->dividedBy($total, 1, RoundingMode::HalfUp);
    }

    private function typeColor(AccountType $type): string
    {
        return match ($type) {
            AccountType::Checking => '#315D40',
            AccountType::Savings => '#2563EB',
            AccountType::Investment => '#7C3AED',
            AccountType::Cash => '#B45309',
            AccountType::Other => '#64748B',
        };
    }
}
