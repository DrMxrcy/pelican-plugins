<?php

namespace DrMxrcy\ModpacksPlus\Services;

use DrMxrcy\ModpacksPlus\Data\PlanFile;
use DrMxrcy\ModpacksPlus\Exceptions\PackUnavailableException;
use DrMxrcy\ModpacksPlus\Exceptions\UnsafePlanException;
use DrMxrcy\ModpacksPlus\Providers\CurseForge\CurseForgePackProvider;
use DrMxrcy\ModpacksPlus\Support\Caps;
use DrMxrcy\ModpacksPlus\Support\PlanValidator;

/**
 * Decides where each file of an FTB or ATLauncher pack comes from (plan 32), with the
 * rules of plans 30 and 31:
 *
 * - a file the pack says is a CurseForge file (project + file id) is first looked up on
 *   Modrinth by sha1 (ModrinthMatcher: same sha1, same size, cdn.modrinth.com, sha512);
 *   a match is downloaded from Modrinth and Modrinth's server_side drops client-only mods;
 * - a CurseForge file whose own CurseForge record (asked below) tags it `Client` and not
 *   `Server` is left out as client-only whatever Modrinth says (plan 48);
 * - a CurseForge-hosted file not on Modrinth is only fetched from the URL CurseForge's own
 *   API gives for that file id (CurseForgePackProvider::referencedFiles: built from id and
 *   name, never when allowModDistribution is false). Without a CurseForge key or the mirror
 *   it is listed as "needs CurseForge key" (`curseforge_key_required`);
 * - a file the source hosts itself is fetched from its own host only (else
 *   `host_not_allowed`), by the panel, and checked against the source's sha1;
 * - a file with no usable hash (no sha1 from the source, none from CurseForge) is never
 *   downloaded: it is listed (`no_usable_hash`). The one exception: a file the source serves
 *   from a container on ATLauncher's own CDN and vouches for with an md5 (`md5`, ATLauncher only;
 *   PlanValidator::isAtlauncherMd5Url) is fetched by the panel with no redirects and checked
 *   against that md5 (PlanFile::VERIFY_ATLAUNCHER_MD5);
 * - a file CurseForge returns nothing for is listed (`not_on_curseforge`), one without a
 *   usable size is listed (`no_usable_size`);
 * - a file only on CurseForge or the source's host and larger than max_panel_fetch_bytes is
 *   listed (`too_large`).
 *
 * When both the source and CurseForge give a sha1 (or a size) for a file and they differ,
 * the install stops (`hash_mismatch`): the sources disagree about the bytes.
 *
 * @phpstan-type Candidate array{path: string, display: string, url: ?string, size: int, sha1: ?string, md5?: ?string, side?: string, curseforge?: array{project: int, file: int}|null, cf_hosted?: bool, link?: ?string}
 * @phpstan-type ManualFile array{name: string, url: ?string, reason: string}
 */
class PackFileResolver
{
    public function __construct(
        private readonly ModrinthMatcher $matcher,
        private readonly CurseForgePackProvider $curseforge,
        private readonly Caps $caps,
    ) {}

