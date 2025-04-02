<?php
namespace verbb\base;

use Craft;

use yii\log\Logger;

trait LogTrait
{
    // Static Methods
    // =========================================================================
 
    public static function log(string $message, array $params = []): void
    {
        Craft::$app->getDeprecator()->log(__METHOD__, 'The `log()` function is deprecated. Use `info()` instead.');

        self::_log(Logger::LEVEL_INFO, $message, $params);
    }

    public static function info(string $message, array $params = []): void
    {
        self::_log(Logger::LEVEL_INFO, $message, $params);
    }

    public static function warning(string $message, array $params = []): void
    {
        self::_log(Logger::LEVEL_WARNING, $message, $params);
    }

    public static function error(string $message, array $params = []): void
    {
        self::_log(Logger::LEVEL_ERROR, $message, $params);
    }


    // Private Methods
    // =========================================================================
 
    private static function _log(int|string $level, string $message, array $params = []): void
    {
        if ($params && self::getInstance()) {
            $message = Craft::t(self::getInstance()->handle, $message, $params);
        }

        // Determine the calling function automatically so we don't have to use `__METHOD__` everywhere.
        // Use `DEBUG_BACKTRACE_IGNORE_ARGS, 3` for performance and only get the first 3 traces.
        $category = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)[2]['function'] ?? 'application';
        $class = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)[2]['class'] ?? self::class;

        Craft::getLogger()->log($message, $level, implode('::', [$class, $category]));
    }
}