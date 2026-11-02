<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\AccountState;
use App\Enums\AccountStatus;
use App\Models\UserPhone;
use App\Support\ErrorText;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin UserPhone */
final class PhoneResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isMine = $this->user_id === $request->user()?->id;
        $state = AccountState::of($this->resource);

        return [
            'id' => $this->id,
            'phone' => $this->phone,
            'name' => $this->display_name,
            'username' => $this->username,
            'telegram_user_id' => $this->telegram_user_id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'tone' => $this->isFlooded() && $this->status === AccountStatus::Authorized ? 'warning' : $this->status->tone(),
            // the accounts page's group; its label names a working account by the group
            // ("active", "paused"), any other by its exact status ("code sent", "revoked")
            'state' => $state->value,
            'state_label' => in_array($state, [AccountState::Active, AccountState::Paused], true) ? $state->label() : $this->status->label(),
            'state_tone' => $state->tone(),
            'in_flight' => $this->status->isInFlight(),
            'awaits' => match (true) {
                $this->status->acceptsCode() => 'code',
                $this->status->acceptsPassword() => 'password',
                default => null,
            },
            'password_hint' => $this->password_hint,
            'last_error' => $this->last_error,
            'last_error_text' => ErrorText::for($this->last_error),
            'flood_until' => $this->isFlooded() ? $this->flood_until?->toIso8601String() : null,
            'authorized_at' => $this->authorized_at?->toIso8601String(),
            'last_checked_at' => $this->last_checked_at?->toIso8601String(),
            'last_sent_at' => $this->last_sent_at?->toIso8601String(),
            'is_mine' => $isMine,
            'owner' => $this->whenLoaded('user', fn () => $this->user ? ['id' => $this->user->id, 'name' => $this->user->name] : null),
            'can' => [
                'send' => $this->canSend(),
                'check' => in_array($this->status, [AccountStatus::Authorized, AccountStatus::Revoked], true),
                'logout' => $this->status === AccountStatus::Authorized || $this->status === AccountStatus::Revoked,
                'relogin' => $this->status->canStartLogin(),
                'cancel' => ! $this->status->isInFlight() && $this->status !== AccountStatus::Authorized && $this->status->awaitsInput(),
                'remove' => ! $this->status->isInFlight() && $this->status !== AccountStatus::Authorized,
            ],
        ];
    }
}
