<?php

declare(strict_types=1);

namespace App\Jobs\Accounts;

use App\Accounts\AccountSessions;
use App\Enums\AccountStatus;
use App\Telegram\Contracts\TelegramGateway;
use App\Telegram\Errors\ErrorKind;
use App\Telegram\Errors\TelegramError;
use App\Telegram\ProcessLock;
use Throwable;

/**
 * Opens the session, refreshes the profile, and retires the account if the session
 * is dead. Never on a session a send job holds: it tries again a minute later.
 */
final class CheckAccountSession extends AccountJob
{
    public function handle(TelegramGateway $telegram, AccountSessions $sessions): void
    {
        $account = $this->account();
        if ($account === null || $account->session_path === null) {
            return;
        }

        $lock = ProcessLock::for("account-{$account->id}");
        if (! $lock->acquire()) {
            self::dispatch($account->id)->delay(now()->addMinute());

            return;
        }

        $session = null;
        try {
            $session = $telegram->open($account->session_path);
            $profile = $session->me();
        } catch (Throwable $e) {
            $error = TelegramError::from($e);
            $error->kind === ErrorKind::DeadSession
                ? $sessions->retire($account->id, $error)
                : $account->update(['last_error' => $error->key, 'last_checked_at' => now()]);

            return;
        } finally {
            $session?->close();
            $lock->release();
        }

        // A logout (or a new login) that started meanwhile wins over this check.
        $account->refresh();
        // a pause keeps its reason (peer_flood, worker_timeout ...) until it ends
        $columns = $profile->toColumns() + ['last_checked_at' => now(), 'last_error' => $account->isFlooded() ? $account->last_error : null];
        if (in_array($account->status, [AccountStatus::Authorized, AccountStatus::Revoked], true)) {
            $columns['status'] = AccountStatus::Authorized;
        }
        $account->update($columns);
    }

    public function failed(?Throwable $e): void
    {
        $this->account()?->update(['last_error' => 'check_failed', 'last_checked_at' => now()]);
    }
}
