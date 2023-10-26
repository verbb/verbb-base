<?php
namespace verbb\base\helpers;

use verbb\base\Base;

use Craft;
use craft\base\Plugin as CraftPlugin;
use craft\log\MonologTarget;

use Monolog\Formatter\LineFormatter;
use Psr\Log\LogLevel;

class Plugin
{
    // Static Methods
    // =========================================================================

    public static function bootstrapPlugin(string $pluginHandle): void
    {
        // Determine the plugin class calling this automatically, so we don't have to pass it in specifically
        // This will help set the category for logging to `verbb\plugin\*`.
        $category = $pluginHandle;
        $pluginClass = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['class'] ?? null;

        if ($pluginClass) {
            $pluginClassParts = explode('\\', $pluginClass);
            array_pop($pluginClassParts);
            $pluginClassParts[] = '*';

            $category = implode('\\', $pluginClassParts);
        }
        
        self::registerModule();
        self::setFileLogging($pluginHandle, $category);
    }

    public static function registerModule(): void
    {
        $moduleId = 'verbb-base';

        if (!Craft::$app->hasModule($moduleId)) {
            Craft::$app->setModule($moduleId, new Base($moduleId));

            Craft::$app->getModule($moduleId);
        }
    }

    public static function setFileLogging(string $pluginHandle, string $category, array $targetOptions = []): void
    {
        // Check that dispatcher exists, to avoid error when testing, since this is a bootstrapped module.
        // https://github.com/verbb/verbb-base/pull/1/files
        if ($dispatcher = Craft::getLogger()->dispatcher) {
            $dispatcher->targets[$category] = new MonologTarget(array_replace_recursive([
                'name' => $pluginHandle,
                'categories' => [$category],
                'level' => LogLevel::INFO,
                'allowLineBreaks' => true,
                'logVars' => ['_GET', '_POST'],
                'formatter' => new LineFormatter(
                    format: "%datetime% [%level_name%] %message%\n",
                    dateFormat: 'Y-m-d H:i:s',
                    allowInlineLineBreaks: true,
                ),
            ], $targetOptions));
        }
    }

    public static function isPluginInstalledAndEnabled(string $pluginHandle): bool
    {
        $pluginsService = Craft::$app->getPlugins();

        // Ensure that we check if initialized, installed and enabled. 
        // The plugin might be installed but disabled, or installed and enabled, but missing plugin files.
        return $pluginsService->isPluginInstalled($pluginHandle) && $pluginsService->isPluginEnabled($pluginHandle) && $pluginsService->getPlugin($pluginHandle);
    }

}
