<?php

declare(strict_types=1);

namespace App\Telegram\Madeline;

use danog\MadelineProto\API;
use danog\MadelineProto\Ipc\Client;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ends the MadelineProto worker of a session once the work with it is done
 * (ported from Postix).
 *
 * On Linux, new API() on a session that already exists does not hold it in this
 * process: it starts "MadelineProto worker <session path>", a process of its own
 * (setsid, so a supervisor restart leaves it alive) that holds the session and
 * answers over IPC. It has no idle timeout: it outlives the job, reads the
 * updates of every chat of the account for weeks, and a call that hangs in it
 * hangs every later job of the account too. The settings can't turn this off in
 * MadelineProto 8.0 (Settings\Ipc has no setter).
 *
 * Stopping it is safe: the worker saves the session on the way out, and the next
 * new API() starts a fresh one in a few seconds. Our processes never share a
 * session (SessionStore, ProcessLock), so no other call is cut off.
 */
final class SessionSleep
{
    /**
     * Stops the worker behind this API. Never throws: it runs on the way out,
     * often of an error, and must not hide it.
     */
    public static function put(API $api): void
    {
        try {
            // The IPC client sits in a protected property; MadelineProto reaches it the same way to stop its worker.
            $client = (fn () => $this->wrapper->getAPI())->call($api);
            if (! $client instanceof Client) {
                return; // the session lives in this process (first login step, Windows): it ends with it
            }

            if (! file_exists($client->getSessionName())) {
                // Deleted (retired, logged out) and its worker killed with it: only let go,
                // or the client would start the worker again.
                $client->unreference();

                return;
            }

            // No hasEventHandler() check as in Postix: it is a call into the worker and
            // waits for good on one that hangs, and this project has no event handlers.
            $client->stopIpcServer();
        } catch (Throwable $e) {
            Log::channel('telegram')->warning('MadelineProto worker did not stop', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Kills the worker of a session without letting it save: one that hangs (a send
     * run never returned), or one whose session is about to be deleted. There is no
     * API to reach a worker that does not answer, so it is found by its process
     * title. Linux only, never throws.
     *
     * @return list<int> the pids killed
     */
    public static function kill(?string $sessionPath): array
    {
        if (PHP_OS_FAMILY !== 'Linux' || ! function_exists('posix_kill') || $sessionPath === null || $sessionPath === '') {
            return [];
        }

        $killed = [];
        try {
            foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $file) {
                $pid = (int) basename(dirname($file));
                if ($pid !== getmypid() && self::isWorkerOf((string) @file_get_contents($file), $sessionPath) && posix_kill($pid, 9)) {
                    $killed[] = $pid;
                }
            }
        } catch (Throwable) {
            // nothing more to do: the next start of the session tries anew
        }

        if ($killed !== []) {
            Log::channel('telegram')->warning('MadelineProto worker killed', ['session' => basename($sessionPath), 'pids' => $killed]);
        }

        return $killed;
    }

    /**
     * Whether a process command line (/proc/<pid>/cmdline) is the worker of this very
     * session. The whole path must match: other projects on the server run workers
     * too, and a session name is often the tail of another one
     * ("1234567_user_1.madeline" ends "9981234567_user_1.madeline").
     */
    public static function isWorkerOf(string $cmdline, string $sessionPath): bool
    {
        $cmdline = trim(str_replace("\0", ' ', $cmdline));
        // the title the worker sets, or its plain command line where the title could not be set
        if (! str_starts_with($cmdline, 'MadelineProto worker ') && ! str_contains($cmdline, ' madeline-ipc ')) {
            return false;
        }

        $paths = array_unique(array_filter([rtrim($sessionPath, '/'), realpath($sessionPath) ?: null]));
        foreach ($paths as $path) {
            if (preg_match('~(^|\s)'.preg_quote($path, '~').'/?(\s|$)~', $cmdline) === 1) {
                return true;
            }
        }

        return false;
    }
}
