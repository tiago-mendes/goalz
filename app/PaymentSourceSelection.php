<?php

namespace App;

use App\Models\User;
use Illuminate\Validation\ValidationException;

final class PaymentSourceSelection
{
    public static function fromIds(?int $accountId, ?int $creditCardId): string
    {
        if ($accountId !== null) {
            return 'account:'.$accountId;
        }

        if ($creditCardId !== null) {
            return 'credit-card:'.$creditCardId;
        }

        return '';
    }

    /** @return array{payment_account_id: ?int, credit_card_id: ?int} */
    public static function resolve(
        User $user,
        mixed $selection,
        ?int $currentAccountId = null,
        ?int $currentCreditCardId = null,
    ): array {
        if ($selection === null || $selection === '') {
            return ['payment_account_id' => null, 'credit_card_id' => null];
        }

        if (! is_string($selection) || preg_match('/\A(account|credit-card):([1-9][0-9]*)\z/', $selection, $matches) !== 1) {
            self::invalid();
        }

        $id = (int) $matches[2];

        if ($matches[1] === 'account') {
            $account = $user->accounts()->find($id);
            if ($account === null || (! $account->is_active && $currentAccountId !== $account->id)) {
                self::invalid();
            }

            return ['payment_account_id' => $account->id, 'credit_card_id' => null];
        }

        $creditCard = $user->creditCards()->find($id);
        if ($creditCard === null || (! $creditCard->is_active && $currentCreditCardId !== $creditCard->id)) {
            self::invalid();
        }

        return ['payment_account_id' => null, 'credit_card_id' => $creditCard->id];
    }

    private static function invalid(): never
    {
        throw ValidationException::withMessages([
            'payment_source' => 'Choose one of your active payment sources, or keep this record’s current payment source.',
        ]);
    }
}
