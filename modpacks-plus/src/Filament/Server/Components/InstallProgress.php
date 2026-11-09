<?php

namespace DrMxrcy\ModpacksPlus\Filament\Server\Components;

use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Services\ModrinthMetadataWriter;
use DrMxrcy\ModpacksPlus\Support\DiskBudget;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;
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
                // Plan 38: the disk message carries its sizes in the detail.
                $params['reason'] = self::reason($params['reason'], $params['reason'] === 'not_enough_disk' ? (string) ($params['detail'] ?? '') : '');
            }

            $items .= '<li style="font-family: ui-monospace, monospace; font-size: 0.85em">'
                .e($time).' '.e(trans('modpacks-plus::strings.log.'.$line['key'], array_map(fn ($value) => (string) $value, $params))).'</li>';
        }

        return new HtmlString('<ul>'.$items.'</ul>');
    }

    public static function reason(string $reason, string $detail): string
    {
        $params = ['detail' => $detail];
        if ($reason === 'not_enough_disk') {
            $params += DiskBudget::detailParams($detail);
        }

        return trans('modpacks-plus::strings.reasons.'.$reason, $params);
    }

    public static function error(PackInstall $install): ?string
    {
        if ($install->error === null) {
            return null;
        }

        return self::reason($install->error, (string) $install->progressValue('error_detail', ''));
    }

    /**
     * @param  bool  $showSetup  whether the viewer may read the startup settings (startup.read):
     *                           only then are the startup command, images and variable values shown
     */
    public static function summary(PackInstall $install, bool $showSetup = false): HtmlString
    {
        $summary = $install->summary ?? [];
        $lines = [];

        if (!empty($summary['backup_uuid'])) {
            $lines[] = trans('modpacks-plus::strings.summary.backup', ['uuid' => $summary['backup_uuid']]);
        } elseif (!empty($summary['backup_skipped'])) {
            $lines[] = trans('modpacks-plus::strings.summary.backup_skipped');
        }

        $update = is_array($summary['update'] ?? null) ? $summary['update'] : null;
        if ($update !== null) {
            array_push($lines, ...self::updateLines($update, (string) ($summary['moved_aside_path'] ?? '')));
        } elseif (!empty($summary['moved'])) {
            $lines[] = trans('modpacks-plus::strings.summary.moved', [
                'folders' => implode(', ', array_map('strval', (array) $summary['moved'])),
                'path' => (string) ($summary['moved_aside_path'] ?? ''),
            ]);
        }

        if (!empty($summary['fresh_world']) && is_array($summary['fresh_world'])) {
            $lines[] = trans('modpacks-plus::strings.summary.fresh_world', ['paths' => implode(', ', array_map('strval', $summary['fresh_world'])), 'path' => (string) ($summary['moved_aside_path'] ?? '')]);
        }

        if (is_string($summary['properties'] ?? null) && trans()->has('modpacks-plus::strings.summary.properties.'.$summary['properties'])) {
            $lines[] = trans('modpacks-plus::strings.summary.properties.'.$summary['properties'], ['path' => (string) ($summary['moved_aside_path'] ?? '')]);
        }

        if (isset($summary['files_total']) && $update === null) {
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

        if (is_array($summary['sources'] ?? null)) {
            $lines[] = trans('modpacks-plus::strings.summary.sources', [
                'modrinth' => (int) ($summary['sources']['modrinth'] ?? 0),
                'curseforge' => (int) ($summary['sources']['curseforge'] ?? 0),
                'manual' => (int) ($summary['sources']['manual'] ?? 0),
            ]);
        }
        if ((int) ($summary['md5_verified'] ?? 0) > 0) {
            $lines[] = trans('modpacks-plus::strings.summary.md5_verified', ['count' => (int) $summary['md5_verified']]);
        }
        if (!empty($summary['server_pack'])) {
            $lines[] = trans('modpacks-plus::strings.summary.server_pack');
        } elseif (is_array($summary['server_pack_fallback'] ?? null) && is_string($summary['server_pack_fallback']['reason'] ?? null)) {
            $lines[] = trans('modpacks-plus::strings.summary.server_pack_fallback', [
                'reason' => self::reason($summary['server_pack_fallback']['reason'], (string) ($summary['server_pack_fallback']['detail'] ?? '')),
            ]);
        }
        if (!empty($summary['client_only_by']) && is_array($summary['client_only_by'])) {
            $lines[] = trans('modpacks-plus::strings.summary.client_only_by', ['files' => implode(', ', array_map(
                fn ($entry) => is_array($entry) ? ((string) ($entry['path'] ?? '')).' ('.((string) ($entry['project'] ?? '')).')' : '',
                $summary['client_only_by'],
            ))]);
        }
        if (!empty($summary['client_only_folder_files'])) {
            $lines[] = trans('modpacks-plus::strings.summary.client_only_folder_files', ['paths' => implode(', ', array_map('strval', (array) $summary['client_only_folder_files']))]);
        }
        if (!empty($summary['client_only_pack_files'])) {
            $lines[] = trans('modpacks-plus::strings.summary.client_only_pack_files', ['paths' => implode(', ', array_map('strval', (array) $summary['client_only_pack_files']))]);
        }
        if (!empty($summary['skipped_pack_files'])) {
            $lines[] = trans('modpacks-plus::strings.summary.skipped_pack_files', ['paths' => implode(', ', array_map('strval', (array) $summary['skipped_pack_files']))]);
        }

        array_push($lines, ...self::metadataLines($summary['modrinth_metadata'] ?? null));

        if ($install->progressValue('restore_discarded')) {
            $lines[] = trans('modpacks-plus::strings.summary.restore_discarded', ['path' => (string) $install->preserved_path]);
        } elseif ($install->restored_at !== null) {
            $lines[] = trans('modpacks-plus::strings.summary.restored');
        } elseif ($install->isRestoring()) {
            $lines[] = trans('modpacks-plus::strings.summary.restoring');
        }

        $incomplete = $install->progressValue('restore_incomplete');
        if (is_array($incomplete) && is_array($incomplete['failed'] ?? null) && $incomplete['failed'] !== [] && $install->restored_at === null) {
            $lines[] = trans('modpacks-plus::strings.summary.restore_incomplete', [
                'restored' => (int) ($incomplete['restored'] ?? 0),
                'failed' => count($incomplete['failed']),
                'paths' => implode(', ', array_map('strval', $incomplete['failed'])),
                'path' => (string) ($install->preserved_path ?? $install->moved_aside_path),
            ]);
        }
        $unexpected = $install->progressValue('preserved_unexpected', []);
        if (is_array($unexpected) && $unexpected !== []) {
            $lines[] = trans('modpacks-plus::strings.summary.preserved_unexpected', ['paths' => implode(', ', array_map('strval', $unexpected)), 'path' => (string) $install->preserved_path]);
        }

        $switch = $summary['loader_switch'] ?? null;
        if (is_array($switch)) {
            $lines = array_merge($lines, self::switchLines($switch, $install, $showSetup));
        }

        return new HtmlString('<ul>'.implode('', array_map(fn (string $line) => '<li>'.e($line).'</li>', $lines)).'</ul>'.self::manualList($summary['manual_files'] ?? null));
    }

    /**
     * What an update did (plan 36): counts, the user's files it kept or moved, dropped files.
     *
     * @param  array<string, mixed>  $update
     * @return array<int, string>
     */
    private static function updateLines(array $update, string $aside): array
    {
        $list = fn (string $key): string => implode(', ', array_map('strval', is_array($update[$key] ?? null) ? $update[$key] : []));
        $lines = [trans('modpacks-plus::strings.summary.update', [
            'added' => (int) ($update['added'] ?? 0),
            'changed' => (int) ($update['changed'] ?? 0),
            'removed' => (int) ($update['removed'] ?? 0),
            'unchanged' => (int) ($update['unchanged'] ?? 0),
        ])];

        if (!empty($update['kept_modified'])) {
            $lines[] = trans('modpacks-plus::strings.summary.update_kept', ['paths' => $list('kept_modified')]);
        }
        if (!empty($update['overwritten'])) {
            $lines[] = trans('modpacks-plus::strings.summary.update_overwritten', ['paths' => $list('overwritten'), 'path' => $aside]);
        }
        if (!empty($update['removed_paths'])) {
            $lines[] = trans('modpacks-plus::strings.summary.update_removed', ['paths' => $list('removed_paths'), 'path' => $aside]);
        }
        if (!empty($update['not_ours'])) {
            $lines[] = trans('modpacks-plus::strings.summary.update_not_ours', ['paths' => $list('not_ours'), 'path' => $aside]);
        }

        return $lines;
    }

    /**
     * Files the user downloads themselves (never fetched by the panel): each with a link to its
     * page on the source's website. Entries are re-checked (PlanValidator::manualFile) and escaped.
     */
    public static function manualList(mixed $files): string
    {
        $items = [];
        foreach (is_array($files) ? $files : [] as $file) {
            $file = is_array($file) ? PlanValidator::manualFile($file) : null;
            if ($file === null) {
                continue;
            }

            $name = e($file['name']);
            $reason = e(trans('modpacks-plus::strings.summary.manual_reasons.'.$file['reason']));
            $items[] = '<li>'.($file['url'] !== null ? '<a href="'.e($file['url']).'" target="_blank" rel="noopener noreferrer" class="underline">'.$name.'</a>' : $name).' ('.$reason.')</li>';
        }

        if ($items === []) {
            return '';
        }

        return '<p>'.e(trans('modpacks-plus::strings.summary.manual_files', ['count' => count($items)])).'</p><ul style="list-style: disc; padding-left: 1.25rem">'.implode('', $items).'</ul>';
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
    private static function switchLines(array $switch, PackInstall $install, bool $showSetup): array
    {
        $lines = [];
        $previous = is_array($switch['previous'] ?? null) ? $switch['previous'] : null;

        if (!empty($switch['egg_changed'])) {
            $lines[] = $showSetup
                ? trans('modpacks-plus::strings.summary.switched', ['from' => (string) ($previous['egg_name'] ?? '?'), 'to' => (string) ($switch['egg_name'] ?? '?'), 'image' => (string) ($switch['image'] ?? '')])
                : trans('modpacks-plus::strings.summary.switched_hidden', ['from' => (string) ($previous['egg_name'] ?? '?'), 'to' => (string) ($switch['egg_name'] ?? '?')]);
        } elseif (is_string($switch['egg_name'] ?? null)) {
            $lines[] = trans('modpacks-plus::strings.summary.not_switched', ['egg' => (string) ($previous['egg_name'] ?? '?')]);
        }

        if ($previous !== null) {
            // With startup.read: values of visible variables, names of hidden ones (their values
            // aren't recorded). Without it: only how many there were.
            $variables = [];
            foreach (is_array($previous['variables'] ?? null) ? $previous['variables'] : [] as $name => $value) {
                $variables[] = $name.'='.(is_scalar($value) ? (string) $value : '');
            }
            foreach (is_array($previous['hidden_variables'] ?? null) ? $previous['hidden_variables'] : [] as $name) {
                $variables[] = (string) $name;
            }

            $lines[] = $showSetup
                ? trans('modpacks-plus::strings.summary.previous', [
                    'egg' => (string) ($previous['egg_name'] ?? '?'),
                    'image' => (string) ($previous['image'] ?? ''),
                    'startup' => (string) ($previous['startup'] ?? ''),
                    'variables' => implode(', ', $variables),
                ])
                : trans('modpacks-plus::strings.summary.previous_hidden', [
                    'egg' => (string) ($previous['egg_name'] ?? '?'),
                    'count' => count($variables),
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
