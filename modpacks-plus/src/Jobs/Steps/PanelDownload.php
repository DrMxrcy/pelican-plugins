<?php

namespace DrMxrcy\ModpacksPlus\Jobs\Steps;

use DrMxrcy\ModpacksPlus\Data\PlanFile;
use DrMxrcy\ModpacksPlus\Exceptions\PackUnavailableException;
use DrMxrcy\ModpacksPlus\Exceptions\StepFailedException;
use DrMxrcy\ModpacksPlus\Exceptions\UnsafePlanException;
use DrMxrcy\ModpacksPlus\Providers\Modrinth\ModrinthClient;
use DrMxrcy\ModpacksPlus\Support\Caps;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;

/**
 * The panel-side streamed download (plan 32 step 2), used for every file the node may not
 * pull: FTB's dist.modpacks.ch (which sends no Content-Length, so Wings can't fetch it), the
 * CurseForge CDN, ATLauncher's CDN and any other non-Modrinth host.
 *
 * - The body streams into a temp file on the panel (ModrinthClient::download: HTTPS on the
 *   provider's allowlist at every redirect hop, identity encoding, the plugin User-Agent),
 *   capped at min(max_panel_fetch_bytes, the declared size): by Content-Length when the
 *   host sends one, by the streamed byte count, and by a byte-limit filter on the temp file
 *   itself, so a host without Content-Length is cut off as soon as it goes over; one
 *   deadline covers every redirect hop (at most one);
 * - the exact size and the strongest known hash (sha512, else sha1; md5 only for an
 *   ATLauncher-CDN file with nothing stronger, PlanFile::VERIFY_ATLAUNCHER_MD5) are checked
 *   on the temp file in chunks before it is read, and once more on the bytes read;
 * - only then are the bytes returned for the daemon write, so they never exceed
 *   max_panel_fetch_bytes (the existing limit for what the panel holds and writes);
 * - the temp file is removed whatever happens: an aborted download leaves no partial file,
 *   and nothing at all reaches the server.
 */
final class PanelDownload
{
    /**
     * Seconds a whole download job may take (well under the queue's retry_after of 90 s).
     * Budget + fetch + write stay below it: see DownloadStep::panelFetchTimeout().
     */
    public const JOB_SECONDS = 55;

    /** Seconds kept free after a fetch for the daemon write, rename and bookkeeping. */
    public const WRITE_RESERVE_SECONDS = 20;

    /** A fetch is only started with at least this long to run; else the job hands over. */
    public const MIN_FETCH_SECONDS = 5;

    /**
     * Seconds the next panel fetch may take when the job has run for $elapsed seconds, or
     * null when there is not enough time left and a fresh job should start it.
     */
    public static function timeout(float $elapsed): ?int
    {
        $left = (int) floor(self::JOB_SECONDS - self::WRITE_RESERVE_SECONDS - $elapsed);
        $timeout = min(ModrinthClient::DOWNLOAD_TIMEOUT, $left);

        return $timeout >= self::MIN_FETCH_SECONDS ? $timeout : null;
    }

    /**
     * Downloads $file onto the panel at $temp and returns its verified bytes.
     *
     * @throws UnsafePlanException too large, off the allowlist, or the hash doesn't match
     * @throws StepFailedException the size doesn't match
     * @throws PackUnavailableException the host failed or timed out (worth another attempt)
     */
    public static function fetch(PlanFile $file, string $temp, PlanValidator $validator, Caps $caps, int $timeout): string
    {
        if ($file->size < 0 || $file->size > $caps->maxPanelFetchBytes) {
            throw new UnsafePlanException('panel_fetch_too_large', $file->path);
        }

        try {
            // An md5-checked ATLauncher file is only ever taken from ATLauncher's CDN itself: no redirects.
            $redirects = $file->verify === PlanFile::VERIFY_ATLAUNCHER_MD5 ? 0 : ModrinthClient::MAX_REDIRECTS;
            app(ModrinthClient::class)->download((string) $file->url, $temp, $file->size, $validator, $timeout, 'panel_fetch_too_large', $redirects);

            clearstatcache(true, $temp);
            if (!is_file($temp) || filesize($temp) !== $file->size) {
                throw new StepFailedException('download_incomplete', $file->path);
            }

            [$algorithm, $expected] = PlanValidator::fileHash($file);
            if (!hash_equals($expected, (string) hash_file($algorithm, $temp))) {
                throw new UnsafePlanException('hash_mismatch', $file->path);
            }

            $contents = file_get_contents($temp);
            if (!is_string($contents) || strlen($contents) !== $file->size) {
                throw new StepFailedException('download_incomplete', $file->path);
            }
            PlanValidator::assertContentMatches($file, $contents);

            return $contents;
        } finally {
            @unlink($temp);
        }
    }
}
