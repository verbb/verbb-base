<?php
namespace verbb\base;

use Craft;
use craft\log\FileTarget;
use craft\web\Application;

abstract class BaseHelper
{
    // Public Methods
    // =========================================================================

    public static function registerModule()
    {
        $moduleId = 'verbb-base';

        if (!Craft::$app->hasModule($moduleId)) {
            Craft::$app->setModule($moduleId, new Base($moduleId));

            Craft::$app->getModule($moduleId);
        }
    }

    public static function setFileLogging($pluginHandle)
    {
        // Prevent code from firing too early before Craft is bootstrapped
        Craft::$app->on(Application::EVENT_INIT, function() use ($pluginHandle) {
            $hasFileLogging = false;

            // Check to see if the app is using file logging.
            foreach (Craft::$app->getLog()->targets as $target) {
                if ($target instanceof FileTarget) {
                    $hasFileLogging = true;

                    break;
                }
            }

            // If file logging is disabled, don't setup a new, separate file target. Logging will still be done
            // it just don't be placed in a separate log file for convenience. Instead, it'll be categorised in the main log.
            if ($hasFileLogging) {
                Craft::getLogger()->dispatcher->targets[] = new FileTarget([
                    'logFile' => Craft::getAlias('@storage/logs/' . $pluginHandle . '.log'),
                    'categories' => [$pluginHandle],
                    'logVars' => [
                        '_GET',
                        '_POST',
                    ],
                ]);
            }
        });
    }
}
