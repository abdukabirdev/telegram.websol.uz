<?php

namespace Tests\Feature;

use App\Models\Catalog;
use App\Models\MessageGroup;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPhone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * One superadmin (the configured email). Admins have the same rights except for
 * accounts: their own and plain users', never another admin's (nor may they edit
 * another admin). Users only their own. On 2026-10-26 an API token of a second
 * superadmin sent from someone else's account.
 */
class RolesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $otherAdmin;

    private User $plain;

    private UserPhone $adminPhone;

    private UserPhone $otherAdminPhone;

    private UserPhone $plainPhone;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->admin = User::factory()->admin()->create();
        $this->otherAdmin = User::factory()->admin()->create();
        $this->plain = $this->user();
        $this->adminPhone = $this->phoneOf($this->admin);
        $this->otherAdminPhone = $this->phoneOf($this->otherAdmin);
        $this->plainPhone = $this->phoneOf($this->plain);
    }

    private function send(User $as, UserPhone $from): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($as, 'sanctum')->postJson('/api/tg/send', ['phone_id' => $from->id, 'peer' => '@alice', 'message' => 'hi']);
    }

    public function test_an_admin_sends_from_plain_users_accounts_but_not_from_another_admins(): void
    {
        $this->send($this->admin, $this->otherAdminPhone)->assertStatus(422);
        $this->send($this->admin, $this->plainPhone)->assertOk();
        $this->send($this->admin, $this->adminPhone)->assertOk();
        $this->send($this->plain, $this->adminPhone)->assertStatus(422);

        $this->assertSame(0, MessageGroup::query()->where('user_phone_id', $this->otherAdminPhone->id)->count());
    }

    public function test_the_superadmin_sends_from_any_account(): void
    {
        $this->send($this->superadmin(), $this->otherAdminPhone)->assertOk();
    }

    public function test_the_superadmin_role_without_the_configured_email_is_a_plain_user(): void
    {
        $impostor = User::factory()->create(['role_id' => Role::query()->firstOrCreate(['name' => 'superadmin'])->id]);

        $this->assertFalse($impostor->isSuperadmin());
        $this->assertFalse($impostor->seesOthers());
        $this->send($impostor, $this->plainPhone)->assertStatus(422);
        $this->actingAs($impostor)->get(route('users.index'))->assertForbidden();
        // and an admin does not oversee it either
        $this->assertFalse($this->admin->oversees($impostor));
    }

    public function test_policies_follow_the_same_rule(): void
    {
        $as = Gate::forUser($this->admin);
        $this->assertTrue($as->allows('use', $this->plainPhone));
        $this->assertTrue($as->allows('use', $this->adminPhone));
        $this->assertFalse($as->allows('use', $this->otherAdminPhone));
        $this->assertFalse($as->allows('view', $this->phoneOf($this->superadmin())));

        $this->assertTrue($as->allows('update', Catalog::factory()->for($this->plain)->create()));
        $this->assertTrue($as->allows('update', Catalog::factory()->for($this->otherAdmin)->create()), 'catalogs are not accounts');
        $this->assertFalse(Gate::forUser($this->plain)->allows('view', $this->adminPhone));
    }

    public function test_account_lists_show_an_admin_only_plain_users_besides_their_own(): void
    {
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/phones')
            ->assertOk()
            ->assertJsonPath('data.others_phones.visible', true)
            ->assertJsonPath('data.others_phones.count', 1)
            ->assertJsonPath('data.others_phones.items.0.id', $this->plainPhone->id);

        $ids = collect($this->actingAs($this->admin)->getJson(route('phones.data', ['all' => 1]))->assertOk()->json('phones'))->pluck('id')->sort()->values()->all();
        $this->assertSame([$this->adminPhone->id, $this->plainPhone->id], $ids);

        $mine = $this->actingAs($this->plain)->getJson(route('phones.data', ['all' => 1]))->assertOk()->json('phones');
        $this->assertSame([$this->plainPhone->id], array_column($mine, 'id'));

        // catalogs are not accounts: an admin sees every one, like the superadmin
        Catalog::factory()->for($this->plain)->create();
        Catalog::factory()->for($this->otherAdmin)->create();
        $this->actingAs($this->admin)->getJson(route('catalogs.data', ['tab' => 'others']))->assertOk()->assertJsonPath('counts.others', 2);
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/catalogs')
            ->assertOk()->assertJsonPath('data.all_catalogs.total', 2)->assertJsonPath('data.can_see_all', true);
        $this->actingAs($this->plain)->getJson(route('catalogs.data', ['tab' => 'others']))->assertForbidden();
    }

    public function test_an_admin_opens_monitoring_and_users_like_the_superadmin(): void
    {
        $this->actingAs($this->admin);
        foreach (['monitoring.index', 'monitoring.api', 'monitoring.api.logs', 'monitoring.events', 'monitoring.logs', 'monitoring.settings', 'users.index'] as $route) {
            $this->get(route($route))->assertOk();
        }
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/users')->assertOk();
        $this->withToken($this->admin->createToken('dash')->plainTextToken)->getJson('/api/stats/top-users')->assertOk();
        $this->actingAs($this->plain)->get(route('monitoring.api.logs'))->assertForbidden();
    }

    public function test_an_admin_connects_a_phone_for_a_plain_user_but_not_for_another_admin(): void
    {
        $this->actingAs($this->admin)->get(route('phones.index'))->assertOk()
            ->assertViewHas('owners', fn ($owners) => $owners->pluck('id')->all() === [$this->admin->id, $this->plain->id]);

        // the users page opens the wizard in place, only for the users the admin may connect for
        $this->get(route('users.index'))->assertOk()->assertViewHas('owners', fn ($owners) => $owners->pluck('id')->all() === [$this->admin->id, $this->plain->id]);
        $connect = $this->getJson(route('users.data'))->assertOk()->collect('users')->pluck('can.connect', 'id');
        $this->assertTrue($connect[$this->plain->id]);
        $this->assertFalse($connect[$this->otherAdmin->id]);

        $this->postJson(route('phones.store'), ['phone' => '+998901110000', 'user_id' => $this->plain->id])
            ->assertCreated()
            ->assertJsonPath('data.owner.id', $this->plain->id)
            ->assertJsonPath('data.is_mine', false);
        $account = UserPhone::query()->wherePhone('+998901110000')->sole();
        $this->assertSame($this->plain->id, $account->user_id);
        $this->assertStringContainsString("_user_{$this->plain->id}.madeline", $account->session_path);

        $this->postJson(route('phones.store'), ['phone' => '+998901110001', 'user_id' => $this->otherAdmin->id])->assertForbidden();
        $this->actingAs($this->plain)->postJson(route('phones.store'), ['phone' => '+998901110002', 'user_id' => $this->admin->id])->assertForbidden();
        $this->actingAs($this->plain)->postJson(route('phones.store'), ['phone' => '+998901110003', 'user_id' => $this->plain->id])->assertCreated();
        $this->actingAs($this->superadmin())->postJson(route('phones.store'), ['phone' => '+998901110004', 'user_id' => $this->otherAdmin->id])->assertCreated();

        $this->assertFalse(UserPhone::query()->wherePhone('+998901110001')->exists());
        $this->assertFalse(UserPhone::query()->wherePhone('+998901110002')->exists());
        $this->assertSame($this->otherAdmin->id, UserPhone::query()->wherePhone('+998901110004')->value('user_id'));
    }

    public function test_an_admin_finds_users_by_the_numbers_they_oversee_only(): void
    {
        $digits = fn (UserPhone $p) => preg_replace('/\D+/', '', $p->phone);
        $this->actingAs($this->admin);

        $this->getJson(route('users.data', ['search' => $digits($this->plainPhone)]))->assertOk()->assertJsonPath('users.*.id', [$this->plain->id]);
        // another admin's number is not theirs to see, not even through the search
        $this->getJson(route('users.data', ['search' => $digits($this->otherAdminPhone)]))->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_create_and_connect_opens_the_wizard_for_the_new_user(): void
    {
        $this->actingAs($this->admin)
            ->post(route('users.store'), ['name' => 'Employee', 'email' => 'employee@example.com', 'password' => 'long-enough', 'role' => 'user', 'then' => 'phone'])
            ->assertRedirect(route('users.index', ['owner' => User::query()->where('email', 'employee@example.com')->value('id')]).'#connect');

        // a new admin is not theirs to connect for: back to the list
        $this->post(route('users.store'), ['name' => 'Boss', 'email' => 'boss@example.com', 'password' => 'long-enough', 'role' => 'admin', 'then' => 'phone'])
            ->assertRedirect(route('users.index'));
        $this->post(route('users.store'), ['name' => 'Plain', 'email' => 'plain@example.com', 'password' => 'long-enough', 'role' => 'user'])
            ->assertRedirect(route('users.index'));
    }

    public function test_an_admin_edits_plain_users_but_not_another_admin(): void
    {
        $this->actingAs($this->admin);

        // the other admin's password would open their accounts
        $this->get(route('users.edit', $this->otherAdmin))->assertForbidden();
        $this->put(route('users.update', $this->otherAdmin), ['name' => 'X', 'email' => $this->otherAdmin->email, 'password' => 'long-enough', 'role' => 'user'])->assertForbidden();
        $this->delete(route('users.destroy', $this->otherAdmin))->assertForbidden();
        $this->actingAs($this->admin, 'sanctum')->getJson("/api/users/{$this->otherAdmin->id}")->assertForbidden();
        $this->assertTrue($this->otherAdmin->refresh()->load('role')->isAdmin());

        $can = $this->actingAs($this->admin)->getJson(route('users.data'))->assertOk()->collect('users')->keyBy('id');
        $this->assertFalse($can[$this->otherAdmin->id]['can']['update']);
        $this->assertTrue($can[$this->plain->id]['can']['update']);
        $this->get(route('users.edit', $this->plain))->assertOk();
        $this->delete(route('users.destroy', $this->plain))->assertRedirect(route('users.index'));
    }

    public function test_only_the_configured_email_can_be_made_superadmin(): void
    {
        $super = $this->superadmin();
        $superRole = Role::query()->where('name', 'superadmin')->value('id');

        $this->actingAs($super)->put(route('users.update', $this->plain), ['name' => 'X', 'email' => $this->plain->email, 'role' => 'superadmin'])
            ->assertSessionHasErrors('role');
        $this->actingAs($super, 'sanctum')->postJson('/api/users', ['name' => 'X', 'email' => 'x@example.com', 'password' => 'password1', 'role_id' => $superRole])
            ->assertUnprocessable()->assertJsonValidationErrors('role_id');

        $this->actingAs($super)->put(route('users.update', $this->plain), ['name' => 'X', 'email' => $this->plain->email, 'role' => 'admin'])
            ->assertRedirect(route('users.index'));
        $this->assertTrue($this->plain->refresh()->load('role')->isAdmin());
    }
}
