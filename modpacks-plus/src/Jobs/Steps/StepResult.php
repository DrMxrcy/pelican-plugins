<?php

namespace DrMxrcy\ModpacksPlus\Jobs\Steps;

/** What a step's perform() asks the chain to do next. */
final class StepResult
{
    private function __construct(
        public readonly string $kind,
        public readonly int $delay = 0,
    ) {}

    /** The step finished; go to the next one. */
    public static function done(): self
    {
        return new self('done');
    }

    /** The step had nothing to do (e.g. the backup was skipped by the admin setting). */
    public static function skipped(): self
    {
        return new self('skipped');
    }

    /** Run this step again after $seconds (waiting, or more work after a time budget). */
    public static function again(int $seconds = 0): self
    {
        return new self('again', max(0, $seconds));
    }

    /** The install already ended inside perform(). */
    public static function ended(): self
    {
        return new self('ended');
    }
}
