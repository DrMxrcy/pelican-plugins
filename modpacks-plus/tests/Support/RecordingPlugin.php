<?php

namespace DrMxrcy\ModpacksPlus\Tests\Support;

use DrMxrcy\ModpacksPlus\ModpacksPlusPlugin;

/** The plugin with .env writes recorded instead of written. */
class RecordingPlugin extends ModpacksPlusPlugin
{
    /** @var array<string, mixed> */
    public array $written = [];

    public function writeToEnvironment(array $values = []): void
    {
        $this->written = $values;
    }
}
