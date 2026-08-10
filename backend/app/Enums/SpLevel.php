<?php

namespace App\Enums;

enum SpLevel: string
{
    case Aman = 'aman';
    case Sp1 = 'sp1';
    case Sp2 = 'sp2';
    case Sp3 = 'sp3';
    case Do = 'do';

    public function label(): string
    {
        return match ($this) {
            self::Aman => 'Aman',
            self::Sp1 => 'SP1',
            self::Sp2 => 'SP2',
            self::Sp3 => 'SP3',
            self::Do => 'DO',
        };
    }

    public function next(): ?self
    {
        return match ($this) {
            self::Aman => self::Sp1,
            self::Sp1 => self::Sp2,
            self::Sp2 => self::Sp3,
            self::Sp3 => self::Do,
            self::Do => null,
        };
    }

    public function notificationFlag(): ?string
    {
        return $this === self::Aman ? null : "notified_{$this->value}";
    }

    public function approachingFlag(): string
    {
        return "notified_approaching_{$this->value}";
    }

    public function approachingCode(): string
    {
        return "approaching_{$this->value}";
    }

    public function isUrgent(): bool
    {
        return $this === self::Sp3 || $this === self::Do;
    }
}
