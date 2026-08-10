<?php

namespace EvoSC\Modules\WarManager\Classes;

use DomainException;

final class WarState
{
    public const DRAFT = 'DRAFT';
    public const ACTIVE = 'ACTIVE';
    public const PAUSED = 'PAUSED';
    public const FINISHED = 'FINISHED';
    public const CANCELLED = 'CANCELLED';

    private const TRANSITIONS = [
        self::DRAFT => [self::ACTIVE, self::CANCELLED],
        self::ACTIVE => [self::PAUSED, self::FINISHED, self::CANCELLED],
        self::PAUSED => [self::ACTIVE, self::FINISHED, self::CANCELLED],
    ];

    public static function assertTransition(string $from, string $to): void
    {
        if (!in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw new DomainException("Invalid war transition: {$from} -> {$to}");
        }
    }
}
