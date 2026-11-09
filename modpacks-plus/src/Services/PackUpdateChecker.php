<?php

namespace DrMxrcy\ModpacksPlus\Services;

use DrMxrcy\ModpacksPlus\Exceptions\PackUnavailableException;
use DrMxrcy\ModpacksPlus\Models\PackInstall;
use DrMxrcy\ModpacksPlus\Notifications\PackUpdateAvailable;
use DrMxrcy\ModpacksPlus\Providers\CurseForge\CurseForgeClient;
use DrMxrcy\ModpacksPlus\Providers\CurseForge\CurseForgePackProvider;

/**
 * Finds newer versions of installed packs (plan 36). For the newest installed pack of each
 * server it asks the pack's source for the pack's version list (metadata only, one request
 * per pack per run, at most MAX_PACKS packs per run, through the providers' size-capped
 * clients) and marks an update when a newer version exists for the installed pack's loader
 * and Minecraft version (an update never turns into a loader switch). The server owner is
 * notified once per new version. Never downloads pack files; never contacts a node or a game
 * server; a source that can't be read is skipped (CurseForge without a key or the mirror is
 * never asked).
 */
class PackUpdateChecker
{
    public const FREQUENCIES = ['off', 'daily', 'weekly'];

    /** Distinct packs asked per run (API quota); `modpacks-plus.update_check_max_packs` may lower it. */
    public const MAX_PACKS = 200;

    public static function maxPacks(): int
    {
        $configured = config('modpacks-plus.update_check_max_packs');

        return is_numeric($configured) && (int) $configured > 0 ? min((int) $configured, self::MAX_PACKS) : self::MAX_PACKS;
    }

    public static function frequency(): string
    {
        $value = config('modpacks-plus.update_check');

        return is_string($value) && in_array($value, self::FREQUENCIES, true) ? $value : 'off';
    }

    public static function enabled(): bool
    {
        return self::frequency() !== 'off';
    }

    /** @return array{checked: int, available: int, notified: int, skipped: int} */
    public function run(): array
    {
        $result = ['checked' => 0, 'available' => 0, 'notified' => 0, 'skipped' => 0];

        $latest = PackInstall::query()
            ->whereIn('id', PackInstall::query()->selectRaw('max(id)')->where('status', PackInstall::STATUS_INSTALLED)->groupBy('server_id'))
            // Newest installs first: when the per-run cap is reached, the oldest are left out.
            ->orderByDesc('id')
            ->with('server.user')
            ->get();

        $byPack = $latest->groupBy(fn (PackInstall $install) => $install->provider.'|'.$install->pack_id);
        $asked = 0;
        $max = self::maxPacks();

        foreach ($byPack as $installs) {
            /** @var PackInstall $first */
            $first = $installs->first();
            if (!in_array($first->provider, PackProviders::IDS, true)
                || ($first->provider === CurseForgePackProvider::ID && !CurseForgeClient::fromConfig()->configured())
                || $asked >= $max) {
                $result['skipped'] += $installs->count();

                continue;
            }

            $provider = app(PackProviders::class)->get($first->provider);
            if (!$provider::isValidId($first->pack_id)) {
                $result['skipped'] += $installs->count();

                continue;
            }

            $asked++;
            try {
                $versions = $provider->versions($first->pack_id);
            } catch (PackUnavailableException $exception) {
                report($exception);
                $result['skipped'] += $installs->count();

                continue;
            }

            foreach ($installs as $install) {
                $result['checked']++;
                $newer = self::newerVersion($install, $versions);

                $install->forceFill([
                    'latest_version_id' => $newer['id'] ?? $install->version_id,
                    'latest_version_number' => mb_strimwidth((string) ($newer['version_number'] ?? $install->version_number ?? ''), 0, 250, '…'),
                    'update_available' => $newer !== null,
                    'update_checked_at' => now(),
                ]);

                if ($newer !== null) {
                    $result['available']++;
                    if ($install->notified_version !== $newer['id']) {
                        if (PackUpdateAvailable::send($install)) {
                            $result['notified']++;
                        }
                        $install->notified_version = $newer['id'];
                    }
                }

                $install->save();
            }
        }

        return $result;
    }

    /**
     * The newest version for the installed pack's loader and Minecraft version, when it is
     * newer than the installed one (listed before it, newest first); null otherwise.
     *
     * @param  array<int, array{id: string, version_number: string, game_versions: array<int, string>, loaders: array<int, string>}>  $versions
     * @return array{id: string, version_number: string}|null
     */
    public static function newerVersion(PackInstall $install, array $versions): ?array
    {
        $plan = $install->installPlan();
        if ($plan === null || $plan->loader === '' || $plan->minecraftVersion === '') {
            return null;
        }

        foreach ($versions as $version) {
            if ($version['id'] === $install->version_id) {
                return null;
            }
            if (in_array($plan->loader, array_map('strtolower', $version['loaders']), true) && in_array($plan->minecraftVersion, $version['game_versions'], true)) {
                return ['id' => $version['id'], 'version_number' => $version['version_number']];
            }
        }

        return null;
    }
}
