<?php

declare(strict_types=1);

namespace App\Sending;

use App\Accounts\AccountSessions;
use App\Enums\EventKind;
use App\Enums\GroupStatus;
use App\Enums\MessageStatus;
use App\Enums\TaskStatus;
use App\Jobs\Accounts\CheckAccountSession;
use App\Models\MessageGroup;
use App\Models\MessageTask;
use App\Models\TelegramMessage;
use App\Models\UserPhone;
use App\Monitoring\EventRecorder;
use App\Settings\Settings;
use App\Telegram\Contracts\TelegramGateway;
use App\Telegram\Contracts\TelegramSession;
use App\Telegram\Errors\ErrorKind;
use App\Telegram\Errors\TelegramError;
use App\Telegram\OutgoingFile;
use App\Telegram\Phone;
use App\Telegram\ProcessLock;
use Closure;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Sends what is due for ONE account, in one process, for a limited time.
 * Runs inside the SendAccountMessages queue job.
 *
 * Each row is claimed with a conditional UPDATE (pending -> sending) before it is
 * sent, so a row can't go out twice even if two workers ever raced. The process
 * holds the account's ProcessLock, so two workers never share a session.
 *
 * A run takes at most worker.messages_per_run messages (1: every message in a fresh
 * process and session, the process that opened one exits after the job).
 *
 * The sends (groups) of one account take turns, one message each, so a big send
 * started first does not hold back the ones started after it.
 *
 * Outcomes (read by the job):
 *   DONE           nothing left, or the account must wait (flood, daily limit)
 *   RETRY          failed, run again later with backoff
 *   NOT_STARTABLE  not authorized / session dead
 *   BUSY           another process holds this account's session
 *   MORE           time is up or a stop was asked with messages left: run again in resumeIn() seconds
 */
final class SendWorker
{
    public const DONE = 0;

    public const RETRY = 1;

    public const NOT_STARTABLE = 2;

    public const BUSY = 3;

    public const MORE = 4;

    private const TRANSIENT_STREAK_LIMIT = 5;

    /** A message that failed on a network or Telegram hiccup goes again after the others, this much later. */
    public const TRANSIENT_RETRY_SECONDS = 60;

    /** @var array<int, array{text: string, file: ?OutgoingFile, missing: bool}> group id => what to send */
    private array $payloads = [];

    private int $transientStreak = 0;

    private bool $stopping = false;

    private int $deadline = 0;

    private int $resumeIn = 0;

    private mixed $restartSignal = null;

    /** @var Closure(int): void */
    private Closure $sleeper;

    public function __construct(
        private readonly TelegramGateway $telegram,
        private readonly AccountSessions $accounts,
        private readonly GroupProgress $progress,
        private readonly DailyLimit $dailyLimit,
        private readonly Settings $settings,
        private readonly EventRecorder $events,
    ) {
        $this->sleeper = fn (int $seconds) => sleep($seconds);
    }

    /** Tests replace sleeping with a no-op. */
    public function sleepUsing(Closure $sleeper): self
    {
        $this->sleeper = $sleeper;

        return $this;
    }

    /** Finish the message in hand, then return MORE (SIGTERM from a deploy). */
    public function stop(): void
    {
        $this->stopping = true;
    }

    /** After MORE: seconds to wait before the next run, so the pause between messages holds. */
    public function resumeIn(): int
    {
        return $this->resumeIn;
    }

    public function run(int $accountId, int $maxRuntime): int
    {
        $lock = ProcessLock::for("account-{$accountId}");
        if (! $lock->acquire()) {
            return self::BUSY;
        }

        $this->deadline = time() + $maxRuntime;
        $this->resumeIn = 0;
        $this->restartSignal = Cache::get('illuminate:queue:restart');
        Cache::put(self::runningKey($accountId), ['pid' => getmypid(), 'started_at' => time()], $maxRuntime + 300);

        try {
            $this->previousRunDied($accountId);
            $this->markUnfinished($accountId, null);
            $outcome = $this->work($accountId);
            Cache::forget(self::deadRunsKey($accountId));

            return $outcome;
        } finally {
            Cache::forget(self::runningKey($accountId));
            Cache::forget(self::unfinishedKey($accountId));
            $lock->release();
        }
    }

    /** Set while a worker sends for this account (monitoring). */
    public static function runningKey(int $accountId): string
    {
        return "telegram:send-running:{$accountId}";
    }

