<?php

namespace DrMxrcy\ModpacksPlus\Exceptions;

use RuntimeException;

/** The install moved on (another job, cancel, watchdog) while this job ran; it stops quietly. */
class StaleJobException extends RuntimeException {}
