<?php

declare(strict_types=1);

namespace App\Telegram\Contracts;

use App\Telegram\Profile;

/**
 * Everything the app asks of Telegram, behind one interface. The real
 * implementation is MadelineGateway; tests bind a fake.
 *
 * Methods throw whatever the library throws; callers turn it into a
 * TelegramError with ErrorMap. Never call this from a web request.
 */
interface TelegramGateway
{
    /** Asks Telegram to send a login code to the phone. */
    public function requestCode(string $sessionPath, string $phone): void;

    /**
     * Submits the login code.
     *
     * @return array{password_required: bool, hint: ?string, profile: ?Profile}
     */
    public function submitCode(string $sessionPath, string $code): array;

    public function submitPassword(string $sessionPath, string $password): Profile;

    /** Opens an authorized session; throws when the session is not usable. */
    public function open(string $sessionPath): TelegramSession;

    /** Logs the session out on Telegram's side. */
    public function logout(string $sessionPath): void;

    /**
     * Kills whatever holds the session in the background without letting it save:
     * one that hangs. The next open() starts afresh. Never throws.
     */
    public function killWorker(string $sessionPath): void;
}