    /**
     * Set for the whole run and removed when it returns. Still there at the next run
     * means the process never got back: killed at the job timeout (a Telegram call
     * that hung) or died. Neither leaves an exception, so this is the only trace.
     */
    public static function unfinishedKey(int $accountId): string
    {
        return "telegram:send-unfinished:{$accountId}";
    }

    /** The group this account's last message came from: the next one comes from the group after it. */
    public static function lastGroupKey(int $accountId): string
    {
        return "telegram:send-last-group:{$accountId}";
    }

    /** Runs in a row of this account that never returned. */
    public static function deadRunsKey(int $accountId): string
    {
        return "telegram:send-dead-runs:{$accountId}";
    }

    private function markUnfinished(int $accountId, ?int $messageId): void
    {
        $started = Cache::get(self::unfinishedKey($accountId))['started_at'] ?? time();
        Cache::put(self::unfinishedKey($accountId), [
            'pid' => getmypid(),
            'started_at' => $started,
            'message_id' => $messageId,
        ], now()->addDay());
    }

    /**
     * Records a previous run that never returned. The message it had in hand fails
     * (worker_timeout) instead of going again, so one peer Telegram hangs on never
     * holds back the account's other messages; the session is checked in case it
     * is the account itself that hangs.
     */
    private function previousRunDied(int $accountId): void
    {
        $previous = Cache::pull(self::unfinishedKey($accountId));
        if (! is_array($previous)) {
            return;
        }

        Cache::add(self::deadRunsKey($accountId), 0, now()->addDay());
        $deadRuns = (int) Cache::increment(self::deadRunsKey($accountId));
        $message = isset($previous['message_id']) ? TelegramMessage::query()->find($previous['message_id']) : null;
        $this->events->error(EventKind::Worker, 'A send run of this account never finished: it hung on Telegram until the time limit or the process died', [
            'started_at' => date('Y-m-d H:i:s', (int) ($previous['started_at'] ?? 0)),
            'pid' => $previous['pid'] ?? null,
            'message_id' => $message?->id,
            'peer' => $message?->peer,
            'in_a_row' => $deadRuns,
        ], $accountId, $message?->message_group_id, 'worker_timeout');

        if ($message !== null && $message->status === MessageStatus::Sending) {
            $message->update(['status' => MessageStatus::Failed, 'error_key' => 'worker_timeout', 'claimed_at' => null, 'attempts' => $message->attempts + 1]);
            $this->progress->refresh($message->message_group_id);
        }
        // The session's worker may be what hangs: kill it, so this run opens a fresh one.
        $this->telegram->killWorker((string) UserPhone::query()->whereKey($accountId)->value('session_path'));
        CheckAccountSession::dispatch($accountId);
    }

    private function work(int $accountId): int
    {
        $account = UserPhone::query()->find($accountId);
        if ($account === null || ! $account->canSend()) {
            return self::NOT_STARTABLE;
        }

        // The pause between two messages holds across runs too.
        $sinceLast = $account->last_sent_at !== null ? (int) $account->last_sent_at->diffInSeconds(now(), true) : PHP_INT_MAX;
        if ($sinceLast < $this->settings->int('send_delay_min')) {
            ($this->sleeper)($this->settings->int('send_delay_min') - $sinceLast);
        }

        try {
            $session = $this->telegram->open((string) $account->session_path);
        } catch (Throwable $e) {
            $error = TelegramError::from($e);
            if ($error->kind === ErrorKind::DeadSession) {
                $this->accounts->retire($account->id, $error);

                return self::NOT_STARTABLE;
            }
            $this->events->warning(EventKind::Worker, 'Could not open the session', ['raw' => $error->raw], $account->id, errorKey: $error->key);

            return self::RETRY;
        }

        try {
            return $this->sendDue($session, $account);
        } finally {
            $session->close(); // the next run starts a fresh worker
        }
    }

