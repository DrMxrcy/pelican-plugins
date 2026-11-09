<?php

namespace DrMxrcy\ModpacksPlus\Services;

use DrMxrcy\ModpacksPlus\Contracts\PackProvider;
use DrMxrcy\ModpacksPlus\Exceptions\PackUnavailableException;
use DrMxrcy\ModpacksPlus\Providers\Modrinth\ModrinthClient;
use DrMxrcy\ModpacksPlus\Providers\Modrinth\ModrinthPackProvider;
use DrMxrcy\ModpacksPlus\Support\Caps;

/** Looks pack providers up by id. */
class PackProviders
{
    /** @throws PackUnavailableException */
    public function get(string $id): PackProvider
    {
        return match ($id) {
            ModrinthPackProvider::ID => new ModrinthPackProvider(app(ModrinthClient::class), Caps::fromConfig()),
            default => throw new PackUnavailableException('not_found', $id),
        };
    }
}
