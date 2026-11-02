<?php

namespace Tests\Feature\Pages;

use App\Enums\AccountStatus;
use App\Enums\MessageStatus;
use App\Models\Catalog;
use App\Models\MessageGroup;
use App\Models\User;
use App\Models\UserPhone;
use App\Sending\DailyLimit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The accounts page: groups (App\Enums\AccountState) as tabs with counts, working
 * accounts first, search, an owner filter that never reaches past what a user oversees.
 */
class PhonesPageTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    /** @var array<string, UserPhone> group => account */
    private array $phones;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->user();

        // created in the reverse of the page's order, so the order is the query's doing
        $this->phones = [
            'off' => $this->phoneOf($this->owner, ['phone' => '+998900000001', 'status' => AccountStatus::LoggedOut]),
            'problem' => $this->phoneOf($this->owner, ['phone' => '+998900000002', 'status' => AccountStatus::Revoked, 'last_error' => 'auth_key_unregistered']),
            'login' => $this->phoneOf($this->owner, ['phone' => '+998900000003', 'status' => AccountStatus::CodeSent]),
            'paused' => $this->phoneOf($this->owner, ['phone' => '+998900000004', 'flood_until' => now()->addMinutes(10)]),
            'active' => $this->phoneOf($this->owner, ['phone' => '+998911234567', 'first_name' => 'Zarina', 'username' => 'zarina_sales', 'flood_until' => now()->subMinute()]),
        ];
    }

    private function ids(array $query = [], ?User $as = null): array
    {
        return collect($this->actingAs($as ?? $this->owner)->getJson(route('phones.data', $query))->assertOk()->json('phones'))->pluck('id')->all();
    }

    public function test_working_accounts_come_first_and_every_group_is_counted(): void
    {
        $response = $this->actingAs($this->owner)->getJson(route('phones.data'))->assertOk()
            ->assertJsonStructure([
                'phones' => [['id', 'phone', 'state', 'state_label', 'state_tone', 'status', 'sent_today', 'can']],
                'meta' => ['current_page', 'last_page', 'total'],
                'counts' => ['all', 'active', 'paused', 'login', 'problem', 'off'],
                'daily_limit',
                'filters',
            ]);

        $this->assertSame(['active', 'paused', 'login', 'problem', 'off'], array_column($response->json('phones'), 'state'));
        $this->assertSame(['all' => 5, 'active' => 1, 'paused' => 1, 'login' => 1, 'problem' => 1, 'off' => 1], $response->json('counts'));
        $this->assertSame('success', $response->json('phones.0.state_tone'));
    }

    public function test_tabs_search_and_counts_follow_the_filters(): void
    {
        foreach ($this->phones as $state => $phone) {
            $this->assertSame([$phone->id], $this->ids(['state' => $state]), $state);
        }

        $active = $this->phones['active']->id;
        $this->assertSame([$active], $this->ids(['search' => '91 123']), 'digits of the number');
        $this->assertSame([$active], $this->ids(['search' => 'zarina']), 'name');
        $this->assertSame([$active], $this->ids(['search' => '@zarina_s']), '@username');
        $this->assertSame([], $this->ids(['search' => '%']), 'a LIKE wildcard is a plain character');

        // the tabs count under the search, not under the tab
        $this->actingAs($this->owner)->getJson(route('phones.data', ['search' => '90 000', 'state' => 'off']))
            ->assertOk()
            ->assertJsonPath('counts.all', 4)
            ->assertJsonPath('counts.active', 0)
            ->assertJsonPath('meta.total', 1);

        // a bad value is no filter
        $this->assertCount(5, $this->ids(['state' => 'nonsense', 'user' => 'x']));
    }

    public function test_an_admin_filters_by_owner_but_never_sees_another_admins_accounts(): void
    {
        $admin = User::factory()->admin()->create();
        $otherAdmin = User::factory()->admin()->create();
        $adminPhone = $this->phoneOf($admin);
        $this->phoneOf($otherAdmin);

        $this->assertCount(6, $this->ids([], $admin), 'own and plain users\' accounts');
        $this->assertSame([$adminPhone->id], $this->ids(['user' => (string) $admin->id], $admin));
        $this->assertSame([], $this->ids(['user' => (string) $otherAdmin->id], $admin));
        $this->assertSame([], $this->ids(['user' => (string) $admin->id]), 'a plain user sees only their own');

        $this->actingAs($admin)->get(route('phones.index'))->assertOk()
            ->assertSee('phonesPage', false)
            ->assertViewHas('initial', fn (array $initial) => $initial['counts']['all'] === 6);
    }

    public function test_today_counts_the_sent_messages_of_each_account(): void
    {
        $catalog = Catalog::factory()->for($this->owner)->peers(['@alice', '@bob'])->create();
        $this->actingAs($this->owner)->postJson(route('send.store'), [
            'phone_id' => $this->phones['active']->id,
            'catalog_ids' => [$catalog->id],
            'message' => 'hi',
        ])->assertCreated();
        $messages = MessageGroup::query()->latest('id')->firstOrFail()->messages()->orderBy('id')->get();
        $messages[0]->forceFill(['status' => MessageStatus::Sent, 'sent_at' => now()])->save();
        $messages[1]->forceFill(['status' => MessageStatus::Sent, 'sent_at' => now()->subDays(2)])->save();

        $phones = collect($this->getJson(route('phones.data'))->assertOk()->json('phones'))->pluck('sent_today', 'id');
        $this->assertSame(1, $phones[$this->phones['active']->id]);
        $this->assertSame(0, $phones[$this->phones['paused']->id]);
        $this->assertSame(1, app(DailyLimit::class)->sentToday($this->phones['active']->id));
    }
}
