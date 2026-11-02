<?php

declare(strict_types=1);

namespace App\Sending;

use App\Enums\MessageStatus;
use App\Enums\TaskStatus;
use App\Models\MessageTask;
use App\Models\TelegramMessage;
use App\Settings\Settings;

/** How many messages an account sent today, against the daily limit from the settings. */
final class DailyLimit
{
    public function __construct(private readonly Settings $settings) {}

    public function limit(): int
    {
        return max(0, $this->settings->int('daily_limit_per_phone'));
    }

    public function sentToday(int $phoneId): int
    {
        return $this->sentTodayMany([$phoneId])[$phoneId];
    }

    /**
     * sentToday() of several accounts in two queries (a list page).
     *
     * @param  list<int>  $phoneIds
     * @return array<int, int> account id => sent today
     */
    public function sentTodayMany(array $phoneIds): array
    {
        $counts = array_fill_keys($phoneIds, 0);
        if ($phoneIds === []) {
            return $counts;
        }
        $since = now()->startOfDay();

        $messages = TelegramMessage::query()->toBase()
            ->join('message_groups', 'message_groups.id', '=', 'telegram_messages.message_group_id')
            ->whereIn('message_groups.user_phone_id', $phoneIds)
            ->where('telegram_messages.status', MessageStatus::Sent->value)
            ->where('telegram_messages.sent_at', '>=', $since)
            ->groupBy('message_groups.user_phone_id')
            ->selectRaw('message_groups.user_phone_id as phone_id, count(*) as n')
            ->get();

        $tasks = MessageTask::query()->toBase()
            ->whereIn('sender_phone_id', $phoneIds)
            ->where('status', TaskStatus::Sent->value)
            ->where('sent_at', '>=', $since)
            ->groupBy('sender_phone_id')
            ->selectRaw('sender_phone_id as phone_id, count(*) as n')
            ->get();

        foreach ($messages->concat($tasks) as $row) {
            $counts[(int) $row->phone_id] += (int) $row->n;
        }

        return $counts;
    }

    public function reached(int $phoneId): bool
    {
        return $this->limit() > 0 && $this->sentToday($phoneId) >= $this->limit();
    }
}
