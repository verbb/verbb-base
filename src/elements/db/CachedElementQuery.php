<?php
namespace verbb\base\elements\db;

use craft\elements\db\ElementQuery;
use craft\helpers\Json;

abstract class CachedElementQuery extends ElementQuery
{
    // Traits
    // =========================================================================

    use CachedElementQueryTrait;
}