    /**
     * @param  array<int, Candidate>  $candidates
     * @param  array<int, string>  $ownHosts  exact hosts the source serves its own files from
     * @return array{files: array<int, PlanFile>, manual: array<int, ManualFile>, client_only: array<int, array{path: string, project: string, source?: string}>}
     *
     * @throws UnsafePlanException
     * @throws PackUnavailableException
     */
    public function resolve(array $candidates, array $ownHosts): array
    {
        foreach ($candidates as $index => $candidate) {
            $candidates[$index] = $candidate + ['side' => 'required', 'curseforge' => null, 'cf_hosted' => false, 'link' => null, 'md5' => null];
            $candidates[$index]['sha1'] = self::sha1($candidate['sha1'], $candidate['path']);
        }

        // CurseForge is asked about files it hosts and files the source gives no sha1 for.
        $cfReady = $this->curseforge->client()->configured();
        $needCf = [];
        foreach ($candidates as $candidate) {
            if ($candidate['curseforge'] !== null && $candidate['side'] !== 'unsupported' && ($candidate['cf_hosted'] || $candidate['sha1'] === null)) {
                $needCf[$candidate['curseforge']['file']] = $candidate['curseforge'];
            }
        }
        $refs = $cfReady && $needCf !== [] ? $this->curseforge->referencedFiles(array_values($needCf)) : [];

        $clientOnly = [];
        foreach ($candidates as $index => $candidate) {
            $ref = $candidate['curseforge'] !== null ? ($refs[$candidate['curseforge']['file']] ?? null) : null;
            if ($ref === null) {
                continue;
            }

            $refSha1 = self::sha1($ref['sha1'], $candidate['path']);
            if (($candidate['sha1'] !== null && $refSha1 !== null && !hash_equals($candidate['sha1'], $refSha1))
                || ($candidate['size'] >= 0 && $ref['size'] >= 0 && $candidate['size'] !== $ref['size'])) {
                throw new UnsafePlanException('hash_mismatch', $candidate['path']);
            }

            $candidates[$index]['sha1'] ??= $refSha1;
            if ($candidate['size'] < 0) {
                $candidates[$index]['size'] = $ref['size'];
            }

            // Plan 48: CurseForge's own record tags the file Client and not Server.
            if ($ref['client_only']) {
                $candidates[$index]['side'] = 'unsupported';
                $clientOnly[] = ['path' => $candidate['path'], 'project' => $ref['project'], 'source' => CurseForgePackProvider::ID];
            }
        }

        // Modrinth-first, for CurseForge files only (plan 31).
        $matches = $this->matcher->match(array_values(array_filter(array_map(
            fn (array $candidate) => $candidate['curseforge'] !== null && $candidate['side'] !== 'unsupported' ? $candidate['sha1'] : null,
            $candidates,
        ))));

        $files = [];
        $manual = [];
        foreach ($candidates as $candidate) {
            $sha1 = $candidate['sha1'];
            $side = $candidate['side'];
            $manualEntry = fn (string $reason, ?string $link = null) => ['name' => $candidate['display'], 'url' => $link ?? $candidate['link'], 'reason' => $reason];

            if ($side === 'unsupported') {
                // Dropped by the validator before any URL or hash is looked at.
                $files[] = PlanFile::fromArray(['path' => $candidate['path'], 'urls' => [], 'size' => $candidate['size'], 'hashes' => [], 'side' => 'unsupported']);

                continue;
            }

            if ($candidate['size'] < 0) {
                $manual[] = $manualEntry('no_usable_size');

                continue;
            }

            $match = $candidate['curseforge'] !== null && $sha1 !== null ? ($matches[$sha1] ?? null) : null;
            if ($match !== null && $match['size'] === $candidate['size']) {
                if ($match['server_side'] === 'unsupported') {
                    $clientOnly[] = ['path' => $candidate['path'], 'project' => $match['project_slug'] ?? $match['project_id']];
                }
                $files[] = PlanFile::fromArray([
                    'path' => $candidate['path'],
                    'urls' => [$match['url']],
                    'size' => $candidate['size'],
                    'hashes' => ['sha512' => $match['sha512'], 'sha1' => (string) $sha1],
                    'side' => $match['server_side'] === 'unsupported' ? 'unsupported' : $side,
                ]);

                continue;
            }

            $ref = $candidate['curseforge'] !== null ? ($refs[$candidate['curseforge']['file']] ?? null) : null;
            // CurseForge is set up but returned nothing for the file the pack names.
            $missingRef = $candidate['curseforge'] !== null && $cfReady && ($candidate['cf_hosted'] || $sha1 === null) && $ref === null;

            if ($sha1 === null) {
                $md5File = $candidate['cf_hosted'] ? null : $this->md5File($candidate);
                if ($md5File !== null) {
                    if ($candidate['size'] > $this->caps->maxPanelFetchBytes) {
                        $manual[] = $manualEntry('too_large');
                    } else {
                        $files[] = $md5File;
                    }

                    continue;
                }

                $manual[] = $manualEntry(match (true) {
                    $candidate['curseforge'] !== null && !$cfReady => 'curseforge_key_required',
                    $missingRef => 'not_on_curseforge',
                    default => 'no_usable_hash',
                }, $ref['link'] ?? null);

                continue;
            }

            if ($candidate['cf_hosted']) {
                if (!$cfReady) {
                    $manual[] = $manualEntry('curseforge_key_required');

                    continue;
                }
                if ($missingRef) {
                    $manual[] = $manualEntry('not_on_curseforge');

                    continue;
                }
                $url = $ref['url'] ?? null;
                if ($url === null) {
                    $manual[] = $manualEntry('distribution_disabled', $ref['link'] ?? null);

                    continue;
                }
            } else {
                $url = $candidate['url'];
                if ($url === null || !self::onHosts($url, $ownHosts)) {
                    $manual[] = $manualEntry('host_not_allowed');

                    continue;
                }
            }

            if ($candidate['size'] > $this->caps->maxPanelFetchBytes) {
                $manual[] = $manualEntry('too_large', $ref['link'] ?? null);

                continue;
            }

            $files[] = PlanFile::fromArray([
                'path' => $candidate['path'],
                'urls' => [$url],
                'size' => $candidate['size'],
                'hashes' => ['sha1' => $sha1],
                'side' => $side,
            ]);
        }

        return ['files' => $files, 'manual' => $manual, 'client_only' => $clientOnly];
    }

