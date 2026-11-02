<?php

declare(strict_types=1);

namespace App\Telegram\Contracts;

use App\Telegram\OutgoingFile;
use App\Telegram\Profile;

/** An open, authorized account session. */
interface TelegramSession
{
    public function me(): Profile;

    /**
     * Sends a text (HTML parse mode) and returns Telegram's message id when known.
     *
     * @param  string|array<string, mixed>  $peer  a username, a t.me link, an id, or a resolved InputPeer
     */
    public function sendMessage(string|array $peer, string $text): ?int;

    /**
     * Sends a file with a caption (HTML, up to 1024 characters) and returns Telegram's
     * message id. The file is uploaded once per session; later sends reuse the upload.
     *
     * @param  string|array<string, mixed>  $peer
     */
    public function sendFile(string|array $peer, OutgoingFile $file, string $caption): ?int;

    /** Resolves a Telegram user id to a peer this session can write to, or null. */
    public function peerByUserId(string $userId): string|array|null;

    /** Resolves a phone number to a peer, or null when nobody uses it or it is hidden. */
    public function peerByPhone(string $phone): string|array|null;

    /**
     * @return array{messages: list<array<string, mixed>>, users: list<array<string, mixed>>, chats: list<array<string, mixed>>}
     */
    public function history(string $peer, int $limit): array;

    /** Downloads a message's media to a file and returns the final path. */
    public function download(mixed $media, string $path): string;

    /**
     * The work with this session is done: whatever holds it in the background stops
     * (saving it) instead of living on. Never throws; the session is not used after.
     */
    public function close(): void;
}
