<?php

namespace DrMxrcy\ModpacksPlus\Filament\Server\Components;

use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Services\ModrinthMetadataWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/**
 * Renders an install's step list, log, error and summary as escaped HTML for the
 * Modpacks page's progress section (which polls while the install runs). Every
 * value that came from a pack or the API is escaped here.
 */
final class InstallProgress
{
    private const LOG_LINES = 30;

    public static function statusColor(PackInstall $install): string
    {
        return match ($install->status) {
            PackInstall::STATUS_INSTALLED => 'success',
            PackInstall::STATUS_FAILED => 'danger',
            PackInstall::STATUS_CANCELLED => 'warning',
            default => 'info',
        };
    }

    public static function steps(PackInstall $install): HtmlString
    {
        $items = '';
        foreach ($install->steps ?? [] as $step) {
            $status = (string) $step['status'];
            $marker = match ($status) {
                PackInstall::STEP_DONE => '[x]',
                PackInstall::STEP_SKIPPED => '[-]',
                PackInstall::STEP_RUNNING => '[>]',
                PackInstall::STEP_FAILED, PackInstall::STEP_CANCELLED => '[!]',
                default => '[ ]',
            };

            $items .= '<li style="font-family: ui-monospace, monospace">'.e($marker).' '
                .e(trans('modpacks-plus::strings.steps.'.$step['key'])).' — '
                .e(trans('modpacks-plus::strings.status.'.$status)).'</li>';
        }

        return new HtmlString('<ul>'.$items.'</ul>');
    }

    public static function log(PackInstall $install, ?string $timezone = null): HtmlString
    {
        $lines = array_reverse(array_slice($install->log ?? [], -self::LOG_LINES));

        $items = '';
        foreach ($lines as $line) {
            $time = Carbon::parse($line['at'])->timezone($timezone ?? 'UTC')->format('H:i:s');
            $params = $line['params'];
            if ($line['key'] === 'failed' && is_string($params['reason'] ?? null)) {
                $params['reason'] = self::reason($params['reason'], '');
            }

            $items .= '<li style="font-family: ui-monospace, monospace; font-size: 0.85em">'
                .e($time).' '.e(trans('modpacks-plus::strings.log.'.$line['key'], array_map(fn ($value) => (string) $value, $params))).'</li>';
        }

        return new HtmlString('<ul>'.$items.'</ul>');
    }

    public static function reason(string $reason, string $detail): string
    {
        return trans('modpacks-plus::strings.reasons.'.$reason, ['detail' => $detail]);
    }

    public static function error(PackInstall $install): ?string
    {
        if ($install->error === null) {
            return null;
        }

        return self::reason($install->error, (string) $install->progressValue('error_detail', ''));
    }

    public static function summary(PackInstall $install): HtmlString
    {
        $summary = $install->summary ?? [];
        $lines = [];

        if (!empty($summary['backup_uuid'])) {
            $lines[] = trans('modpacks-plus::strings.summary.backup', ['uuid' => $summary['backup_uuid']]);
        } elseif (!empty($summary['backup_skipped'])) {
            $lines[] = trans('modpacks-plus::strings.summary.backup_skipped');
        }

        if (!empty($summary['moved'])) {
            $lines[] = trans('modpacks-plus::strings.summary.moved', [
                'folders' => implode(', ', array_map('strval', (array) $summary['moved'])),
                'path' => (string) ($summary['moved_aside_path'] ?? ''),
            ]);
        }

        if (isset($summary['files_total'])) {
            $lines[] = trans('modpacks-plus::strings.summary.downloaded', ['done' => $summary['downloaded'] ?? 0, 'total' => $summary['files_total']]);
        }
        if (isset($summary['overrides_total'])) {
            $lines[] = trans('modpacks-plus::strings.summary.overrides', ['done' => $summary['overrides_applied'] ?? 0, 'total' => $summary['overrides_total']]);
        }
        if (!empty($summary['skipped_client_files'])) {
            $lines[] = trans('modpacks-plus::strings.summary.skipped_client', ['count' => $summary['skipped_client_files']]);
        }
        if (!empty($summary['skipped_overrides'])) {
            $lines[] = trans('modpacks-plus::strings.summary.skipped_overrides', ['paths' => implode(', ', array_map('strval', (array) $summary['skipped_overrides']))]);
        }

        if (!empty($summary['skipped_client_overrides'])) {
            $lines[] = trans('modpacks-plus::strings.summary.skipped_client_overrides', ['paths' => implode(', ', array_map('strval', (array) $summary['skipped_client_overrides']))]);
        }

        array_push($lines, ...self::metadataLines($summary['modrinth_metadata'] ?? null));

        if ($install->restored_at !== null) {
            $lines[] = trans('modpacks-plus::strings.summary.restored');
        } elseif ($install->isRestoring()) {
            $lines[] = trans('modpacks-plus::strings.summary.restoring');
        }

        $switch = $summary['loader_switch'] ?? null;
        if (is_array($switch)) {
            $lines = array_merge($lines, self::switchLines($switch, $install));
        }

        return new HtmlString('<ul>'.implode('', array_map(fn (string $line) => '<li>'.e($line).'</li>', $lines)).'</ul>');
    }

