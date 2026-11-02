<?php

declare(strict_types=1);

namespace App\Telegram\Madeline;

use App\Telegram\Contracts\TelegramSession;
use App\Telegram\Errors\ErrorKind;
use App\Telegram\Errors\TelegramError;
use App\Telegram\OutgoingFile;
use App\Telegram\Phone;
use App\Telegram\Profile;
use danog\MadelineProto\API;
use danog\MadelineProto\EventHandler\Media;
use danog\MadelineProto\EventHandler\Media\Document;
use danog\MadelineProto\EventHandler\Media\Photo;
use danog\MadelineProto\LocalFile;
use danog\MadelineProto\ParseMode;
use RuntimeException;
use Throwable;

final class MadelineSession implements TelegramSession
{
    /** @var array<string, Media> file path => the media of the first message that carried it */
    private array $uploaded = [];

    public function __construct(private readonly API $api) {}

    public function me(): Profile
    {
        return NoticeShield::guard(function (): Profile {
            $self = $this->api->getSelf();
            if ($self === false) {
                throw new RuntimeException('SESSION_NOT_AUTHORIZED');
            }

            return Profile::fromSelf($self);
        });
    }

    public function sendMessage(string|array $peer, string $text): ?int
    {
        return NoticeShield::guard(function () use ($peer, $text): ?int {
            $updates = $this->api->messages->sendMessage(peer: $peer, message: $text, parse_mode: 'HTML');

            try {
                return $this->api->extractMessageId($updates);
            } catch (Throwable) {
                return null; // sent, but the id is not in the answer
            }
        });
    }

    public function sendFile(string|array $peer, OutgoingFile $file, string $caption): ?int
    {
        return NoticeShield::guard(function () use ($peer, $file, $caption): ?int {
            $peer = is_array($peer) ? $this->api->getId($peer) : $peer;
            $source = $this->uploaded[$file->path] ?? new LocalFile($file->path);

            $message = $file->asPhoto
                ? $this->api->sendPhoto(peer: $peer, file: $source, caption: $caption, parseMode: ParseMode::HTML, fileName: $file->name)
                : $this->api->sendDocument(peer: $peer, file: $source, caption: $caption, parseMode: ParseMode::HTML, fileName: $file->name, mimeType: $file->mimeType);

            // Madeline reuses an upload only when the media type matches; anything else
            // would be downloaded and uploaded again, so the local file is better then.
            if ($message->media instanceof ($file->asPhoto ? Photo::class : Document::class)) {
                $this->uploaded[$file->path] = $message->media;
            }

            return $message->id;
        });
    }

    public function peerByUserId(string $userId): string|array|null
    {
        return NoticeShield::guard(function () use ($userId): string|array|null {
            try {
                return $this->api->getInfo($userId)['InputPeer'] ?? null;
            } catch (Throwable) {
                return null;
            }
        });
    }

    public function peerByPhone(string $phone): string|array|null
    {
        return NoticeShield::guard(function () use ($phone): string|array|null {
            try {
                $resolved = $this->api->contacts->resolvePhone(phone: Phone::normalize($phone));
            } catch (Throwable $e) {
                // "nobody uses this number" is an answer; a flood wait or a dead session is not
                if (in_array(TelegramError::from($e)->kind, [ErrorKind::Peer, ErrorKind::Unknown], true)) {
                    return null;
                }
                throw $e;
            }
            $id = $resolved['users'][0]['id'] ?? null;

            return $id !== null ? $this->api->getInfo($id)['InputPeer'] ?? null : null;
        });
    }

    public function history(string $peer, int $limit): array
    {
        return NoticeShield::guard(function () use ($peer, $limit): array {
            $result = $this->api->messages->getHistory(peer: $peer, limit: $limit);

            return [
                'messages' => array_values($result['messages'] ?? []),
                'users' => array_values($result['users'] ?? []),
                'chats' => array_values($result['chats'] ?? []),
            ];
        });
    }

    public function download(mixed $media, string $path): string
    {
        return NoticeShield::guard(fn (): string => $this->api->downloadToFile($media, $path));
    }

    public function close(): void
    {
        NoticeShield::guard(fn () => SessionSleep::put($this->api));
    }
}
