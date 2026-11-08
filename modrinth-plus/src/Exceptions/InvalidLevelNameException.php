<?php

namespace DrMxrcy\ModrinthPlus\Exceptions;

/**
 * server.properties was read, but its `level-name` is not a folder inside the server root
 * (absolute path, `.`/`..` segments, backslash, drive letter, control characters, surrounding
 * whitespace). The world folder is unknown, exactly as when the file can't be read: datapack
 * writes refuse instead of falling back to "world".
 */
class InvalidLevelNameException extends ServerPropertiesUnreadableException {}
