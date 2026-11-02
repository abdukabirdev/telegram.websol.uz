<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccountState;
use App\Enums\AccountStatus;
use App\Models\Casts\LenientEnum;
use App\Telegram\Phone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Telegram account connected by a user: its login state, its profile, its health.
 *
 * @property AccountStatus $status
 */
class UserPhone extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'phone', 'status', 'telegram_user_id', 'first_name', 'last_name', 'username',
        'session_path', 'password_hint', 'last_error', 'login_started_at', 'authorized_at',
        'last_checked_at', 'flood_until', 'last_sent_at',
    ];

    protected $hidden = ['session_path'];

    protected function casts(): array
    {
        return [
            'status' => LenientEnum::class.':'.AccountStatus::class.',failed',
            'login_started_at' => 'datetime',
            'authorized_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'flood_until' => 'datetime',
            'last_sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messageGroups(): HasMany
    {
        return $this->hasMany(MessageGroup::class, 'user_phone_id');
    }

    public function chatLogGroups(): HasMany
    {
        return $this->hasMany(ChatLogGroup::class, 'user_phone_id');
    }

    public function scopeOwnedBy(Builder $query, User $user): void
    {
        $user->limitToOverseen($query);
    }

    /** Phones that finished a login at least once (what the old code stored). */
    public function scopeConnected(Builder $query): void
    {
        $query->whereNotNull('authorized_at');
    }

    /** The accounts of one group of the accounts page (see App\Enums\AccountState). */
    public function scopeInState(Builder $query, AccountState $state): void
    {
        $values = fn (AccountState ...$states): array => array_map(
            fn (AccountStatus $status): string => $status->value,
            array_merge(...array_map(fn (AccountState $s): array => $s->statuses(), $states)),
        );

        match ($state) {
            AccountState::Active => $query->where('status', AccountStatus::Authorized)
                ->where(fn (Builder $q) => $q->whereNull('flood_until')->orWhere('flood_until', '<=', now())),
            AccountState::Paused => $query->where('status', AccountStatus::Authorized)->where('flood_until', '>', now()),
            AccountState::Login, AccountState::Off => $query->whereIn('status', $values($state)),
            // whatever no other group takes, unknown statuses included (they read as "failed")
            AccountState::Problem => $query->where(fn (Builder $q) => $q
                ->whereNotIn('status', $values(AccountState::Active, AccountState::Login, AccountState::Off))
                ->orWhereNull('status')),
        };
    }

    /** Find by a phone typed any way: "+998 90 ...", "99890...". */
    public function scopeWherePhone(Builder $query, string $phone): void
    {
        $normalized = Phone::normalize($phone);
        $query->whereIn('phone', array_unique([$phone, $normalized, ltrim($normalized, '+')]));
    }

    public function isFlooded(): bool
    {
        return $this->flood_until !== null && $this->flood_until->isFuture();
    }

    public function canSend(): bool
    {
        return $this->status->canSend() && $this->session_path !== null;
    }

    public function isStaleLogin(): bool
    {
        return $this->status->isInFlight()
            && ($this->login_started_at === null
                || $this->login_started_at->lt(now()->subSeconds((int) config('telegram.login_stale_after'))));
    }

    protected function displayName(): Attribute
    {
        return Attribute::get(function (): string {
            $name = trim("{$this->first_name} {$this->last_name}");

            return $name !== '' ? $name : ($this->username ? '@'.$this->username : $this->phone);
        });
    }
}
