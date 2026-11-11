<?php

namespace App\Enums;

use Carbon\CarbonInterface;
use Filament\Support\Contracts\HasLabel;

enum Recurrence: string implements HasLabel
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Monthly = 'monthly';

    public function getLabel(): string
    {
        return __(ucfirst($this->value));
    }

    public function next(CarbonInterface $date): CarbonInterface
    {
        return match ($this) {
            self::Daily => $date->copy()->addDay(),
            self::Weekly => $date->copy()->addWeek(),
            self::Monthly => $date->copy()->addMonthNoOverflow(),
        };
    }
}
