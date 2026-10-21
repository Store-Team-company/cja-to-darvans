<?php

namespace App\Services;

class BdpSizeService
{
    public static function toUsWomen(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (stripos($value, 'US ') === 0) {
            return $value;
        }

        $map = [
            '35' => 'US 5',
            '36' => 'US 6',
            '37' => 'US 6.5',
            '38' => 'US 7.5',
            '39' => 'US 8.5',
            '40' => 'US 9',
            '41' => 'US 10',
            '42' => 'US 10.5',
            '43' => 'US 11',
        ];

        return $map[$value] ?? $value;
    }
}
