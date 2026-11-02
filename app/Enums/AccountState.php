<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\UserPhone;

/**
 * What an account means for the accounts page, one of five groups of AccountStatus:
 * the page's filter tabs, the order of the list, the colour of a row.
 *
 *   active   authorized, sends
 *   paused   authorized, Telegram asked to wait (flood_until in the future)
 *   login    a login on the way (a worker step or a code/password to type)
 *   problem  the session died or the login failed (and any unknown status)
 *   off      logged out on purpose
 */
enum AccountState: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Login = 'login';
    case Problem = 'problem';
    case Off = 'off';

    public static function of(UserPhone $phone): self
    {
        return self::for($phone->status, $phone->isFlooded());
    }

    public static function for(AccountStatus $status, bool $flooded): self
    {
        return match (true) {
            $status === AccountStatus::Authorized => $flooded ? self::Paused : self::Active,
            in_array($status, self::Login->statuses(), true) => self::Login,
            in_array($status, self::Off->statuses(), true) => self::Off,
            default => self::Problem,
        };
    }

    /**
     * The statuses of a group whose status alone decides it (Active and Paused split
     * Authorized by flood_until; Problem is everything the others do not take).
     *
     * @return list<AccountStatus>
     */
    public function statuses(): array
    {
        return match ($this) {
            self::Active, self::Paused => [AccountStatus::Authorized],
            self::Login => [
                AccountStatus::RequestingCode, AccountStatus::CodeSent, AccountStatus::Verifying,
                AccountStatus::NeedPassword, AccountStatus::CodeInvalid, AccountStatus::PasswordInvalid,
            ],
            self::Off => [AccountStatus::LoggingOut, AccountStatus::LoggedOut],
            self::Problem => [AccountStatus::Revoked, AccountStatus::Failed],
        };
    }

    /** Position in the list: working accounts first, logged out ones last. */
    public function rank(): int
    {
        return match ($this) {
            self::Active => 0,
            self::Paused => 1,
            self::Login => 2,
            self::Problem => 3,
            self::Off => 4,
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Paused => 'warning',
            self::Login => 'info',
            self::Problem => 'error',
            self::Off => 'neutral',
        };
    }

    public function label(): string
    {
        return __("enums.account_state.{$this->value}");
    }
}
