<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Telegram\Contracts\TelegramGateway;
use App\Telegram\Contracts\TelegramSession;
use App\Telegram\OutgoingFile;
use App\Telegram\Profile;
use RuntimeException;
use Throwable;

/**
 * Telegram for tests. Records every call; failures are scripted per peer
 * ("@peer" => exception) or for the next call of a method.
 */
final class FakeTelegram implements TelegramGateway, TelegramSession
{
    /** @var list<array{0: string, 1: mixed}> */
    public array $calls = [];

    /** @var array<string, Throwable> peer => error thrown by sendMessage */
    public array $peerErrors = [];

    /** @var array<string, Throwable> method => error thrown once */
    public array $nextErrors = [];

    public bool $passwordRequired = false;

    public ?Throwable $openError = null;

    private int $messageId = 1000;

    public function failPeer(string $peer, string $rpc): self
    {
        $this->peerErrors[$peer] = new RuntimeException($rpc);

        return $this;
    }

    public function failNext(string $method, string $rpc): self
    {
        $this->nextErrors[$method] = new RuntimeException($rpc);

        return $this;
    }

    /** @return list<string> peers that received a message (text or file) */
    public function sentTo(): array
    {
        return array_values(array_map(fn ($c) => $c[1]['peer'], array_filter($this->calls, fn ($c) => in_array($c[0], ['sendMessage', 'sendFile'], true))));
    }

    /** @return list<array{peer: string, name: string, caption: string, photo: bool}> */
    public function filesSent(): array
    {
        return array_values(array_map(fn ($c) => $c[1], array_filter($this->calls, fn ($c) => $c[0] === 'sendFile')));
    }

    /** How many times a method was called. */
    public function count(string $method): int
    {
        return count(array_filter($this->calls, fn ($c) => $c[0] === $method));
    }

    public function requestCode(string $sessionPath, string $phone): void
    {
        $this->record('requestCode', compact('sessionPath', 'phone'));
    }

    public function submitCode(string $sessionPath, string $code): array
    {
        $this->record('submitCode', compact('code'));

        return $this->passwordRequired
            ? ['password_required' => true, 'hint' => 'my hint', 'profile' => null]
            : ['password_required' => false, 'hint' => null, 'profile' => $this->me()];
    }

    public function submitPassword(string $sessionPath, string $password): Profile
    {
        $this->record('submitPassword', compact('password'));

        return $this->me();
    }

    public function open(string $sessionPath): TelegramSession
    {
        $this->record('open', compact('sessionPath'));
        if ($this->openError) {
            throw $this->openError;
        }

        return $this;
    }

    public function logout(string $sessionPath): void
    {
        $this->record('logout', compact('sessionPath'));
    }

    public function killWorker(string $sessionPath): void
    {
        $this->record('killWorker', compact('sessionPath'));
    }

    public function me(): Profile
    {
        return new Profile('777', 'Test', 'Account', 'test_account');
    }

    public function sendMessage(string|array $peer, string $text): ?int
    {
        $key = is_string($peer) ? $peer : json_encode($peer);
        if (isset($this->peerErrors[$key])) {
            $error = $this->peerErrors[$key];
            unset($this->peerErrors[$key]);
            $this->record('sendMessageFailed', ['peer' => $key]);
            throw $error;
        }
        $this->record('sendMessage', ['peer' => $key, 'text' => $text]);

        return ++$this->messageId;
    }

    public function sendFile(string|array $peer, OutgoingFile $file, string $caption): ?int
    {
        $key = is_string($peer) ? $peer : json_encode($peer);
        if (isset($this->peerErrors[$key])) {
            $error = $this->peerErrors[$key];
            unset($this->peerErrors[$key]);
            $this->record('sendFileFailed', ['peer' => $key]);
            throw $error;
        }
        $this->record('sendFile', ['peer' => $key, 'name' => $file->name, 'caption' => $caption, 'photo' => $file->asPhoto]);

        return ++$this->messageId;
    }

    public function peerByUserId(string $userId): string|array|null
    {
        return $userId === 'unknown' ? null : ['_' => 'inputPeerUser', 'user_id' => $userId];
    }

    public function peerByPhone(string $phone): string|array|null
    {
        return str_ends_with($phone, '0000') ? null : ['_' => 'inputPeerUser', 'phone' => $phone];
    }

    public function history(string $peer, int $limit): array
    {
        return ['messages' => [], 'users' => [], 'chats' => []];
    }

    public function download(mixed $media, string $path): string
    {
        return $path;
    }

    public function close(): void
    {
        $this->calls[] = ['close', null];
    }

    private function record(string $method, mixed $args): void
    {
        if (isset($this->nextErrors[$method])) {
            $error = $this->nextErrors[$method];
            unset($this->nextErrors[$method]);
            throw $error;
        }
        $this->calls[] = [$method, $args];
    }
}
