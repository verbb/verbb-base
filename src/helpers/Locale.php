<?php
namespace verbb\base\helpers;

use Craft;

class Locale
{
    // Static Methods
    // =========================================================================

    public static function switchAppLanguage(string $toLanguage, ?string $formattingLocale = null, callable $callback): void
    {
        // Store original language, locale and formatting locale
        $originalLanguage = Craft::$app->language;
        $originalLocale = Craft::$app->getLocale();
        $originalFormattingLocale = Craft::$app->formattingLocale;

        // Switch to new language
        Craft::$app->language = $toLanguage;
        $locale = Craft::$app->getI18n()->getLocaleById($toLanguage);
        Craft::$app->set('locale', $locale);

        // Switch formatting locale if provided
        if ($formattingLocale !== null) {
            $locale = Craft::$app->getI18n()->getLocaleById($formattingLocale);
        }

        Craft::$app->set('formattingLocale', $locale);

        // Execute the provided callback
        try {
            $callback();
        } finally {
            // Revert back to original language, locale and formatting locale
            Craft::$app->language = $originalLanguage;
            Craft::$app->set('locale', $originalLocale);
            Craft::$app->set('formattingLocale', $originalFormattingLocale);
        }
    }

}
