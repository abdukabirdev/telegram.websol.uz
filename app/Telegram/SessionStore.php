<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Telegram\Madeline\SessionSleep;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Where session files live, and the only code allowed to delete them.
 *
 * Old sessions keep the absolute path stored on their user_phones row. New ones
 * follow the same naming the old code used ("{digits}_user_{id}.madeline").
 * Deleting is refused for any path outside the sessions directory, so a bad
 * value in the database can never remove something else.
 */
final class SessionStore
{
    /** @var array<string, ProcessLock> session path => lock this process holds until it exits */
    private static array $held = [];

    /**
     * Claims a session for the rest of this process, waiting up to
     * telegram.session_busy_wait seconds for another process to let it go.
     *
     * Never released by hand: MadelineProto saves the session while the process
     * shuts down, and the OS drops the lock only after that. So a second login
     * (a double click) can't delete a session another process is still writing.
     */
    public function holdUntilExit(string $path): bool
    {
        if (isset(self::$held[$path])) {
            return true;
        }

        $lock = ProcessLock::for('session-'.sha1($path));
        if (! $lock->acquireWithin((int) config('telegram.session_busy_wait'))) {
            return false;
        }
        self::$held[$path] = $lock;

        return true;
    }

    public function pathFor(string $phone, int $userId): string
    {
        return $this->root().DIRECTORY_SEPARATOR.Phone::digits($phone)."_user_{$userId}.madeline";
    }

    /** The phone a session file belongs to, from its name ("{digits}_user_{id}", old "+{digits}_add_user_{id}"). */
    public static function phoneOf(string $path): ?string
    {
        return preg_match('/^\+?(\d{7,15})_/', basename($path), $m) === 1 ? '+'.$m[1] : null;
    }

    public function exists(?string $path): bool
    {
        return $path !== null && $path !== '' && file_exists($path);
    }

    public function delete(?string $path): void
    {
        if (! $this->exists($path) || ! $this->isInside($path)) {
            return;
        }

        // A worker left on the session would write it back half or keep it in use; it
        // has nothing worth saving any more.
        SessionSleep::kill($path);

        File::isDirectory($path) ? File::deleteDirectory($path) : File::delete($path);

        // MadelineProto keeps sidecar files next to the session (.lock, .ipc, .safe.php ...).
        foreach (File::glob($path.'.*') as $sidecar) {
            File::isDirectory($sidecar) ? File::deleteDirectory($sidecar) : File::delete($sidecar);
        }
    }

    public function root(): string
    {
        $root = (string) config('telegram.sessions_path');
        File::ensureDirectoryExists($root, 0750);

        return $root;
    }

    private function isInside(string $path): bool
    {
        $root = realpath($this->root());
        $real = realpath($path);

        return $root !== false && $real !== false && Str::startsWith($real, $root.DIRECTORY_SEPARATOR);
    }
}
