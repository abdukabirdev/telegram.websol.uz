<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Enums\Role as RoleName;
use App\Http\Controllers\Controller;
use App\Http\Requests\UserRequest;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPhone;
use App\Queries\DeliveryStats;
use App\Queries\PhoneOwners;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/** Superadmin: the panel's users and their roles (superadmin, admin, user: see App\Enums\Role). */
final class UserController extends Controller
{
    public function index(Request $request, PhoneOwners $owners): View
    {
        Gate::authorize('viewAny', User::class);

        // the first page comes with the HTML; search and paging fetch data()
        return view('users.index', [
            'initial' => $this->list($request),
            'owners' => $owners->for($request->user()),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', User::class);

        return response()->json($this->list($request));
    }

    public function create(): View
    {
        Gate::authorize('create', User::class);

        return view('users.form', ['user' => new User]);
    }

    public function store(UserRequest $request): RedirectResponse
    {
        Gate::authorize('create', User::class);
        $user = User::query()->create($this->attributes($request));

        // "Create and connect a phone": back to the list with the connect wizard open for the new user
        if ($request->input('then') === 'phone' && Gate::allows('create', [UserPhone::class, $user])) {
            return redirect()->to(route('users.index', ['owner' => $user->id]).'#connect')->with('success', __('users.saved'));
        }

        return redirect()->route('users.index')->with('success', __('users.saved'));
    }

    public function edit(User $user): View
    {
        Gate::authorize('update', $user);

        return view('users.form', ['user' => $user->load('role')]);
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        Gate::authorize('update', $user);
        $user->update($this->attributes($request));

        return redirect()->route('users.index')->with('success', __('users.saved'));
    }

    public function destroy(Request $request, User $user): RedirectResponse|JsonResponse
    {
        Gate::authorize('delete', $user);
        $user->delete();

        return $request->expectsJson()
            ? response()->json(['ok' => true, 'message' => __('users.deleted')])
            : redirect()->route('users.index')->with('success', __('users.deleted'));
    }

    /**
     * One page of users for the list: by name or email, or by the digits of a number
     * of an account this user oversees ("90 123" finds the owner of +99890123...).
     *
     * @return array{users: list<array<string, mixed>>, meta: array{current_page: int, last_page: int, total: int}}
     */
    private function list(Request $request): array
    {
        $me = $request->user();
        $search = trim((string) $request->query('search'));
        $digits = preg_replace('/\D+/', '', $search);

        $users = User::query()
            ->with('role')
            ->withCount(['phones' => fn (Builder $q) => $q->connected(), 'catalogs'])
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->when(strlen($digits) >= 3, fn (Builder $w) => $w->orWhereHas('phones', fn (Builder $p) => $p
                    ->where('phone', 'like', "%{$digits}%")
                    ->tap(fn (Builder $p) => $me->limitToOverseen($p))))))
            ->orderBy('name')
            ->paginate(25);

        $month = collect(DeliveryStats::between(now()->subDays(29)->startOfDay(), now())->byUser(500))->keyBy('user_id');

        return [
            'users' => $users->getCollection()->map(function (User $u) use ($me, $month): array {
                $stats = $month->get($u->id) ?? ['groups' => 0, 'sent' => 0, 'failed' => 0];
                $role = $u->roleName();

                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'initials' => $u->initials,
                    'is_me' => $u->id === $me->id,
                    'role' => ['label' => $role->label(), 'tone' => $role->tone()],
                    'phones' => $u->phones_count,
                    'catalogs' => $u->catalogs_count,
                    'groups' => $stats['groups'],
                    'sent' => $stats['sent'],
                    'failed' => $stats['failed'],
                    'can' => [
                        'update' => $me->can('update', $u),
                        'delete' => $me->can('delete', $u),
                        'connect' => $me->can('create', [UserPhone::class, $u]),
                    ],
                ];
            })->values()->all(),
            'meta' => ['current_page' => $users->currentPage(), 'last_page' => $users->lastPage(), 'total' => $users->total()],
        ];
    }

    /** @return array<string, mixed> */
    private function attributes(UserRequest $request): array
    {
        $role = Role::query()->firstOrCreate(['name' => RoleName::from((string) $request->input('role'))->value]);
        $data = $request->safe()->only(['name', 'email']) + ['role_id' => $role->id];
        if ($request->filled('password')) {
            $data['password'] = $request->input('password');
        }

        return $data;
    }
}
