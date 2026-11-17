<?php
namespace verbb\base\helpers;

use Craft;
use craft\i18n\PhpMessageSource;
use craft\web\View;

/**
 * Plugin Kit's built-in strings, such as the data table's “Clear search”, translate through one `plugin-kit`
 * category that every Verbb plugin shares. A site translates them once in `translations/<language>/plugin-kit.php`
 * instead of once per plugin.
 */
class PluginKit
{
    // Static Methods
    // =========================================================================

    /**
     * Registers the `plugin-kit` translation category. A category the site has already configured is left alone.
     */
    public static function registerTranslationSource(): void
    {
        $i18n = Craft::$app->getI18n();

        if (isset($i18n->translations[self::TRANSLATION_CATEGORY])) {
            return;
        }

        $i18n->translations[self::TRANSLATION_CATEGORY] = [
            'class' => PhpMessageSource::class,
            // Plugin Kit's interface strings are written in US English, matching Craft.
            'sourceLanguage' => 'en-US',
            'basePath' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'translations',
            'forceTranslation' => true,
            'allowOverrides' => true,
        ];
    }

    /**
     * Makes Plugin Kit's strings available to `Craft.t()` on the current page. Call this when registering a control
     * panel script that renders Plugin Kit components.
     *
     * Craft only outputs strings whose translation differs from the English source, so an English control panel gets
     * no extra script and Plugin Kit shows its own English defaults. Calling this more than once on a page is harmless,
     * because Yii keys registered scripts by their content.
     */
    public static function registerTranslations(?View $view = null): void
    {
        self::registerTranslationSource();

        ($view ?? Craft::$app->getView())->registerTranslations(self::TRANSLATION_CATEGORY, self::translationMessages());
    }

    /**
     * Returns Plugin Kit's English source strings, which are also their translation keys.
     *
     * @return string[]
     */
    public static function translationMessages(): array
    {
        // The English file is generated from Plugin Kit's published list, so its keys are the complete set.
        return array_keys(require dirname(__DIR__) . '/translations/en/' . self::TRANSLATION_CATEGORY . '.php');
    }


    // Constants
    // =========================================================================

    public const TRANSLATION_CATEGORY = 'plugin-kit';
}
