<?php

namespace DrMxrcy\ModrinthPlus\Tests\Unit;

use DrMxrcy\ModrinthPlus\Support\Sides;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SidesTest extends TestCase
{
    /** @return array<string, array{array<string, mixed>, bool}> */
    public static function playersNeedItCases(): array
    {
        return [
            'xaeros-minimap: server optional, client required' => [['server_side' => 'optional', 'client_side' => 'required'], true],
            'architectury-api: both required' => [['server_side' => 'required', 'client_side' => 'required'], true],
            'server unknown, client required' => [['server_side' => 'unknown', 'client_side' => 'required'], true],
            'lithium: both optional' => [['server_side' => 'optional', 'client_side' => 'optional'], false],
            'server required, client unsupported' => [['server_side' => 'required', 'client_side' => 'unsupported'], false],
            'server required, client unknown' => [['server_side' => 'required', 'client_side' => 'unknown'], false],
            'sodium: client-only' => [['server_side' => 'unsupported', 'client_side' => 'required'], false],
            'client required, server side missing' => [['client_side' => 'required'], true],
            'unavailable project: no side keys' => [['project_id' => 'gone', 'unavailable' => true], false],
            'empty project' => [[], false],
            'non-string values' => [['server_side' => null, 'client_side' => ['required']], false],
        ];
    }

    /** @param  array<string, mixed>  $project */
    #[DataProvider('playersNeedItCases')]
    public function test_players_need_it(array $project, bool $expected): void
    {
        $this->assertSame($expected, Sides::playersNeedIt($project));
    }

    /** @return array<string, array{array<string, mixed>, bool}> */
    public static function isClientOnlyCases(): array
    {
        return [
            'sodium: server unsupported' => [['server_side' => 'unsupported', 'client_side' => 'required'], true],
            'server unsupported, client optional' => [['server_side' => 'unsupported', 'client_side' => 'optional'], true],
            'server required' => [['server_side' => 'required', 'client_side' => 'required'], false],
            'lithium: server optional' => [['server_side' => 'optional', 'client_side' => 'optional'], false],
            'server unknown' => [['server_side' => 'unknown', 'client_side' => 'required'], false],
            'unavailable project: no side keys' => [['project_id' => 'gone', 'unavailable' => true], false],
            'empty project' => [[], false],
            'non-string value' => [['server_side' => null], false],
        ];
    }

    /** @param  array<string, mixed>  $project */
    #[DataProvider('isClientOnlyCases')]
    public function test_is_client_only(array $project, bool $expected): void
    {
        $this->assertSame($expected, Sides::isClientOnly($project));
    }
}
