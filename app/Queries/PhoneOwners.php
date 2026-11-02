<?php

declare(strict_types=1);

namespace App\Queries;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Whom a user may connect a Telegram account for: the connect wizard's owner picker
 * (accounts page, users page). An admin: themselves first, then the users they oversee;
 * a plain user: nobody to pick (the wizard connects their own).
 */
final class PhoneOwners
{
    /** @return Collection<int, array{id: int, name: string, email: string, initials: string, phones: int}> */
    public function for(User $user): Collection
    {
        if (! $user->seesOthers()) {
            return collect();
        }

        return User::query()->tap(fn (Builder $q) => $user->limitToOverseen($q, 'id'))
            ->withCount(['phones' => fn (Builder $q) => $q->connected()])
            ->orderByRaw('id = ? desc', [$user->id])->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'initials' => $u->initials, 'phones' => $u->phones_count]);
    }
}
