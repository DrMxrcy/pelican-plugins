<?php

namespace DrMxrcy\PlayersPlus\Services;

use App\Enums\SubuserPermission;
use App\Facades\Activity;
use App\Models\Server;
use App\Models\User;
use DrMxrcy\PlayersPlus\Enums\ModerationAction;
use DrMxrcy\PlayersPlus\Support\ConsoleText;
use DrMxrcy\PlayersPlus\Support\ModerationLists;
use DrMxrcy\PlayersPlus\Support\PlayerName;
use DrMxrcy\PlayersPlus\Support\Settings;
use LogicException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Throwable;

/**
 * The only place moderation console commands are built and sent. A command is the fixed
 * vanilla word of a ModerationAction, a name that passed PlayerName, and for kick and ban a
 * reason cleaned by ConsoleText; the result is checked once more before it leaves.
 *
 * run() repeats every gate itself (permission, setting, running server), so a caller that
 * forgot one, or a direct Livewire call that skipped the UI, still cannot send a command.
 */
class ModerationService
{
    /**
     * @throws ModerationException INVALID_NAME when $name is not a plain Java player name
     */
    public static function command(ModerationAction $action, string $name, ?string $reason = null): string
    {
        if (!PlayerName::isValid($name)) {
            throw new ModerationException(ModerationException::INVALID_NAME);
        }

        $command = $action->command() . ' ' . $name;
        if ($action->takesReason()) {
            $reason = ConsoleText::reason($reason);
            if ($reason !== '') {
                $command .= ' ' . $reason;
            }
        }

        self::assertSafe($action, $command);

        return $command;
    }

    /**
     * Sends one moderation command to the server's console as $user and logs it in the
     * server's activity log. Returns the command that was sent.
     *
     * @throws AccessDeniedHttpException $user lacks control.console on $server
     * @throws ModerationException DISABLED, INVALID_NAME, OFFLINE or FAILED; nothing was sent
     *                             unless FAILED came from the daemon's answer
     */
    public function run(Server $server, User $user, ModerationAction $action, string $name, ?string $reason = null): string
    {
        if (!$user->can(SubuserPermission::ControlConsole, $server)) {
            throw new AccessDeniedHttpException();
        }
        if (!Settings::moderationEnabled()) {
            throw new ModerationException(ModerationException::DISABLED);
        }

        $command = self::command($action, $name, $reason);

        if (!self::isRunning($server)) {
            throw new ModerationException(ModerationException::OFFLINE);
        }

        try {
            $response = $server->send($command);
        } catch (Throwable $exception) {
            throw new ModerationException(ModerationException::FAILED, $exception);
        }

        $status = $response->getStatusCode();
        if ($status === 502) {
            // Wings answers 502 when the server is not running.
            throw new ModerationException(ModerationException::OFFLINE);
        }
        if ($status < 200 || $status >= 300) {
            throw new ModerationException(ModerationException::FAILED);
        }

        // The server rewrites its list file right after; reads must not serve the cached copy.
        ModerationLists::markChanged($server);

        // The panel's own event for console commands, so it reads like one typed in the console.
        Activity::event('server:console.command')
            ->subject($server)
            ->actor($user)
            ->property('command', $command)
            ->log();

        return $command;
    }

    /**
     * Whether the server accepts console commands right now: the same rule as the panel's
     * console input (not suspended, installing, transferring...; container starting or running).
     */
    public static function isRunning(Server $server): bool
    {
        if ($server->isInConflictState()) {
            return false;
        }

        try {
            return $server->retrieveStatus()->isStartingOrRunning();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Last line of defence: whatever changes above, a command that is not exactly one line of
     * "<listed command> <valid name>[ <clean reason>]" is never sent.
     */
    private static function assertSafe(ModerationAction $action, string $command): void
    {
        $prefix = $action->command() . ' ';
        $rest = substr($command, strlen($prefix));
        $pattern = $action->takesReason()
            ? '/^[A-Za-z0-9_]{3,16}(?: [^ ][^\p{C}\p{Zl}\p{Zp}§]{0,' . (ConsoleText::MAX_REASON - 1) . '})?\z/u'
            : '/^[A-Za-z0-9_]{3,16}\z/';

        if (!str_starts_with($command, $prefix)
            || !mb_check_encoding($command, 'UTF-8')
            || preg_match('/[\p{C}\p{Zl}\p{Zp}§]/u', $command) !== 0
            || preg_match($pattern, $rest) !== 1) {
            throw new LogicException('Refusing to send an unsafe console command.');
        }
    }
}
