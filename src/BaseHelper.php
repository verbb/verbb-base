<?php
namespace verbb\base;

use Craft;

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
}
