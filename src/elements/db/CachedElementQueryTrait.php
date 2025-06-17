<?php
namespace verbb\base\elements\db;

use craft\helpers\Json;

trait CachedElementQueryTrait
{
    // Properties
    // =========================================================================

    private static array $_cache = [];


    // Public Methods
    // =========================================================================

    public function one($db = null): mixed
    {
        $key = $this->_getCacheKey('one');

        if (isset(self::$_cache[$key])) {
            return self::$_cache[$key];
        }

        return self::$_cache[$key] = parent::one($db);
    }

    public function all($db = null): array
    {
        $key = $this->_getCacheKey('all');

        if (isset(self::$_cache[$key])) {
            return self::$_cache[$key];
        }

        return self::$_cache[$key] = parent::all($db);
    }

    public function count($q = '*', $db = null): int
    {
        $key = $this->_getCacheKey("count:$q");

        if (isset(self::$_cache[$key])) {
            return self::$_cache[$key];
        }

        return self::$_cache[$key] = parent::count($q, $db);
    }

    public function exists($db = null): bool
    {
        $key = $this->_getCacheKey('exists');

        if (isset(self::$_cache[$key])) {
            return self::$_cache[$key];
        }

        return self::$_cache[$key] = parent::exists($db);
    }


    // Private Methods
    // =========================================================================
    
    private function _getCacheKey(string $method): string
    {
        $params = $this->getCriteria();

        ksort($params);

        return md5($this->elementType . '|' . $method . '|' . Json::encode($params));
    }
}