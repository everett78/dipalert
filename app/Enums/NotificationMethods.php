<?php

namespace App\Enums;

/**
 * DipAlert keeps only e-mail and the in-app notification history.
 */
enum NotificationMethods: string
{
    case Mail = 'mail';

    case Database = 'database';

    public function getChannel(): string
    {
        return $this->value;
    }

    public function requiresUserSettings(): bool
    {
        return $this !== self::Database;
    }
}
