<?php

namespace App;

enum StatementInstitution: string
{
    case Itau = 'itau';

    public function label(): string
    {
        return match ($this) {
            self::Itau => 'Itaú',
        };
    }
}
