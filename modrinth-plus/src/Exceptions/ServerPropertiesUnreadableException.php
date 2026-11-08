<?php

namespace DrMxrcy\ModrinthPlus\Exceptions;

use Exception;

/**
 * server.properties exists but could not be read (daemon error, timeout), so the world folder
 * (`level-name`) is unknown. Datapack installs, updates and uninstalls refuse instead of guessing.
 */
class ServerPropertiesUnreadableException extends Exception {}
