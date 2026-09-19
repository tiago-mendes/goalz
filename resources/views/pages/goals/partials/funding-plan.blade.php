@if ($fundingPlan->deadlineState === 'active')
    <div class="mt-2 space-y-0.5 text-xs text-zinc-600 dark:text-zinc-400">
        <div><x-money :currency="auth()->user()->currency" :amount="$fundingPlan->requiredPerMonth" />/month needed</div>
        <div>{{ $fundingPlan->monthsRemaining }} months remaining</div>
    </div>
@elseif ($fundingPlan->deadlineState === 'due_today')
    <div class="mt-2 space-y-0.5 text-xs text-zinc-600 dark:text-zinc-400">
        <div>Due today</div>
        <div><x-money :currency="auth()->user()->currency" :amount="$fundingPlan->remainingAmount" /> remaining</div>
    </div>
@elseif ($fundingPlan->deadlineState === 'overdue')
    <div class="mt-2 space-y-0.5 text-xs text-zinc-600 dark:text-zinc-400">
        <div>Target date passed</div>
        <div><x-money :currency="auth()->user()->currency" :amount="$fundingPlan->remainingAmount" /> remaining</div>
    </div>
@elseif ($fundingPlan->deadlineState === 'funded')
    <div class="mt-2 text-xs text-zinc-600 dark:text-zinc-400">Funding target reached</div>
@endif
