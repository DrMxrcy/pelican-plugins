<?php

namespace DrMxrcy\PlayersPlus\Filament\Server\Concerns;

use App\Enums\SubuserPermission;
use App\Models\Server;
use Closure;
use DrMxrcy\PlayersPlus\Enums\ModerationAction;
use DrMxrcy\PlayersPlus\Enums\SampleStatus;
use DrMxrcy\PlayersPlus\Models\Sample;
use DrMxrcy\PlayersPlus\Services\ModerationException;
use DrMxrcy\PlayersPlus\Services\ModerationService;
use DrMxrcy\PlayersPlus\Support\ConsoleText;
use DrMxrcy\PlayersPlus\Support\PlayerList;
use DrMxrcy\PlayersPlus\Support\PlayerName;
use DrMxrcy\PlayersPlus\Support\Settings;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

/**
 * Kick, ban, pardon, whitelist and op actions for the Players pages. Each action is hidden
 * unless canModerate(), and its handler checks canModerate() again before anything else (a
 * hidden button is not a permission check: Livewire calls can skip the UI); the command
 * itself is built and sent only by ModerationService, which checks a third time.
 */
trait ModeratesPlayers
{
    private ?Sample $moderationSample = null;

    private bool $moderationSampleLoaded = false;

    /** Moderation is on, and the current user may use this server's console. */
    public static function canModerate(): bool
    {
        $server = Filament::getTenant();
        $user = user();

        return Settings::moderationEnabled()
            && $server instanceof Server
            && $user !== null
            && $user->can(SubuserPermission::ControlConsole, $server);
    }

    /**
     * Whether the stored data says the server is not running: the newest sample is fresh and
     * offline. Only greys the buttons out (no daemon call while rendering); the handler asks
     * the daemon before sending, so an unknown state never lets a command through.
     */
    protected function serverLooksOffline(): bool
    {
        if (!$this->moderationSampleLoaded) {
            /** @var Server $server */
            $server = Filament::getTenant();
            $this->moderationSample = Sample::latestFor($server);
            $this->moderationSampleLoaded = true;
        }

        $sample = $this->moderationSample;

        return $sample !== null && $sample->status === SampleStatus::Offline && !PlayerList::isStale($sample);
    }

    /**
     * One moderation action for the player $name resolves to (from the row record, or the
     * page's player when there is no record).
     *
     * @param  Closure(mixed): mixed  $name
     */
    protected function moderationAction(ModerationAction $action, Closure $name): Action
    {
        $danger = in_array($action, [ModerationAction::Kick, ModerationAction::Ban, ModerationAction::Op], true);

        return Action::make('pp_' . $action->value)
            ->label($action->getLabel())
            ->icon(match ($action) {
                ModerationAction::Kick => 'tabler-door-exit',
                ModerationAction::Ban => 'tabler-ban',
                ModerationAction::Pardon => 'tabler-circle-check',
                ModerationAction::WhitelistAdd => 'tabler-user-plus',
                ModerationAction::WhitelistRemove => 'tabler-user-minus',
                ModerationAction::Op => 'tabler-shield-star',
                ModerationAction::Deop => 'tabler-shield-off',
            })
            ->color($danger ? 'danger' : 'gray')
            ->visible(fn (mixed $record = null) => static::canModerate() && PlayerName::isValid($name($record)))
            ->authorize(fn () => static::canModerate())
            ->disabled(fn () => $this->serverLooksOffline())
            ->tooltip(fn () => $this->serverLooksOffline() ? trans('players-plus::strings.moderation.offline_tooltip') : null)
            ->requiresConfirmation()
            ->modalHeading(fn (mixed $record = null) => trans('players-plus::strings.moderation.confirm_heading', [
                'action' => $action->getLabel(),
                'name' => is_string($n = $name($record)) ? $n : '',
            ]))
            ->modalDescription(fn (mixed $record = null) => trans('players-plus::strings.moderation.confirm_body', [
                'command' => PlayerName::isValid($n = $name($record)) ? ModerationService::command($action, (string) $n) : '',
            ]))
            ->modalSubmitActionLabel($action->getLabel())
            ->schema($action->takesReason() ? [
                TextInput::make('reason')
                    ->label(trans('players-plus::strings.moderation.reason'))
                    ->helperText(trans('players-plus::strings.moderation.reason_help', ['max' => ConsoleText::MAX_REASON]))
                    ->maxLength(ConsoleText::MAX_REASON),
            ] : [])
            ->action(function (mixed $record = null, array $data = []) use ($action, $name) {
                $this->runModeration($action, $name($record), $data['reason'] ?? null);
            });
    }

    /** "Add to whitelist" by name, for players this plugin has never seen. */
    protected function whitelistByNameAction(): Action
    {
        return Action::make('pp_whitelist_add_name')
            ->label(trans('players-plus::strings.moderation.add_by_name'))
            ->icon('tabler-user-plus')
            ->color('gray')
            ->visible(fn () => static::canModerate())
            ->authorize(fn () => static::canModerate())
            ->disabled(fn () => $this->serverLooksOffline())
            ->tooltip(fn () => $this->serverLooksOffline() ? trans('players-plus::strings.moderation.offline_tooltip') : null)
            ->modalHeading(trans('players-plus::strings.moderation.add_by_name'))
            ->modalSubmitActionLabel(ModerationAction::WhitelistAdd->getLabel())
            ->schema([
                TextInput::make('name')
                    ->label(trans('players-plus::strings.page.name'))
                    ->helperText(trans('players-plus::strings.moderation.name_help'))
                    ->required()
                    ->minLength(3)
                    ->maxLength(16)
                    ->regex('/^[A-Za-z0-9_]{3,16}\z/'),
            ])
            ->action(function (array $data = []) {
                $this->runModeration(ModerationAction::WhitelistAdd, $data['name'] ?? null, null);
            });
    }

    /**
     * The handler behind every moderation action. Checks the permission and the setting
     * itself, validates the name, and lets ModerationService send (it checks again).
     */
    protected function runModeration(ModerationAction $action, mixed $name, mixed $reason): void
    {
        abort_unless(static::canModerate(), 403);

        /** @var Server $server */
        $server = Filament::getTenant();
        $user = user();
        abort_if($user === null, 403);

        try {
            if (!is_string($name)) {
                throw new ModerationException(ModerationException::INVALID_NAME);
            }
            $command = app(ModerationService::class)->run($server, $user, $action, $name, is_string($reason) ? $reason : null);
        } catch (ModerationException $exception) {
            Notification::make()
                ->title(trans('players-plus::strings.moderation.failed_title'))
                ->body(trans('players-plus::strings.moderation.errors.' . $exception->reason))
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(trans('players-plus::strings.moderation.sent_title'))
            ->body(trans('players-plus::strings.moderation.sent_body', ['command' => $command]))
            ->success()
            ->send();

        // The server rewrites its list file a moment after the command; look again then.
        $this->js('setTimeout(() => $wire.$refresh(), 2000)');
    }
}
