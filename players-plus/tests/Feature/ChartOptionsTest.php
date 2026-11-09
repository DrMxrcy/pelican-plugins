<?php

namespace DrMxrcy\PlayersPlus\Tests\Feature;

use DrMxrcy\PlayersPlus\Filament\Admin\Widgets\PlayersOverviewChartWidget;
use DrMxrcy\PlayersPlus\Filament\Server\Widgets\PlayerCountChartWidget;
use DrMxrcy\PlayersPlus\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;

/**
 * Filament's chart.js assigns options.animation.duration, so `animation: false` throws
 * ("Cannot create property 'duration' on boolean") and the chart never renders.
 */
class ChartOptionsTest extends TestCase
{
    /** @return array<string, array{class-string}> */
    public static function widgets(): array
    {
        return [
            'activity chart' => [PlayerCountChartWidget::class],
            'overview chart' => [PlayersOverviewChartWidget::class],
        ];
    }

    /** @param  class-string  $widget */
    #[DataProvider('widgets')]
    public function test_animation_is_an_object_filament_can_extend(string $widget): void
    {
        $reflection = new ReflectionClass($widget);
        $instance = $reflection->newInstanceWithoutConstructor();
        $options = $reflection->getMethod('getOptions')->invoke($instance);

        $this->assertIsArray($options);
        $this->assertArrayHasKey('animation', $options);
        $this->assertIsArray($options['animation'], 'animation must be an object, never false');
        $this->assertSame(0, $options['animation']['duration']);
    }
}
