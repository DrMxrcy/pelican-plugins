<?php

namespace DrMxrcy\ModpacksPlus\Services;

use DrMxrcy\ModpacksPlus\Contracts\PackProvider;
use DrMxrcy\ModpacksPlus\Exceptions\PackUnavailableException;
use DrMxrcy\ModpacksPlus\Providers\AtLauncher\AtLauncherPackProvider;
use DrMxrcy\ModpacksPlus\Providers\CurseForge\CurseForgeClient;
use DrMxrcy\ModpacksPlus\Providers\CurseForge\CurseForgePackProvider;
use DrMxrcy\ModpacksPlus\Providers\Ftb\FtbPackProvider;
use DrMxrcy\ModpacksPlus\Providers\Modrinth\ModrinthClient;
use DrMxrcy\ModpacksPlus\Providers\Modrinth\ModrinthPackProvider;
use DrMxrcy\ModpacksPlus\Support\Caps;

/** Looks pack providers up by id. */
class PackProviders
{
    /** Provider ids in the order the Modpacks page lists them. */
    public const IDS = [ModrinthPackProvider::ID, CurseForgePackProvider::ID, FtbPackProvider::ID, AtLauncherPackProvider::ID];

    /** @throws PackUnavailableException */
    public function get(string $id): PackProvider
    {
        return match ($id) {
            ModrinthPackProvider::ID => $this->modrinth(),
            // Built even when CurseForge is not set up: its API calls then refuse before any request,
            // while a running install can still re-read its (CDN-hosted) archive.
            CurseForgePackProvider::ID => $this->curseforge(),
            // Plan 32: CurseForge files inside FTB packs go through the CurseForge provider's rules.
            FtbPackProvider::ID => new FtbPackProvider(FtbPackProvider::apiClient(), $this->resolver(), $this->modrinth(), Caps::fromConfig()),
            AtLauncherPackProvider::ID => new AtLauncherPackProvider(AtLauncherPackProvider::apiClient(), AtLauncherPackProvider::cdnClient(), $this->resolver(), $this->modrinth(), Caps::fromConfig()),
            default => throw new PackUnavailableException('not_found', $id),
        };
    }

    private function curseforge(): CurseForgePackProvider
    {
        return new CurseForgePackProvider(CurseForgeClient::fromConfig(), $this->modrinth(), $this->matcher(), Caps::fromConfig());
    }

    private function matcher(): ModrinthMatcher
    {
        return new ModrinthMatcher(app(ModrinthClient::class));
    }

    private function resolver(): PackFileResolver
    {
        return new PackFileResolver($this->matcher(), $this->curseforge(), Caps::fromConfig());
    }

    private function modrinth(): ModrinthPackProvider
    {
        return new ModrinthPackProvider(app(ModrinthClient::class), Caps::fromConfig());
    }
}
