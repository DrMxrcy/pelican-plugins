<?php

namespace DrMxrcy\ModpacksPlus\Services;

use DrMxrcy\ModpacksPlus\Contracts\PackProvider;
use DrMxrcy\ModpacksPlus\Exceptions\PackUnavailableException;
use DrMxrcy\ModpacksPlus\Providers\CurseForge\CurseForgeClient;
use DrMxrcy\ModpacksPlus\Providers\CurseForge\CurseForgePackProvider;
use DrMxrcy\ModpacksPlus\Providers\Modrinth\ModrinthClient;
use DrMxrcy\ModpacksPlus\Providers\Modrinth\ModrinthPackProvider;
use DrMxrcy\ModpacksPlus\Support\Caps;

/** Looks pack providers up by id. */
class PackProviders
{
    /** Provider ids in the order the Modpacks page lists them. */
    public const IDS = [ModrinthPackProvider::ID, CurseForgePackProvider::ID];

    /** @throws PackUnavailableException */
    public function get(string $id): PackProvider
    {
        return match ($id) {
            ModrinthPackProvider::ID => $this->modrinth(),
            // Built even when CurseForge is not set up: its API calls then refuse before any request,
            // while a running install can still re-read its (CDN-hosted) archive.
            CurseForgePackProvider::ID => new CurseForgePackProvider(CurseForgeClient::fromConfig(), $this->modrinth(), new ModrinthMatcher(app(ModrinthClient::class)), Caps::fromConfig()),
            default => throw new PackUnavailableException('not_found', $id),
        };
    }

    private function modrinth(): ModrinthPackProvider
    {
        return new ModrinthPackProvider(app(ModrinthClient::class), Caps::fromConfig());
    }
}
