<?php
namespace verbb\base;

use Craft;
use craft\log\MonologTarget;

use Monolog\Formatter\LineFormatter;
use Psr\Log\LogLevel;

abstract class BaseHelper
{
    // Public Methods
    // =========================================================================

    public static function registerModule(): void
    {
        $moduleId = 'verbb-base';

        if (!Craft::$app->hasModule($moduleId)) {
            Craft::$app->setModule($moduleId, new Base($moduleId));

            Craft::$app->getModule($moduleId);
        }
    }

    public static function setFileLogging($pluginHandle): void
    {
        // Check that dispatcher exists, to avoid error when testing, since this is a bootstrapped module.
        // https://github.com/verbb/verbb-base/pull/1/files
        if ($dispatcher = Craft::getLogger()->dispatcher) {
            $dispatcher->targets[] = new MonologTarget([
                'name' => $pluginHandle,
                'categories' => [$pluginHandle],
                'level' => LogLevel::INFO,
                'allowLineBreaks' => true,
                'maxFiles' => 10,
                'logVars' => ['_GET', '_POST'],
                'formatter' => new LineFormatter(
                    format: "%datetime% [%level_name%] %message%\n",
                    dateFormat: 'Y-m-d H:i:s',
                    allowInlineLineBreaks: true,
                ),
            ]);
        }
    }
}
