<?php
namespace verbb\base\helpers;

use verbb\base\models\EnvironmentSettingsViolation;

use Craft;
use craft\helpers\App;

/**
 * Decides which connection settings a user who isn't an admin can save.
 *
 * Settings resolved with `App::parseEnv()` can read any server secret, such as the database password, so only admins
 * can point a setting at an environment variable. A reference an admin already saved can stay. When someone else
 * changes where a connection goes, credentials they can't see must be entered again, so a saved secret only ever
 * reaches a destination an admin chose.
 */
class EnvironmentSettings
{
    // Static Methods
    // =========================================================================

    /**
     * Returns why the posted settings can't be saved, or `null` when they can.
     *
     * `$posted` holds the settings as posted, before they're merged over the saved ones. `$stored` holds the saved
     * settings, or an empty array for a new record. `$credentials` lists the settings that hold credentials, and
     * `null` treats every saved setting that refers to a variable as one. Set `$hiddenCredentials` when saved
     * credentials are masked in the form, so carrying one over hides its value. `$destinations` lists the settings
     * that decide where credentials are sent, and `null` matches setting names ending in URL, URI, domain, host,
     * endpoint or server.
     */
    public static function violation(array $posted, array $stored, ?array $credentials = null, bool $hiddenCredentials = false, bool $providerChanged = false, ?array $destinations = null): ?EnvironmentSettingsViolation
    {
        if (self::isTrusted()) {
            return null;
        }

        foreach (self::_references($posted) as [$path, $value]) {
            if (self::_valueAt($stored, $path) !== $value) {
                return new EnvironmentSettingsViolation(EnvironmentSettingsViolation::REFERENCE, $path);
            }
        }

        if (!$stored || (!$providerChanged && !self::_destinationChanged($posted, $stored, $destinations))) {
            return null;
        }

        $credentials ??= array_keys(array_filter($stored, fn($value) => self::containsReference($value)));

        foreach ($credentials as $attribute) {
            $saved = $stored[$attribute] ?? null;

            if ($saved === null || $saved === '' || $saved === []) {
                continue;
            }

            $carried = !array_key_exists($attribute, $posted) || $posted[$attribute] === $saved;

            if ($carried && ($hiddenCredentials || self::containsReference($saved))) {
                return new EnvironmentSettingsViolation(EnvironmentSettingsViolation::CREDENTIALS, [$attribute]);
            }
        }

        return null;
    }

    /**
     * Whether the current user can use environment variables in settings. Console requests and admins can.
     */
    public static function isTrusted(): bool
    {
        return Craft::$app->getRequest()->getIsConsoleRequest() || Craft::$app->getUser()->getIsAdmin();
    }

    /**
     * Whether a value, or any value nested in it, refers to an environment variable.
     */
    public static function containsReference(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::containsReference($item)) {
                    return true;
                }
            }

            return false;
        }

        if (!is_string($value) || !str_contains($value, '$')) {
            return false;
        }

        // The `${VAR}`, `$VAR` and `/$VAR/` forms that `App::parseEnv()` resolves. Earlier Craft 5 releases resolved
        // a few more shapes, so a value the installed version would change counts as well.
        return preg_match('/\$\{\w+}/', $value) === 1
            || preg_match('/(?<=^|\/)\$\w+(?=$|\/)/', $value) === 1
            || (preg_match('/\$\w/', $value) === 1 && App::parseEnv($value) !== $value);
    }

    private static function _references(array $settings, array $path = []): array
    {
        $references = [];

        foreach ($settings as $key => $value) {
            if (is_array($value)) {
                array_push($references, ...self::_references($value, [...$path, $key]));
            } elseif (self::containsReference($value)) {
                $references[] = [[...$path, $key], $value];
            }
        }

        return $references;
    }

    private static function _destinationChanged(array $posted, array $stored, ?array $destinations): bool
    {
        foreach ($posted as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $isDestination = $destinations === null ? preg_match(self::DESTINATION_SETTING, $key) === 1 : in_array($key, $destinations, true);

            if ($isDestination && self::_scalar($stored[$key] ?? null) !== self::_scalar($value)) {
                return true;
            }
        }

        return false;
    }

    private static function _scalar(mixed $value): string
    {
        return is_scalar($value) ? (string)$value : '';
    }

    private static function _valueAt(array $settings, array $path): mixed
    {
        foreach ($path as $key) {
            if (!is_array($settings) || !array_key_exists($key, $settings)) {
                return null;
            }

            $settings = $settings[$key];
        }

        return $settings;
    }


    // Constants
    // =========================================================================

    // Settings that decide which server a connection sends its credentials to, such as `apiUrl` or `apiDomain`.
    private const DESTINATION_SETTING = '/(url|uri|domain|host|endpoint|server)$/i';
}