    private function sendDue(TelegramSession $session, UserPhone $account): int
    {
        $perRun = (int) config('telegram.worker.messages_per_run');
        $handled = 0;

        while (true) {
            if ($this->shouldYield()) {
                return self::MORE;
            }

            $account->refresh();
            if (! $account->canSend() || $account->isFlooded()) {
                return self::DONE;
            }
            if ($this->dailyLimit->reached($account->id)) {
                $this->events->warning(EventKind::Sending, 'Daily limit reached, sending resumes tomorrow', [
                    'limit' => $this->dailyLimit->limit(),
                ], $account->id, errorKey: 'daily_limit');

                return self::DONE;
            }

            $outcome = ($task = $this->claimTask($account)) !== null
                ? $this->deliverTask($session, $account, $task)
                : (($message = $this->claimMessage($account)) !== null ? $this->deliverMessage($session, $account, $message) : null);

            if ($outcome === null) {
                return self::DONE; // nothing left
            }
            if ($outcome !== true) {
                return $outcome; // the account must stop
            }

            $pause = random_int(
                max(0, $this->settings->int('send_delay_min')),
                max($this->settings->int('send_delay_min'), $this->settings->int('send_delay_max')),
            );
            if ($perRun > 0 && ++$handled >= $perRun) {
                if ($this->dueTask($account->id) === null && $this->dueMessage($account->id, null) === null) {
                    return self::DONE;
                }
                $this->resumeIn = $pause; // the next message in a fresh process and session

                return self::MORE;
            }
            if (time() + $pause >= $this->deadline) {
                $this->resumeIn = $pause; // the queue waits instead of this process

                return self::MORE;
            }
            ($this->sleeper)($pause);
        }
    }

    /** Time is up, a SIGTERM arrived, or `queue:restart` was called (deploy). */
    private function shouldYield(): bool
    {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }

