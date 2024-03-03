<?php
namespace verbb\base\helpers;

use Craft;
use craft\base\Plugin;
use craft\helpers\UrlHelper as CraftUrlHelper;

use Closure;

class UrlHelper extends CraftUrlHelper
{
    // Static Methods
    // =========================================================================

    public static function getRedirectUri(Plugin $plugin, string $path = '', array|string|null $params = null, ?string $scheme = null): string
    {
        $settings = $plugin->getSettings();

        // Is there a plugin setting for users to define their own path?
        if (property_exists($settings, 'redirectUri') && $settings->redirectUri) {
            return $settings->redirectUri;
        }

        // For headless, ensure that we point the request back to Craft through a regular action
        if (Craft::$app->getConfig()->getGeneral()->headlessMode) {
            return static::actionUrl($path, $params, $scheme);
        }

        // By default, we use site routes to prevent query strings from `usePathInfo` being enabled ending up in the URL.
        // A lot of providers determine query strings in a redirectUri is invalid. It also makes for a nicer URL.
        // `usePathInfo = false` - https://formie.test/index.php?p=admin/actions/formie/integrations/callback
        // `usePathInfo = true` - https://formie.test/index.php/admin/actions/formie/integrations/callback
        // siteUrl = https://formie.test/formie/integrations/callback
        return static::siteUrl($path, $params, $scheme);
    }

}
