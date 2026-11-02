<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Accounts\AccountLogin;
use App\Accounts\AccountSessions;
use App\Http\Controllers\Controller;
use App\Http\Resources\PhoneResource;
use App\Models\User;
use App\Models\UserPhone;
use App\Queries\PhoneList;
use App\Queries\PhoneOwners;
use App\Sending\DailyLimit;
use App\Telegram\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Telegram accounts: the list, the connect wizard (phone -> code -> password),
 * logout, session check, removal. An admin connects a phone for a user they
 * oversee (an employee) without logging in as them. Every action answers with the account's
 * fresh state; the page polls data() while something is in flight.
 */
final class PhoneController extends Controller
{
    public function __construct(
        private readonly AccountLogin $login,
        private readonly AccountSessions $sessions,
    ) {}

    public function index(Request $request, PhoneOwners $owners, PhoneList $list, DailyLimit $limit): View
    {
        // the first page comes with the HTML; filters, paging and polling fetch data()
        return view('phones.index', [
            'initial' => $this->list($request, $list, $limit),
            'owners' => $owners->for($request->user()),
        ]);
    }

    public function data(Request $request, PhoneList $list, DailyLimit $limit): JsonResponse
    {
        return response()->json($this->list($request, $list, $limit));
    }

    public function show(UserPhone $phone): PhoneResource
    {
        Gate::authorize('view', $phone);

        return new PhoneResource($phone->load('user:id,name'));
    }

    public function store(Request $request): PhoneResource
    {
        $request->validate([
            'phone' => ['required', 'string', 'regex:'.Phone::PATTERN],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);
        $owner = $request->filled('user_id') ? User::query()->findOrFail($request->integer('user_id')) : $request->user();
        Gate::authorize('create', [UserPhone::class, $owner]);

        $account = $this->login->start($owner, (string) $request->input('phone'), by: $request->user());

        return new PhoneResource($account->load('user:id,name'));
    }

    public function code(Request $request, UserPhone $phone): PhoneResource
    {
        Gate::authorize('use', $phone);
        $request->validate(['code' => ['required', 'string', 'regex:/^[\d\s\-]{3,12}$/']]);

        return new PhoneResource($this->login->submitCode($phone, (string) $request->input('code')));
    }

    public function password(Request $request, UserPhone $phone): PhoneResource
    {
        Gate::authorize('use', $phone);
        $request->validate(['password' => ['required', 'string', 'max:256']]);

        return new PhoneResource($this->login->submitPassword($phone, (string) $request->input('password')));
    }

    public function relogin(Request $request, UserPhone $phone): PhoneResource
    {
        Gate::authorize('use', $phone);

        return new PhoneResource($this->login->start($phone->user, $phone->phone));
    }

    public function cancel(UserPhone $phone): JsonResponse
    {
        Gate::authorize('use', $phone);
        $this->login->cancel($phone);

        return response()->json(['ok' => true]);
    }

    public function check(UserPhone $phone): JsonResponse
    {
        Gate::authorize('use', $phone);
        $this->sessions->check($phone);

        return response()->json(['ok' => true, 'message' => __('phones.check_queued')]);
    }

    public function logout(UserPhone $phone): JsonResponse
    {
        Gate::authorize('use', $phone);
        $this->sessions->logout($phone);

        return response()->json(['ok' => true, 'message' => __('phones.logout_queued')]);
    }

    public function destroy(UserPhone $phone): JsonResponse
    {
        Gate::authorize('use', $phone);
        $this->sessions->remove($phone);

        return response()->json(['ok' => true]);
    }

    /**
     * One page of the accounts this user oversees (all of them for an admin, `user`
     * narrows to one owner), with today's sends of each and the size of every group
     * (App\Enums\AccountState) for the page's tabs.
     *
     * @return array<string, mixed>
     */
    private function list(Request $request, PhoneList $list, DailyLimit $limit): array
    {
        $user = $request->user();
        $filters = PhoneList::filters($request);
        $phones = $list->page($user, $filters, $request->integer('page', 1));
        $sent = $limit->sentTodayMany($phones->getCollection()->modelKeys());

        return [
            'phones' => $phones->getCollection()
                ->map(fn (UserPhone $p): array => (new PhoneResource($p))->resolve($request) + ['sent_today' => $sent[$p->id] ?? 0])
                ->all(),
            'meta' => ['current_page' => $phones->currentPage(), 'last_page' => $phones->lastPage(), 'total' => $phones->total()],
            'counts' => $list->counts($user, $filters),
            'daily_limit' => $limit->limit(),
            'filters' => [
                'search' => $filters['search'],
                'state' => $filters['state']?->value ?? '',
                'user' => $filters['user'] !== null ? (string) $filters['user'] : '',
            ],
        ];
    }
}
