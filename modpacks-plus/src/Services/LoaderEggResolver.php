<?php

namespace DrMxrcy\ModpacksPlus\Services;

use App\Models\Egg;
use DrMxrcy\ModpacksPlus\Exceptions\InstallRefusedException;
use DrMxrcy\ModpacksPlus\Support\EggVariableMap;
use DrMxrcy\ModpacksPlus\Support\ServerTarget;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Finds the egg a server moves to for a loader, among the eggs the admin imported.
 * Never creates, imports or edits an egg.
 *
 * - Candidates: eggs recognised as that loader's official pelican-eggs egg (uuid or
 *   update_url, EggVariableMap), or tagged `minecraft` + that loader tag (the Modrinth
 *   Plus convention). A tagged egg the table doesn't know is refused: its variables are
 *   unknown, so the versions can't be set safely.
 * - Tie-break: the admin's default egg for the loader (plugin settings), else the one
 *   egg whose update_url points at pelican-eggs/minecraft, else the only candidate.
 *   More than one left: refused, naming them, until the admin picks a default.
 */
class LoaderEggResolver
{
    /** @var Collection<int, Egg>|null */
    private ?Collection $eggs = null;

    /**
     * @throws InstallRefusedException egg_missing | egg_unknown | egg_ambiguous | egg_variables_missing
     */
    public function resolve(string $loader): Egg
    {
        $loader = strtolower($loader);
        if (!in_array($loader, EggVariableMap::loaders(), true)) {
            throw new InstallRefusedException('loader_unsupported', $loader);
        }

        $known = [];
        $unknown = [];
        foreach ($this->eggs() as $egg) {
            $official = EggVariableMap::loaderFor($egg->uuid, $egg->update_url);
            $tagged = ServerTarget::taggedLoader($egg);

            // Recognised as one loader but tagged as another: not trusted either way.
            if ($official !== null && $tagged !== null && $official !== $tagged) {
                continue;
            }

            if ($official === $loader) {
                $known[$egg->id] = $egg;
            } elseif ($official === null && $tagged === $loader) {
                $unknown[$egg->id] = $egg;
            }
        }

        $default = config('modpacks-plus.loader_eggs.'.$loader);
        if (is_numeric($default) && (int) $default > 0) {
            if (isset($known[(int) $default])) {
                return $this->checked($known[(int) $default], $loader);
            }
            if (isset($unknown[(int) $default])) {
                throw new InstallRefusedException('egg_unknown', $unknown[(int) $default]->name);
            }
            // A default that no longer exists (or isn't this loader's) is ignored.
        }

        if ($known === []) {
            if ($unknown !== []) {
                throw new InstallRefusedException('egg_unknown', implode(', ', array_map(fn (Egg $egg) => $egg->name, $unknown)));
            }

            throw new InstallRefusedException('egg_missing', self::loaderName($loader));
        }

        $preferred = array_filter($known, fn (Egg $egg) => EggVariableMap::isOfficialUpdateUrl($egg->update_url));
        $pool = $preferred !== [] ? $preferred : $known;

        if (count($pool) > 1) {
            throw new InstallRefusedException('egg_ambiguous', implode(', ', array_map(fn (Egg $egg) => $egg->name.' #'.$egg->id, $pool)));
        }

        return $this->checked(reset($pool), $loader);
    }

    /**
     * Eggs an admin may pick as the default for a loader (settings), id => name.
     *
     * @return array<int, string>
     */
    public function choices(string $loader): array
    {
        $choices = [];
        foreach ($this->eggs() as $egg) {
            if (EggVariableMap::loaderFor($egg->uuid, $egg->update_url) === $loader && in_array(ServerTarget::taggedLoader($egg), [null, $loader], true)) {
                $choices[$egg->id] = $egg->name.' #'.$egg->id;
            }
        }

        return $choices;
    }

    public static function loaderName(string $loader): string
    {
        return match ($loader) {
            'neoforge' => 'NeoForge',
            default => Str::headline($loader),
        };
    }

    /** @throws InstallRefusedException */
    private function checked(Egg $egg, string $loader): Egg
    {
        $variables = $egg->variables()->pluck('env_variable')->all();
        $missing = array_diff(EggVariableMap::requiredVariables($loader), $variables);
        if ($missing !== []) {
            throw new InstallRefusedException('egg_variables_missing', $egg->name.': '.implode(', ', $missing));
        }

        return $egg;
    }

    /** @return Collection<int, Egg> */
    private function eggs(): Collection
    {
        return $this->eggs ??= Egg::query()->orderBy('id')->get();
    }
}
