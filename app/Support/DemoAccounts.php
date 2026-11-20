<?php

namespace App\Support;

/**
 * The public demo logins (owner@demo.bookly.test, customer@demo.bookly.test, …)
 * are shared by every visitor of the portfolio demo. Their email and password
 * must stay as published, so nobody can lock the next visitor out. Everything
 * else a visitor changes is undone by the daily `demo:reset`.
 */
final class DemoAccounts
{
    public const DOMAIN = '@demo.bookly.test';

    public const MESSAGE = "This is a shared demo account, so its email and password can't be changed.";

    public static function isDemo(?string $email): bool
    {
        return $email !== null && str_ends_with(strtolower(trim($email)), self::DOMAIN);
    }

    /**
     * True when $changes would alter the login of the demo account $email:
     * a non-empty password, or an email different from the current one.
     *
     * @param  array<string, mixed>  $changes
     */
    public static function changesLogin(?string $email, array $changes): bool
    {
        if (! self::isDemo($email)) {
            return false;
        }

        $newEmail = $changes['email'] ?? null;

        return ! empty($changes['password'])
            || ($newEmail !== null && strtolower(trim((string) $newEmail)) !== strtolower(trim((string) $email)));
    }
}
