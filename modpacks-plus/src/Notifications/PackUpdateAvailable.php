<?php

namespace DrMxrcy\ModpacksPlus\Notifications;

use DrMxrcy\ModpacksPlus\Models\PackInstall;
use Filament\Notifications\Notification;

/**
 * "Update available" for a server's owner (plan 36), as a Filament database notification.
 * It names only the pack, its installed and new version numbers and the server's name, each
 * HTML-escaped (no link, image or style from pack metadata reaches the bell): no
 * variable, startup, image, path or key ever goes into it (owners of shared panels see their
 * notifications in the bell, and admins can read the table).
 */
final class PackUpdateAvailable
{
    /** Whether a notification was sent (false when the server has no owner). */
    public static function send(PackInstall $install): bool
    {
        $server = $install->server;
        $owner = $server?->user;
        if ($server === null || $owner === null) {
            return false;
        }

        $locale = is_string($owner->language ?? null) && $owner->language !== '' ? $owner->language : null;
        // Pack names and version numbers come from the pack's source: escaped, because Filament
        // renders notification text as (sanitised) HTML, which would still allow links, images
        // (fetched from the owner's browser) and styles.
        $text = fn (string $value): string => e(mb_strimwidth(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '', 0, 100, '…'));

        Notification::make()
            ->title(trans('modpacks-plus::strings.notifications.update_title', ['name' => $text($install->name)], $locale))
            ->body(trans('modpacks-plus::strings.notifications.update_body', [
                'server' => $text((string) $server->name),
                'name' => $text($install->name),
                'installed' => $text((string) ($install->version_number ?? $install->version_id)),
                'version' => $text((string) ($install->latest_version_number ?? $install->latest_version_id)),
            ], $locale))
            ->icon('tabler-package')
            ->info()
            ->sendToDatabase($owner);

        return true;
    }
}
