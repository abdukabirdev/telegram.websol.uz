<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Accounts\AccountSessions;
use App\ChatLogs\ChatHistoryFetcher;
use App\Enums\EventKind;
use App\Models\ChatLogGroup;
use App\Monitoring\EventRecorder;
use App\Telegram\Contracts\TelegramGateway;
use App\Telegram\Errors\ErrorKind;
use App\Telegram\Errors\TelegramError;
use App\Telegram\ProcessLock;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

final class FetchChatHistory implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly int $chatLogGroupId, public readonly int $limit = 100)
    {
        $this->onQueue(config('telegram.queue'));
    }

    public function handle(TelegramGateway $telegram, ChatHistoryFetcher $fetcher, AccountSessions $accounts, EventRecorder $events): void
    {
        $group = ChatLogGroup::query()->with('phone')->find($this->chatLogGroupId);
        $phone = $group?->phone;
        if ($group === null || $phone === null || ! $phone->canSend()) {
            return;
        }

        // A fetch can hold a process for minutes: only in a free slot, so a login never waits behind it.
        $slot = WorkerSlots::take($this->timeout);
        if ($slot === null) {
            self::dispatch($this->chatLogGroupId, $this->limit)->delay(now()->addSeconds(30));

            return;
        }

        // Never next to a send job on the same session: come back when it is done.
        $lock = ProcessLock::for("account-{$phone->id}");
        if (! $lock->acquire()) {
            $slot->release();
            self::dispatch($this->chatLogGroupId, $this->limit)->delay(now()->addMinutes(2));

            return;
        }

        $session = null;
        try {
            $session = $telegram->open((string) $phone->session_path);
            $fetcher->fetch($session, $group, $phone->telegram_user_id, $this->limit);
        } catch (Throwable $e) {
            $error = TelegramError::from($e);
            $error->kind === ErrorKind::DeadSession
                ? $accounts->retire($phone->id, $error)
                : $events->warning(EventKind::Api, 'Chat history fetch failed', ['peer' => $group->peer, 'raw' => $error->raw], $phone->id, errorKey: $error->key);
        } finally {
            $session?->close();
            $lock->release();
            $slot->release();
        }
    }
}
