<?php

namespace DrMxrcy\PlayersPlus\Services;

use RuntimeException;

/**
 * The daemon could not stream the log up to the cursor within the read deadline, so this scan
 * read nothing new. ScanLogsJob backs off instead of retrying (and failing) every 5 minutes.
 */
final class LogReadTooSlow extends RuntimeException {}
