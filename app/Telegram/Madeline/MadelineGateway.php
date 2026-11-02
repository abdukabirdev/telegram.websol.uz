<?php

declare(strict_types=1);

namespace App\Telegram\Madeline;

use App\Telegram\Contracts\TelegramGateway;
use App\Telegram\Contracts\TelegramSession;
use App\Telegram\Profile;
use App\Telegram\SessionStore;
use danog\MadelineProto\API;
use RuntimeException;

final class MadelineGateway implements TelegramGateway
{
    public function __construct(
        private readonly SettingsFactory $settings,
        private readonly SessionStore $sessions,
    ) {}

    public function requestCode(string $sessionPath, string $phone): void
    {
        NoticeShield::guard(fn () => $this->api($sessionPath)->phoneLogin($phone));
    }

    public function submitCode(string $sessionPath, string $code): array
    {
        return NoticeShield::guard(function () use ($sessionPath, $code): array {
            $api = $this->api($sessionPath);
            $result = $api->completePhoneLogin($code);

            return match ($result['_'] ?? null) {
                // the session's worker stays up for the password step
                'account.password' => ['password_required' => true, 'hint' => $result['hint'] ?? null, 'profile' => null],
                'account.needSignup' => throw new RuntimeException('ACCOUNT_NOT_REGISTERED'),
                default => ['password_required' => false, 'hint' => null, 'profile' => $this->loggedIn($api)],
            };
        });
    }

    public function submitPassword(string $sessionPath, string $password): Profile
    {
        return NoticeShield::guard(function () use ($sessionPath, $password): Profile {
            $api = $this->api($sessionPath);
            $api->complete2faLogin($password);

            return $this->loggedIn($api);
        });
    }

    public function open(string $sessionPath): TelegramSession
    {
        if (! file_exists($sessionPath)) {
            throw new RuntimeException('SESSION_MISSING');
        }

        return NoticeShield::guard(function () use ($sessionPath): TelegramSession {
            $api = $this->api($sessionPath);
            if ($api->getAuthorization() !== API::LOGGED_IN) {
                throw new RuntimeException('SESSION_NOT_AUTHORIZED');
            }

            return new MadelineSession($api);
        });
    }

    public function logout(string $sessionPath): void
    {
        if (! file_exists($sessionPath)) {
            return;
        }

        NoticeShield::guard(fn () => $this->api($sessionPath)->logout());
    }

    public function killWorker(string $sessionPath): void
    {
        SessionSleep::kill($sessionPath);
    }

    private function api(string $sessionPath): API
    {
        if (! $this->sessions->holdUntilExit($sessionPath)) {
            throw new RuntimeException('SESSION_BUSY');
        }
        $this->endWorkerAfterJob();

        return new API($sessionPath, $this->settings->make(SessionStore::phoneOf($sessionPath)));
    }

    /**
     * A MadelineProto instance never really closes inside a PHP process: its event
     * loop, IPC server and worker pool outlive the job that opened it. Left alive in a
     * long-running queue worker, it holds the session while the next step of the same
     * login runs in another process, breaks when a new login deletes the session
     * ("Could not read the lightstate file"), and once its pool dies every later job
     * of that process fails with "The pool was shut down". So a queue worker that
     * opened a session exits after the job and supervisor starts a fresh one.
     */
    private function endWorkerAfterJob(): void
    {
        if (app()->resolved('queue.worker')) {
            app('queue.worker')->shouldQuit = true;
        }
    }

    /** The login is complete: the session sleeps until a job needs it (SessionSleep). */
    private function loggedIn(API $api): Profile
    {
        $profile = $this->self($api);
        SessionSleep::put($api);

        return $profile;
    }

    private function self(API $api): Profile
    {
        $self = $api->getSelf();
        if ($self === false) {
            throw new RuntimeException('SESSION_NOT_AUTHORIZED');
        }

        return Profile::fromSelf($self);
    }
}
