<?php

namespace Tests\Feature;

use App\Jobs\Accounts\CheckAccountSession;
use App\Jobs\FetchChatHistory;
use App\Jobs\SendAccountMessages;
use App\Models\Catalog;
use App\Models\ChatLogGroup;
use App\Models\User;
use App\Models\UserPhone;
use App\Sending\SendWorker;
use App\Settings\Settings;
use App\Telegram\Madeline\SessionSleep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** A session's MadelineProto worker ends with the work that opened it (SessionSleep). */
class SessionWorkerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private UserPhone $phone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->user();
        $this->phone = $this->phoneOf($this->user);
        app(Settings::class)->update(['send_delay_min' => 0, 'send_delay_max' => 0]);
    }

    private function send(array $peers): void
    {
        $catalog = Catalog::factory()->for($this->user)->peers($peers)->create();
        $this->actingAs($this->user)->postJson(route('send.store'), [
            'phone_id' => $this->phone->id,
            'catalog_ids' => [$catalog->id],
            'message' => 'Hello',
        ])->assertCreated();
    }

    private function work(): int
    {
        return app(SendWorker::class)->sleepUsing(fn () => null)->run($this->phone->id, 60);
    }

    public function test_every_send_run_puts_its_session_to_sleep(): void
    {
        config(['telegram.worker.messages_per_run' => 1]);
        $this->send(['@a1', '@a2']);

        $this->assertSame(SendWorker::MORE, $this->work());
        $this->assertSame(SendWorker::DONE, $this->work());

        $this->assertSame(2, $this->telegram->count('open'));
        $this->assertSame(2, $this->telegram->count('close'));
        $this->assertSame('close', $this->telegram->calls[array_key_last($this->telegram->calls)][0]);
    }

    public function test_a_run_that_stops_on_an_error_still_puts_its_session_to_sleep(): void
    {
        $this->telegram->failPeer('@alice', 'AUTH_KEY_UNREGISTERED');
        $this->send(['@alice', '@bob']);

        $this->assertSame(SendWorker::NOT_STARTABLE, $this->work());

        $this->assertSame(1, $this->telegram->count('close'));
    }

    public function test_after_a_run_that_hung_the_next_one_kills_the_worker_before_it_opens_the_session(): void
    {
        Bus::fake([SendAccountMessages::class, CheckAccountSession::class]);
        Cache::put(SendWorker::unfinishedKey($this->phone->id), ['pid' => 1, 'started_at' => time() - 400, 'message_id' => null], 3600);

        $this->work();

        $this->assertSame(['killWorker', ['sessionPath' => $this->phone->session_path]], $this->telegram->calls[0]);
        $this->assertSame('open', $this->telegram->calls[1][0]);

        $this->work(); // a run that returned: nothing to kill
        $this->assertSame(1, $this->telegram->count('killWorker'));
    }

    public function test_a_session_check_and_a_chat_history_fetch_put_their_session_to_sleep(): void
    {
        app()->call([new CheckAccountSession($this->phone->id), 'handle']);
        $this->assertSame(['open', 'close'], array_column($this->telegram->calls, 0));

        $chat = ChatLogGroup::query()->create(['user_phone_id' => $this->phone->id, 'peer' => '@alice', 'status' => 'active']);
        app()->call([new FetchChatHistory($chat->id), 'handle']);
        $this->assertSame(['open', 'close', 'open', 'close'], array_column($this->telegram->calls, 0));
    }

    public function test_a_worker_is_found_by_the_whole_path_of_its_session(): void
    {
        $session = '/var/www/websol/storage/app/sessions/998901234567_user_1.madeline';

        // the title the worker sets (padded with NULs), and its plain command line
        $this->assertTrue(SessionSleep::isWorkerOf("MadelineProto worker {$session}\0\0\0", $session));
        $this->assertTrue(SessionSleep::isWorkerOf("/opt/php83/bin/php\0-d\0display_errors=0\0/var/www/websol/vendor/danog/madelineproto/src/Ipc/Runner/entry.php\0madeline-ipc\0{$session}\0123456\0", $session));
        $this->assertTrue(SessionSleep::isWorkerOf("MadelineProto worker {$session}", $session.'/'));

        // the same session name in another project on the server
        $this->assertFalse(SessionSleep::isWorkerOf('MadelineProto worker /var/www/postix/storage/sessions/998901234567_user_1.madeline', $session));
        // a name that only starts like it
        $this->assertFalse(SessionSleep::isWorkerOf("MadelineProto worker {$session}.old", $session));
        $this->assertFalse(SessionSleep::isWorkerOf('MadelineProto worker /var/www/websol/storage/app/sessions/998901234567_user_12.madeline', $session));
        // a process that only mentions the session
        $this->assertFalse(SessionSleep::isWorkerOf("tail\0-f\0{$session}/safe.php", $session));
        $this->assertFalse(SessionSleep::isWorkerOf("ls\0{$session}", $session));
    }

    public function test_killing_a_worker_is_never_tried_off_linux_or_without_a_session(): void
    {
        $this->assertSame([], SessionSleep::kill(null));
        $this->assertSame([], SessionSleep::kill(''));
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->assertSame([], SessionSleep::kill($this->phone->session_path));
        }
    }
}
