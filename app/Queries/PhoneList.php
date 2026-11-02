<?php

declare(strict_types=1);

namespace App\Queries;

use App\Enums\AccountState;
use App\Enums\AccountStatus;
use App\Models\User;
use App\Models\UserPhone;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The accounts page's list: the accounts a user oversees, narrowed by a search, an
 * owner and a group (App\Enums\AccountState), working accounts first; and how many
 * accounts each group has under the same search and owner (the page's tabs).
 *
 * @phpstan-type Filters array{search: string, state: ?AccountState, user: ?int}
 */
final class PhoneList
{
    public const PER_PAGE = 50;

    /**
     * Read leniently from the query string: a bad value is no filter, not an error
     * (the page's address may be old or hand-typed).
     *
     * @return Filters
     */
    public static function filters(Request $request): array
    {
        $user = $request->query('user');

        return [
            'search' => mb_substr(trim((string) $request->query('search')), 0, 100),
            'state' => AccountState::tryFrom((string) $request->query('state')),
            'user' => is_string($user) && ctype_digit($user) ? (int) $user : null,
        ];
    }

    /** @param Filters $filters */
    public function page(User $viewer, array $filters, int $page): LengthAwarePaginator
    {
        [$rank, $bindings] = $this->rank();

        return $this->base($viewer, $filters)
            ->when($filters['state'], fn (Builder $q, AccountState $state) => $q->inState($state))
            ->with('user:id,name')
            ->orderByRaw($rank, $bindings)
            ->orderByRaw('user_id = ? desc', [$viewer->id])
            ->orderBy('phone')
            ->paginate(self::PER_PAGE, ['*'], 'page', max(1, $page));
    }

    /**
     * @param  Filters  $filters
     * @return array<string, int> group => accounts, and 'all'
     */
    public function counts(User $viewer, array $filters): array
    {
        $counts = array_fill_keys(array_column(AccountState::cases(), 'value'), 0);

        $rows = $this->base($viewer, $filters)->toBase()
            ->selectRaw('status, count(*) as n, sum(case when flood_until > ? then 1 else 0 end) as flooded', [now()])
            ->groupBy('status')
            ->get();

        foreach ($rows as $row) {
            $status = AccountStatus::tryFrom((string) $row->status) ?? AccountStatus::Failed;
            $total = (int) $row->n;
            $flooded = $status === AccountStatus::Authorized ? (int) $row->flooded : 0;

            $counts[AccountState::for($status, false)->value] += $total - $flooded;
            $counts[AccountState::Paused->value] += $flooded;
        }

        return ['all' => array_sum($counts)] + $counts;
    }

    /**
     * By a name, an @username, the owner's name, or the digits of the number
     * ("90 123" finds +99890123...).
     *
     * @param  Filters  $filters
     * @return Builder<UserPhone>
     */
    private function base(User $viewer, array $filters): Builder
    {
        $search = $filters['search'];
        $like = '%'.addcslashes(ltrim($search, '@'), '%_\\').'%';
        $digits = (string) preg_replace('/\D+/', '', $search);

        return UserPhone::query()
            ->ownedBy($viewer)
            ->when($filters['user'], fn (Builder $q, int $id) => $q->where('user_id', $id))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->whereRaw("concat_ws(' ', first_name, last_name) like ?", [$like])
                ->orWhere('username', 'like', $like)
                ->orWhereHas('user', fn (Builder $u) => $u->where('name', 'like', $like))
                ->when(strlen($digits) >= 2, fn (Builder $w) => $w->orWhere('phone', 'like', "%{$digits}%"))));
    }

    /**
     * AccountState::rank() in SQL, so the database pages in the page's order.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function rank(): array
    {
        $values = fn (AccountState $state): array => array_map(fn (AccountStatus $s): string => $s->value, $state->statuses());
        $marks = fn (AccountState $state): string => implode(', ', array_fill(0, count($state->statuses()), '?'));

        $sql = 'case when status = ? then (case when flood_until > ? then ? else ? end)'
            .' when status in ('.$marks(AccountState::Login).') then ?'
            .' when status in ('.$marks(AccountState::Off).') then ?'
            .' else ? end';

        return [$sql, [
            AccountStatus::Authorized->value, now(), AccountState::Paused->rank(), AccountState::Active->rank(),
            ...$values(AccountState::Login), AccountState::Login->rank(),
            ...$values(AccountState::Off), AccountState::Off->rank(),
            AccountState::Problem->rank(),
        ]];
    }
}
