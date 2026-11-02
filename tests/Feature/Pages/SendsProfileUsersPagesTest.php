<?php

namespace Tests\Feature\Pages;

use App\Enums\GroupStatus;
use App\Enums\MessageStatus;
use App\Enums\Role;
use App\Models\Catalog;
use App\Models\MessageGroup;
use App\Models\MessageTask;
use App\Models\SendLoop;
use App\Models\User;
use App\Models\UserPhone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Sends history and detail, profile, and the superadmin's users pages. */
class SendsProfileUsersPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private UserPhone $phone;

    private Catalog $catalog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->user();
        $this->phone = $this->phoneOf($this->owner);
        $this->catalog = Catalog::factory()->for($this->owner)->peers(['@alice', '@bob', '@carol'])->create(['title' => 'Clients']);
    }

    private function send(string $text = 'Hello from the test'): MessageGroup
    {
        $this->actingAs($this->owner)->postJson(route('send.store'), [
            'phone_id' => $this->phone->id,
            'catalog_ids' => [$this->catalog->id],
            'message' => $text,
        ])->assertCreated();

        return MessageGroup::query()->latest('id')->firstOrFail();
    }

    // ---------------------------------------------------------------- sends

    public function test_sends_index_renders_with_the_account_filter(): void
    {
        $this->actingAs($this->owner)->get(route('sends.index'))
            ->assertOk()
            ->assertSee('sendsPage', false)
            ->assertSee($this->phone->phone);
    }

    public function test_sends_data_answers_every_filter(): void
    {
        $group = $this->send('Unique promo text');
        $ids = fn ($response) => collect($response->json('groups'))->pluck('id')->all();

        $this->actingAs($this->owner);

        $all = $this->getJson(route('sends.data'))->assertOk()->assertJsonStructure([
            'groups' => [['id', 'status', 'status_label', 'tone', 'active', 'text', 'phone', 'creator', 'catalogs', 'counts', 'progress', 'created_at', 'url', 'can']],
            'meta' => ['current_page', 'last_page', 'total'],
        ]);
        $this->assertSame([$group->id], $ids($all));
        $this->assertSame(3, $all->json('groups.0.counts.total'));

        $this->assertSame([$group->id], $ids($this->getJson(route('sends.data', ['search' => 'promo']))->assertOk()));
        $this->assertSame([], $ids($this->getJson(route('sends.data', ['search' => 'nothing like this']))->assertOk()));
        $this->assertSame([$group->id], $ids($this->getJson(route('sends.data', ['search' => 'Clients']))->assertOk()));

        $this->assertSame([$group->id], $ids($this->getJson(route('sends.data', ['status' => 'active']))->assertOk()));
        $this->assertSame([$group->id], $ids($this->getJson(route('sends.data', ['status' => 'pending']))->assertOk()));
        foreach (['sending', 'completed', 'failed', 'canceled'] as $status) {
            $this->assertSame([], $ids($this->getJson(route('sends.data', ['status' => $status]))->assertOk()), $status);
        }
        $this->getJson(route('sends.data', ['status' => 'bogus']))->assertUnprocessable();

        $this->assertSame([$group->id], $ids($this->getJson(route('sends.data', ['phone_id' => $this->phone->id]))->assertOk()));
        $this->assertSame([], $ids($this->getJson(route('sends.data', ['phone_id' => $this->phone->id + 999]))->assertOk()));

        $today = now()->toDateString();
        $this->assertSame([$group->id], $ids($this->getJson(route('sends.data', ['from' => $today, 'to' => $today]))->assertOk()));
        $this->assertSame([], $ids($this->getJson(route('sends.data', ['from' => now()->addDay()->toDateString()]))->assertOk()));
        $this->assertSame([], $ids($this->getJson(route('sends.data', ['to' => now()->subDay()->toDateString()]))->assertOk()));

        // another user sees nothing of it
        $this->actingAs($this->user());
        $this->assertSame([], $ids($this->getJson(route('sends.data'))->assertOk()));
    }

    public function test_send_detail_and_progress_for_the_owner(): void
    {
        $group = $this->send();

        $this->actingAs($this->owner)->get(route('sends.show', $group))
            ->assertOk()
            ->assertSee('sendShowPage', false)
            ->assertSee('sends\\\\\\/'.$group->id.'\\\\\\/progress', false); // the url inside @js(): sends\\\/1\\\/progress

        $this->getJson(route('sends.progress', $group))
            ->assertOk()
            ->assertJsonStructure([
                'group' => ['id', 'status', 'counts' => ['total', 'sent', 'failed', 'pending', 'canceled'], 'can' => ['cancel', 'retry']],
                'messages' => [['id', 'peer', 'link', 'status', 'status_label', 'tone', 'error_key', 'error_text', 'attempts', 'sent_at', 'updated_at']],
                'meta' => ['current_page', 'last_page', 'total'],
                'editable_catalogs',
            ])
            ->assertJsonPath('group.counts.total', 3)
            ->assertJsonPath('group.can.cancel', true)
            ->assertJsonPath('editable_catalogs.0.id', $this->catalog->id)
            ->assertJsonPath('messages.0.link', fn ($link) => str_starts_with((string) $link, 'https://t.me/'));

        $group->messages()->where('peer', '@bob')->update(['status' => MessageStatus::Failed, 'error_key' => 'peer_not_found']);

        $this->getJson(route('sends.progress', [$group, 'status' => 'failed']))
            ->assertOk()->assertJsonCount(1, 'messages')->assertJsonPath('messages.0.peer', '@bob')
            ->assertJsonPath('messages.0.error_text', fn ($text) => is_string($text) && $text !== '');
        $this->getJson(route('sends.progress', [$group, 'status' => 'pending']))->assertOk()->assertJsonCount(2, 'messages');
        $this->getJson(route('sends.progress', [$group, 'search' => 'ali']))->assertOk()->assertJsonCount(1, 'messages');
        $this->getJson(route('sends.progress', [$group, 'status' => 'sent']))->assertOk()->assertJsonCount(0, 'messages');
    }

    public function test_another_user_cannot_see_or_touch_a_send(): void
    {
        $group = $this->send();
        $stranger = $this->user();

        $this->actingAs($stranger)->get(route('sends.show', $group))->assertForbidden();
        $this->getJson(route('sends.progress', $group))->assertForbidden();
        $this->postJson(route('sends.cancel', $group))->assertForbidden();
        $this->postJson(route('sends.retry', $group))->assertForbidden();

        $this->assertSame(GroupStatus::Pending, $group->refresh()->status);
    }

    public function test_a_superadmin_sees_any_send(): void
    {
        $group = $this->send();

        $this->actingAs($this->superadmin())->get(route('sends.show', $group))->assertOk();
        $this->getJson(route('sends.progress', $group))->assertOk()->assertJsonPath('group.id', $group->id);
    }

    public function test_cancel_stops_an_active_send_once(): void
    {
        $group = $this->send();

        $this->actingAs($this->owner)->postJson(route('sends.cancel', $group))
            ->assertOk()->assertJsonPath('ok', true)->assertJsonStructure(['message']);

        $this->assertSame(GroupStatus::Canceled, $group->refresh()->status);
        $this->assertSame(3, $group->messages()->where('status', MessageStatus::Canceled)->count());

        $this->postJson(route('sends.cancel', $group))->assertStatus(409);
        $this->getJson(route('sends.progress', $group))->assertOk()->assertJsonPath('group.can.cancel', false);
    }

    public function test_stop_all_stops_only_what_the_user_may_see(): void
    {
        $group = $this->send();
        $this->actingAs($this->owner)->postJson(route('send.store'), [
            'phone_id' => $this->phone->id, 'catalog_ids' => [$this->catalog->id], 'message' => 'Weekly', 'repeat_every_minutes' => 60,
        ])->assertCreated();
        $round = MessageGroup::query()->latest('id')->firstOrFail();
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/telegram/single-messages', [
            'sender_phone_id' => $this->phone->id, 'target_tg_id' => '12345', 'message' => 'hi',
        ])->assertStatus(202);

        $stranger = $this->user();
        $strangerPhone = $this->phoneOf($stranger);
        $this->actingAs($stranger)->postJson(route('send.store'), [
            'phone_id' => $strangerPhone->id,
            'catalog_ids' => [Catalog::factory()->for($stranger)->peers(['@x'])->create()->id],
            'message' => 'not yours',
        ])->assertCreated();
        $foreign = MessageGroup::query()->latest('id')->firstOrFail();

        $this->actingAs($this->owner)->getJson(route('sends.data'))->assertOk()
            ->assertJsonPath('active.groups', 2)
            ->assertJsonPath('active.messages', 6)
            ->assertJsonPath('active.loops', 1);

        $this->postJson(route('sends.cancel-all'))
            ->assertOk()
            ->assertJsonPath('groups', 2)
            ->assertJsonPath('messages', 6)
            ->assertJsonPath('loops', 1)
            ->assertJsonPath('tasks', 1)
            ->assertJsonPath('message', __('sends.stopped_all', ['groups' => 2, 'messages' => 7]));

        $this->assertSame(GroupStatus::Canceled, $group->refresh()->status);
        $this->assertSame(GroupStatus::Canceled, $round->refresh()->status);
        $this->assertFalse(SendLoop::query()->findOrFail($round->send_loop_id)->isActive());
        $this->assertSame('CANCELED', MessageTask::query()->firstOrFail()->error_code);
        $this->assertTrue($foreign->refresh()->status->isActive(), 'another user\'s send keeps going');

        $this->postJson(route('sends.cancel-all'))->assertOk()->assertJsonPath('message', __('sends.nothing_to_stop'));
        $this->getJson(route('sends.data'))->assertJsonPath('active.groups', 0);
    }

    public function test_a_superadmin_stops_everything_or_one_account(): void
    {
        $mine = $this->send();
        $stranger = $this->user();
        $other = $this->phoneOf($stranger);
        $this->actingAs($stranger)->postJson(route('send.store'), [
            'phone_id' => $other->id,
            'catalog_ids' => [Catalog::factory()->for($stranger)->peers(['@x'])->create()->id],
            'message' => 'theirs',
        ])->assertCreated();
        $group = MessageGroup::query()->latest('id')->firstOrFail();

        $this->actingAs($this->superadmin())->postJson(route('sends.cancel-all'), ['phone_id' => $other->id])
            ->assertOk()->assertJsonPath('groups', 1);
        $this->assertTrue($mine->refresh()->status->isActive(), 'only the chosen account');
        $this->assertSame(GroupStatus::Canceled, $group->refresh()->status);

        $this->postJson(route('sends.cancel-all'))->assertOk()->assertJsonPath('groups', 1);
        $this->assertSame(GroupStatus::Canceled, $mine->refresh()->status);
    }

    public function test_retry_creates_a_new_send_for_the_failed_peers(): void
    {
        $group = $this->send();
        $group->messages()->where('peer', '@alice')->update(['status' => MessageStatus::Sent, 'sent_at' => now()]);
        $group->messages()->where('peer', '!=', '@alice')->update(['status' => MessageStatus::Failed, 'error_key' => 'peer_not_found']);
        $group->update(['status' => GroupStatus::Completed, 'finished_at' => now()]);

        $this->actingAs($this->owner)->getJson(route('sends.progress', $group))->assertJsonPath('group.can.retry', true);

        $response = $this->postJson(route('sends.retry', $group))->assertOk()->assertJsonPath('ok', true);
        $retry = MessageGroup::query()->latest('id')->firstOrFail();

        $this->assertNotSame($group->id, $retry->id);
        $response->assertJsonPath('redirect', route('sends.show', $retry));
        $this->assertEqualsCanonicalizing(['@bob', '@carol'], $retry->messages()->pluck('peer')->all());
    }

    public function test_list_and_progress_show_the_file_and_retry_stops_once_it_is_removed(): void
    {
        Storage::fake('local');
        $this->actingAs($this->owner)->post(route('send.store'), [
            'phone_id' => $this->phone->id,
            'catalog_ids' => [$this->catalog->id],
            'file' => UploadedFile::fake()->create('price.pdf', 120, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();
        $group = MessageGroup::query()->latest('id')->firstOrFail();

        $this->getJson(route('sends.data'))->assertOk()
            ->assertJsonPath('groups.0.file.name', 'price.pdf')
            ->assertJsonPath('groups.0.loop', null);

        $this->getJson(route('sends.progress', $group))->assertOk()
            ->assertJsonPath('group.text', '')
            ->assertJsonPath('group.file.name', 'price.pdf')
            ->assertJsonPath('group.file.kind', 'document')
            ->assertJsonPath('group.file.removed', false)
            ->assertJsonPath('group.loop', null)
            ->assertJsonPath('group.can.stop_loop', false);

        $group->messages()->update(['status' => MessageStatus::Failed, 'error_key' => 'peer_not_found']);
        $group->update(['status' => GroupStatus::Failed, 'finished_at' => now()]);
        $this->getJson(route('sends.progress', $group))->assertJsonPath('group.can.retry', true);

        $group->attachment->update(['removed_at' => now()]);
        $this->getJson(route('sends.progress', $group))
            ->assertJsonPath('group.file.removed', true)
            ->assertJsonPath('group.can.retry', false);
    }

    public function test_a_repeating_send_is_shown_and_stopped_by_its_owner_only(): void
    {
        $this->actingAs($this->owner)->postJson(route('send.store'), [
            'phone_id' => $this->phone->id,
            'catalog_ids' => [$this->catalog->id],
            'message' => 'Weekly news',
            'repeat_every_minutes' => 60 * 24 * 7,
        ])->assertCreated();
        $group = MessageGroup::query()->latest('id')->firstOrFail();
        $loop = SendLoop::query()->findOrFail($group->send_loop_id);

        $this->getJson(route('sends.data'))->assertOk()->assertJsonPath('groups.0.loop.every_minutes', 10080);
        $this->getJson(route('sends.progress', $group))->assertOk()
            ->assertJsonPath('group.loop.id', $loop->id)
            ->assertJsonPath('group.loop.active', true)
            ->assertJsonPath('group.loop.rounds_done', 1)
            ->assertJsonPath('group.loop.max_rounds', null)
            ->assertJsonPath('group.loop.stop_url', route('sends.loops.stop', $loop))
            ->assertJsonPath('group.can.stop_loop', true);

        // a stranger can't stop it
        $this->actingAs($this->user())->postJson(route('sends.loops.stop', $loop))->assertForbidden();
        $this->assertTrue($loop->refresh()->isActive());

        // the owner can, once; the running round is canceled with it
        $this->actingAs($this->owner)->postJson(route('sends.loops.stop', $loop))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message', __('sends.loop_stopped'));
        $this->assertFalse($loop->refresh()->isActive());
        $this->assertSame('canceled', $loop->stop_reason);
        $this->assertSame(GroupStatus::Canceled, $group->refresh()->status);

        $this->postJson(route('sends.loops.stop', $loop))->assertStatus(409);
        $this->getJson(route('sends.progress', $group))
            ->assertJsonPath('group.loop.active', false)
            ->assertJsonPath('group.loop.stop_reason', 'canceled')
            ->assertJsonPath('group.can.stop_loop', false);
    }

    public function test_a_failed_peer_can_be_removed_from_the_catalog(): void
    {
        $group = $this->send();

        $this->actingAs($this->owner)->postJson(route('catalogs.remove-peer', $this->catalog), ['peer' => '@bob'])->assertOk();

        $this->assertNotContains('@bob', $this->catalog->refresh()->peerList());
        $this->actingAs($this->user())->postJson(route('catalogs.remove-peer', $this->catalog), ['peer' => '@alice'])->assertForbidden();
        $this->assertSame(3, $group->messages()->count());
    }

    // -------------------------------------------------------------- profile

    public function test_profile_page_renders(): void
    {
        $this->owner->createToken('CRM');

        $this->actingAs($this->owner)->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('profilePage', false)
            ->assertSee($this->owner->email)
            ->assertSee('CRM')
            ->assertSee('Authorization: Bearer', false);
    }

    public function test_profile_update_name_email_and_password(): void
    {
        $this->actingAs($this->owner)->from(route('profile.edit'))
            ->put(route('profile.update'), ['name' => 'New Name', 'email' => 'new@example.com'])
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('success');

        $this->owner->refresh();
        $this->assertSame('New Name', $this->owner->name);
        $this->assertSame('new@example.com', $this->owner->email);

        // wrong current password
        $this->from(route('profile.edit'))->put(route('profile.update'), [
            'name' => 'New Name', 'email' => 'new@example.com',
            'current_password' => 'wrong-password', 'password' => 'secret-123', 'password_confirmation' => 'secret-123',
        ])->assertRedirect(route('profile.edit'))->assertSessionHasErrors('current_password');

        $this->from(route('profile.edit'))->put(route('profile.update'), [
            'name' => 'New Name', 'email' => 'new@example.com',
            'current_password' => 'password', 'password' => 'secret-123', 'password_confirmation' => 'secret-123',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('secret-123', $this->owner->refresh()->password));
    }

    public function test_profile_validation_messages_are_translated(): void
    {
        app()->setLocale('ru');
        $this->actingAs($this->owner)->from(route('profile.edit'))
            ->put(route('profile.update'), ['name' => '', 'email' => 'not-an-email'])
            ->assertSessionHasErrors(['name' => 'Заполните поле «имя».']);
    }

    public function test_api_token_is_shown_once_and_can_be_revoked(): void
    {
        $response = $this->actingAs($this->owner)->from(route('profile.edit'))
            ->post(route('profile.tokens.store'), ['name' => 'Website', '_form' => 'token'])
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('new_token');

        $plain = session('new_token');
        $this->get(route('profile.edit'))->assertOk()->assertSee($plain);
        $this->get(route('profile.edit'))->assertOk()->assertDontSee($plain);

        $token = $this->owner->tokens()->firstOrFail();
        $this->assertSame('Website', $token->name);

        $this->from(route('profile.edit'))->delete(route('profile.tokens.destroy', $token->id))
            ->assertRedirect(route('profile.edit'))->assertSessionHas('success');
        $this->assertSame(0, $this->owner->tokens()->count());

        // someone else's token is untouched
        $other = $this->user();
        $foreign = $other->createToken('Theirs')->accessToken;
        $this->delete(route('profile.tokens.destroy', $foreign->id));
        $this->assertSame(1, $other->tokens()->count());
    }

    // ---------------------------------------------------------------- users

    public function test_superadmin_manages_users(): void
    {
        $admin = $this->superadmin();
        $this->actingAs($admin);

        $this->get(route('users.index'))->assertOk()->assertSee($this->owner->email)->assertSee($admin->email);
        $this->getJson(route('users.data', ['search' => $this->owner->name]))->assertOk()
            ->assertJsonPath('users.*.id', [$this->owner->id])->assertJsonPath('meta.total', 1);
        $this->get(route('users.create'))->assertOk()->assertSee('name="password"', false);
        $this->get(route('users.edit', $this->owner))->assertOk()->assertSee($this->owner->email);

        $this->post(route('users.store'), ['name' => 'Operator', 'email' => 'operator@example.com', 'password' => 'short', 'role' => 'user'])
            ->assertSessionHasErrors('password');
        $this->post(route('users.store'), ['name' => 'Operator', 'email' => 'operator@example.com', 'password' => 'long-enough', 'role' => 'user'])
            ->assertRedirect(route('users.index'))->assertSessionHas('success');
        $created = User::query()->where('email', 'operator@example.com')->firstOrFail();
        $this->assertSame(Role::User, $created->roleName());

        $this->put(route('users.update', $created), ['name' => 'Operator 2', 'email' => 'operator@example.com', 'password' => '', 'role' => 'admin'])
            ->assertRedirect(route('users.index'));
        $created->refresh()->load('role');
        $this->assertSame('Operator 2', $created->name);
        $this->assertSame(Role::Admin, $created->roleName());
        $this->assertTrue(Hash::check('long-enough', $created->password));

        $this->delete(route('users.destroy', $this->owner))->assertRedirect(route('users.index'))->assertSessionHas('success');
        $this->assertNull(User::query()->find($this->owner->id));

        // the list deletes without leaving the page
        $this->deleteJson(route('users.destroy', $created))->assertOk()->assertJsonPath('message', __('users.deleted'));
        $this->assertNull(User::query()->find($created->id));
    }

    public function test_the_users_search_finds_the_owner_of_a_number(): void
    {
        $this->actingAs($this->superadmin());
        $this->phoneOf($this->owner, ['phone' => '+998901234567']);

        // typed any way: "90 123 45" is the digits of the number
        $this->getJson(route('users.data', ['search' => '90 123 45']))->assertOk()->assertJsonPath('users.*.id', [$this->owner->id]);
    }

    public function test_the_superadmin_cannot_delete_themselves(): void
    {
        $admin = $this->superadmin();

        $this->actingAs($admin)->delete(route('users.destroy', $admin))->assertForbidden();
        $this->assertNotNull(User::query()->find($admin->id));

        // no delete button for them on the list
        $can = $this->getJson(route('users.data'))->assertOk()->collect('users')->pluck('can.delete', 'id');
        $this->assertFalse($can[$admin->id]);
        $this->assertTrue($can[$this->owner->id]);
    }

    public function test_a_normal_user_cannot_open_users(): void
    {
        $this->actingAs($this->owner);
        $victim = $this->user();

        $this->get(route('users.index'))->assertForbidden();
        $this->getJson(route('users.data'))->assertForbidden();
        $this->get(route('users.create'))->assertForbidden();
        $this->get(route('users.edit', $victim))->assertForbidden();
        $this->post(route('users.store'), ['name' => 'X', 'email' => 'x@example.com', 'password' => 'long-enough', 'role' => 'superadmin'])->assertForbidden();
        $this->put(route('users.update', $victim), ['name' => 'X', 'email' => $victim->email, 'role' => 'superadmin'])->assertForbidden();
        $this->delete(route('users.destroy', $victim))->assertForbidden();

        $this->assertNull(User::query()->where('email', 'x@example.com')->first());
        $this->assertNotNull(User::query()->find($victim->id));
    }
}
