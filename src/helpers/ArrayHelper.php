<?php
namespace verbb\base\helpers;

use craft\helpers\ArrayHelper as CraftArrayHelper;

use Closure;

class ArrayHelper extends CraftArrayHelper
{
    // Static Methods
    // =========================================================================

    // Deliberate lack of typing to not conflict with `yii\helpers\BaseArrayHelper::flatten`
    public static function flatten($data, $separator = '.'): array
    {
        $result = [];
        $stack = [];
        $path = '';

        reset($data);

        while (!empty($data)) {
            $key = key($data);
            $element = $data[$key];
            unset($data[$key]);

            if (is_array($element) && !empty($element)) {
                if (!empty($data)) {
                    $stack[] = [$data, $path];
                }
                
                $data = $element;
                reset($data);
                $path .= $key . $separator;
            } else {
                $result[$path . $key] = $element;
            }

            if (empty($data) && !empty($stack)) {
                [$data, $path] = array_pop($stack);
                reset($data);
            }
        }

        return $result;
    }

    public static function expand(array $data, string $separator = '.'): array
    {
        $hash = [];

        foreach ($data as $path => $value) {
            $keys = explode($separator, (string)$path);

            if (count($keys) === 1) {
                $hash[$path] = $value;
                continue;
            }

            $valueKey = end($keys);
            $keys = array_slice($keys, 0, -1);

            $keyHash = &$hash;

            foreach ($keys as $key) {
                if (!array_key_exists($key, $keyHash)) {
                    $keyHash[$key] = [];
                }

                $keyHash = &$keyHash[$key];
            }

            if (!is_array($keyHash)) {
                $keyHash = [];
            }

            $keyHash[$valueKey] = $value;
        }

        return $hash;
    }

    public static function recursiveFilter(array $array, ?Closure $filterCallback = null): array
    {
        foreach ($array as $key => $item) {
            if (is_array($item)) {
                $array[$key] = self::recursiveFilter($item, $filterCallback);
            }
        }

        // Allow the callback to define how to handle filtering.
        if ($filterCallback) {
            return array_filter($array, $filterCallback);
        }

        return array_filter($array);
    }

    public static function filterNull(array $array): array
    {
        return self::recursiveFilter($array, function($value): bool {
            return $value !== null;
        });
    }

    public static function filterEmpty(array $array): array
    {
        return self::recursiveFilter($array, function($value): bool {
            return $value !== '' && $value !== null;
        });
    }

    public static function filterFalse(array $array): array
    {
        return self::recursiveFilter($array, function($value): bool {
            return $value !== false;
        });
    }

    public static function filterEmptyFalse(array $array): array
    {
        return self::recursiveFilter($array, function($value): bool {
            return $value !== '' && $value !== null && $value !== false;
        });
    }

    public static function filterNullFalse(array $array): array
    {
        return self::recursiveFilter($array, function($value): bool {
            return $value !== null && $value !== false;
        });
    }

    public static function recursiveImplode(array $array, string $glue = ',', bool $include_keys = false, bool $trim_all = false): string
    {
        $glued_string = '';

        // Recursively iterates array and adds key/value to glued string
        array_walk_recursive($array, function($value, $key) use ($glue, $include_keys, &$glued_string) {
            $include_keys && $glued_string .= $key . $glue;
            $glued_string .= $value . $glue;
        });

        // Removes last $glue from string
        $glue !== '' && $glued_string = substr($glued_string, 0, -strlen($glue));

        // Trim ALL whitespace
        $trim_all && $glued_string = preg_replace("/(\s)/ixsm", '', $glued_string);

        return (string)$glued_string;
    }

}