    /**
     * What the hand-off to Modrinth Plus (`mods/.modrinth-metadata.json`, plan 33) did.
     *
     * @return array<int, string>
     */
    private static function metadataLines(mixed $result): array
    {
        $status = is_array($result) && is_string($result['status'] ?? null) ? $result['status'] : null;
        if ($status === null) {
            return [];
        }

        /** @var array<string, mixed> $result */
        $lines = [];
        $key = 'modpacks-plus::strings.summary.metadata.'.$status;
        if ($status !== 'nothing' && trans()->has($key)) {
            $lines[] = trans($key, [
                'added' => (int) ($result['added'] ?? 0),
                'updated' => (int) ($result['updated'] ?? 0),
                'path' => ModrinthMetadataWriter::PATH,
            ]);
        }

        foreach (['kept', 'conflicts', 'skipped'] as $list) {
            if (!empty($result[$list]) && is_array($result[$list])) {
                $lines[] = trans('modpacks-plus::strings.summary.metadata_'.$list, ['files' => implode(', ', array_map('strval', $result[$list]))]);
            }
        }

        if (is_string($result['previous'] ?? null)) {
            $lines[] = trans('modpacks-plus::strings.summary.metadata_previous', ['path' => $result['previous']]);
        }

        return $lines;
    }

    /**
     * What a loader switch changed and what the server had before (the egg is never switched back).
     *
     * @param  array<string, mixed>  $switch
     * @return array<int, string>
     */
    private static function switchLines(array $switch, PackInstall $install): array
    {
        $lines = [];
        $previous = is_array($switch['previous'] ?? null) ? $switch['previous'] : null;

        if (!empty($switch['egg_changed'])) {
            $lines[] = trans('modpacks-plus::strings.summary.switched', ['from' => (string) ($previous['egg_name'] ?? '?'), 'to' => (string) ($switch['egg_name'] ?? '?'), 'image' => (string) ($switch['image'] ?? '')]);
        } elseif (is_string($switch['egg_name'] ?? null)) {
            $lines[] = trans('modpacks-plus::strings.summary.not_switched', ['egg' => (string) ($previous['egg_name'] ?? '?')]);
        }

        if ($previous !== null) {
            $variables = [];
            foreach (is_array($previous['variables'] ?? null) ? $previous['variables'] : [] as $name => $value) {
                $variables[] = $name.'='.(is_scalar($value) ? (string) $value : '');
            }
            $lines[] = trans('modpacks-plus::strings.summary.previous', [
                'egg' => (string) ($previous['egg_name'] ?? '?'),
                'image' => (string) ($previous['image'] ?? ''),
                'startup' => (string) ($previous['startup'] ?? ''),
                'variables' => implode(', ', $variables),
            ]);
        }

        $preserved = is_array($switch['preserved'] ?? null) ? array_map('strval', $switch['preserved']) : [];
        if ($preserved !== []) {
            $lines[] = empty($switch['preserved_restored']) && $install->restored_at === null
                ? trans('modpacks-plus::strings.summary.preserved_aside', ['paths' => implode(', ', $preserved), 'path' => (string) ($switch['preserved_path'] ?? '')])
                : trans('modpacks-plus::strings.summary.preserved_back', ['paths' => implode(', ', $preserved)]);
        }

        return $lines;
    }
}
