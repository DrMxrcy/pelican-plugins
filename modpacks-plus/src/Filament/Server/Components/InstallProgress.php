<?php

namespace DrMxrcy\ModpacksPlus\Filament\Server\Components;

use DrMxrcy\ModpacksPlus\Models\PackInstall;
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

        return new HtmlString('<ul>'.implode('', array_map(fn (string $line) => '<li>'.e($line).'</li>', $lines)).'</ul>');
    }
}