    /**
     * The md5-checked plan file for a file served from a container on ATLauncher's own CDN
     * (PlanValidator::isAtlauncherMd5Url) that has no sha1 anywhere, or null when it isn't one
     * (other host or path, no md5). A malformed md5 stops the install.
     *
     * @param  array<string, mixed>  $candidate
     *
     * @throws UnsafePlanException
     */
    private function md5File(array $candidate): ?PlanFile
    {
        $url = $candidate['url'] ?? null;
        $md5 = $candidate['md5'] ?? null;
        if (!is_string($url) || !PlanValidator::isAtlauncherMd5Url($url) || $md5 === null || $md5 === '') {
            return null;
        }
        if (!is_string($md5) || !preg_match('/^[0-9a-fA-F]{32}$/', $md5)) {
            throw new UnsafePlanException('bad_hash', (string) $candidate['path']);
        }

        return PlanFile::fromArray([
            'path' => $candidate['path'],
            'urls' => [$url],
            'size' => $candidate['size'],
            'hashes' => ['md5' => strtolower($md5)],
            'side' => $candidate['side'],
            'verify' => PlanFile::VERIFY_ATLAUNCHER_MD5,
        ]);
    }

    /**
     * Whether $url is HTTPS on one of $hosts (exactly), with the validator's URL rules.
     *
     * @param  array<int, string>  $hosts
     */
    public static function onHosts(string $url, array $hosts): bool
    {
        return (new PlanValidator(new Caps(), ['world'], $hosts))->isAllowedUrl($url);
    }

    /**
     * A sha1 as lowercase hex; null when none is given. A malformed one stops the install
     * (a broken or hostile answer never falls back to "no hash").
     *
     * @throws UnsafePlanException
     */
    private static function sha1(mixed $sha1, string $path): ?string
    {
        if ($sha1 === null || $sha1 === '') {
            return null;
        }

        if (!is_string($sha1) || !preg_match('/^[0-9a-fA-F]{40}$/', $sha1)) {
            throw new UnsafePlanException('bad_hash', $path);
        }

        return strtolower($sha1);
    }
}