        return $this->stopping
            || time() >= $this->deadline
            || Cache::get('illuminate:queue:restart') !== $this->restartSignal;
    }

    /** The first due message of this account in a group after $afterGroupId (any group when null). */
    private function dueMessage(int $accountId, ?int $afterGroupId): ?TelegramMessage
    {
        return TelegramMessage::query()
            ->whereIn('message_group_id', MessageGroup::query()
                ->select('id')
                ->where('user_phone_id', $accountId)
                ->whereIn('status', GroupStatus::activeValues()))
            ->where('status', MessageStatus::Pending)
            ->where('created_at', '>=', now()->subHours((int) config('telegram.worker.expire_pending_hours')))
            ->where(fn ($q) => $q->whereNull('send_at')->orWhere('send_at', '<=', now()))
            ->when($afterGroupId !== null, fn ($q) => $q->where('message_group_id', '>', $afterGroupId))
            ->orderBy('message_group_id')
            ->orderBy('id')
            ->first();
    }

    private function dueTask(int $accountId): ?MessageTask
    {
        return MessageTask::query()
            ->where('sender_phone_id', $accountId)
            ->where('status', TaskStatus::Pending)
            ->where('created_at', '>=', now()->subHours((int) config('telegram.worker.expire_pending_hours')))
            ->orderBy('id')
            ->first();
    }

    private function claimMessage(UserPhone $account): ?TelegramMessage
    {
        for ($try = 0; $try < 5; $try++) {
            $lastGroupId = Cache::get(self::lastGroupKey($account->id));
            $candidate = $this->dueMessage($account->id, $lastGroupId)
                ?? ($lastGroupId !== null ? $this->dueMessage($account->id, null) : null);

            if ($candidate === null) {
                return null;
            }
            Cache::put(self::lastGroupKey($account->id), (int) $candidate->message_group_id, now()->addDay());

            $claimed = TelegramMessage::query()
                ->whereKey($candidate->id)
                ->where('status', MessageStatus::Pending)
                ->update(['status' => MessageStatus::Sending, 'claimed_at' => now(), 'updated_at' => now()]);

            if ($claimed === 1) {
                $this->markUnfinished($account->id, $candidate->id);
                $this->progress->markStarted($candidate->message_group_id);

                return $candidate->refresh();
            }
        }

        return null;
    }

    private function claimTask(UserPhone $account): ?MessageTask
    {
        $candidate = $this->dueTask($account->id);

        if ($candidate === null) {
            return null;
        }

        $claimed = MessageTask::query()
            ->whereKey($candidate->id)
            ->where('status', TaskStatus::Pending)
            ->update(['status' => TaskStatus::Processing, 'updated_at' => now()]);

        return $claimed === 1 ? $candidate->refresh() : null;
    }

    /** @return array{text: string, file: ?OutgoingFile, missing: bool} */
    private function payload(int $groupId): array
    {
        if (isset($this->payloads[$groupId])) {
            return $this->payloads[$groupId];
        }

        $group = MessageGroup::query()->with('attachment')->find($groupId);
        $attachment = $group?->attachment;
        $file = null;
        $missing = false;
        if ($group?->attachment_id !== null) {
            $missing = $attachment === null || $attachment->isRemoved() || ! is_file($attachment->absolutePath());
            $file = $missing ? null : new OutgoingFile(
                $attachment->absolutePath(),
                $attachment->original_name,
                $attachment->mime_type,
                $attachment->kind === 'photo',
            );
        }

        return $this->payloads[$groupId] = ['text' => (string) $group?->message_text, 'file' => $file, 'missing' => $missing];
    }

    /** @return true|int true to go on, or an outcome */
    private function deliverMessage(TelegramSession $session, UserPhone $account, TelegramMessage $message): true|int
    {
        $payload = $this->payload($message->message_group_id);
        $fail = function (string $key) use ($message): void {
            $message->update(['status' => MessageStatus::Failed, 'error_key' => $key, 'attempts' => $message->attempts + 1]);
        };

        if ($payload['missing']) {
            $fail('attachment_missing');
            $this->progress->refresh($message->message_group_id);

            return true;
        }

        try {
            $peer = $this->resolvePeer($session, (string) $message->peer);
            if ($peer === null) {
                $fail('phone_not_found');
                $this->progress->refresh($message->message_group_id);

                return true;
            }
            $telegramId = $this->deliver($session, $peer, $payload['text'], $payload['file']);
        } catch (Throwable $e) {
            $error = TelegramError::from($e);
            $verdict = $this->handleFailure($account, $error, $message->message_group_id, release: function () use ($message): void {
                $message->update(['status' => MessageStatus::Pending, 'claimed_at' => null, 'attempts' => $message->attempts + 1]);
            }, fail: $fail, attempts: $message->attempts + 1, retryLater: function () use ($message): void {
                $message->update([
                    'status' => MessageStatus::Pending,
                    'claimed_at' => null,
                    'attempts' => $message->attempts + 1,
                    'send_at' => now()->addSeconds(self::TRANSIENT_RETRY_SECONDS),
                ]);
            });
            $this->progress->refresh($message->message_group_id);

            return $verdict;
        }

        $this->transientStreak = 0;
        $message->update([
            'status' => MessageStatus::Sent,
            'sent_at' => now(),
            'telegram_message_id' => $telegramId,
            'attempts' => $message->attempts + 1,
            'error_key' => null,
        ]);
        $account->forceFill(['last_sent_at' => now()])->saveQuietly();
        $this->progress->refresh($message->message_group_id);

        return true;
    }

    /**
     * A file goes with the text as its caption; a text too long for a caption
     * follows as a second message.
     */
    private function deliver(TelegramSession $session, string|array $peer, string $text, ?OutgoingFile $file): ?int
    {
        if ($file === null) {
            return $session->sendMessage($peer, $text);
        }

        $captioned = mb_strlen($text) <= MassSend::MAX_CAPTION_LENGTH;
        $id = $session->sendFile($peer, $file, $captioned ? $text : '');
        if (! $captioned) {
            $session->sendMessage($peer, $text);
        }

        return $id;
    }

    /** "+998901234567" is looked up by phone number; anything else Telegram resolves itself. */
    private function resolvePeer(TelegramSession $session, string $peer): string|array|null
    {
        return Peers::isPhone($peer) ? $session->peerByPhone($peer) : $peer;
    }

    private function deliverTask(TelegramSession $session, UserPhone $account, MessageTask $task): true|int
    {
        try {
            $peer = $task->target_tg_id ? $session->peerByUserId((string) $task->target_tg_id) : null;
            if ($peer === null && $task->target_phone) {
                $peer = $session->peerByPhone((string) $task->target_phone);
                if ($peer === null) {
                    $task->update(['status' => TaskStatus::Failed, 'error_code' => 'TARGET_PHONE_NOT_FOUND', 'error_message' => 'Nobody with this phone, or the number is hidden']);

                    return true;
                }
            }
            if ($peer === null) {
                $task->update(['status' => TaskStatus::Failed, 'error_code' => 'TARGET_NOT_FOUND', 'error_message' => 'Neither the Telegram id nor the phone resolved']);

                return true;
            }

            $session->sendMessage($peer, (string) $task->message_text);
        } catch (Throwable $e) {
            $error = TelegramError::from($e);

            return $this->handleFailure($account, $error, null, release: function () use ($task): void {
                $task->update(['status' => TaskStatus::Pending]);
            }, fail: function (string $key) use ($task, $error): void {
                $task->update(['status' => TaskStatus::Failed, 'error_code' => strtoupper($key), 'error_message' => mb_substr($error->raw, 0, 1000)]);
            }, attempts: 1);
        }

        $this->transientStreak = 0;
        $task->update(['status' => TaskStatus::Sent, 'sent_at' => now(), 'error_code' => null, 'error_message' => null]);
        $account->forceFill(['last_sent_at' => now()])->saveQuietly();

        return true;
    }

    /**
     * What a failed send means for the row and for the account.
     *
     * @param  Closure(): void  $release  put the row back to pending
     * @param  Closure(string): void  $fail  mark the row failed with this key
     * @param  Closure(): void  $retryLater  put the row back to pending after the others
     * @return true|int true to go on with the next row, or an outcome
     */
    private function handleFailure(UserPhone $account, TelegramError $error, ?int $groupId, Closure $release, Closure $fail, int $attempts, ?Closure $retryLater = null): true|int
    {
        $settings = $this->settings;
        $retryLater ??= $release;

        switch ($error->kind) {
            case ErrorKind::Wait:
                $release();
                $wait = (int) $error->waitSeconds;
                if ($wait <= $settings->int('flood_wait_inline_max')) {
                    if (time() + $wait + 1 >= $this->deadline) {
                        $this->resumeIn = $wait + 1;

                        return self::MORE;
                    }
                    ($this->sleeper)($wait + 1);

                    return true;
                }
                $until = now()->addSeconds($wait + random_int(0, max(0, $settings->int('flood_wait_jitter'))));
                $account->forceFill(['flood_until' => $until])->saveQuietly();
                $this->events->warning(EventKind::Flood, "Telegram asked to wait {$wait}s, the account pauses", [
                    'until' => $until->toDateTimeString(),
                    'phone' => Phone::mask($account->phone),
                ], $account->id, $groupId, 'flood_wait');

                return self::DONE;

            case ErrorKind::PeerFlood:
                $release();
                $until = now()->addHours(max(1, $settings->int('peer_flood_rest_hours')));
                $account->forceFill(['flood_until' => $until, 'last_error' => 'peer_flood'])->saveQuietly();
                $this->events->error(EventKind::Flood, 'Account is spam-limited by Telegram (PEER_FLOOD), it rests', [
                    'until' => $until->toDateTimeString(),
                    'phone' => Phone::mask($account->phone),
                ], $account->id, $groupId, 'peer_flood');

                return self::DONE;

            case ErrorKind::Frozen:
                $release();
                $account->forceFill(['flood_until' => now()->addDays(7), 'last_error' => $error->key])->saveQuietly();
                $this->events->critical(EventKind::Session, 'Account is frozen by Telegram, sending stopped', [
                    'phone' => Phone::mask($account->phone),
                    'raw' => $error->raw,
                ], $account->id, $error->key);

                return self::DONE;

            case ErrorKind::DeadSession:
                $release();
                $this->accounts->retire($account->id, $error);

                return self::NOT_STARTABLE;

            case ErrorKind::Transient:
                // The row goes again later, after the others: one bad peer never holds the queue.
                if ($attempts >= (int) config('telegram.worker.max_attempts')) {
                    $fail($error->key);
                } else {
                    $retryLater();
                }
                if (++$this->transientStreak >= self::TRANSIENT_STREAK_LIMIT) {
                    $this->events->warning(EventKind::Worker, 'Telegram keeps failing for this account, the worker stops for now', [
                        'raw' => $error->raw,
                    ], $account->id, $groupId, $error->key);

                    return self::RETRY;
                }

                return true;

            default:
                $fail($error->storedKey());
                $this->transientStreak = 0;
                if ($error->kind === ErrorKind::Unknown) {
                    $this->events->warning(EventKind::Sending, 'Message failed with an unknown Telegram error', [
                        'raw' => $error->raw,
                    ], $account->id, $groupId, 'unknown_error');
                }

                return true;
        }
    }
}
