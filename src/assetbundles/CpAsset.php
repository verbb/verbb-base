<?php
namespace verbb\base\assetbundles;

use verbb\base\web\assets\cp\CpAsset as NewCpAsset;

use Craft;
use craft\web\AssetBundle;

/**
 * @deprecated Use {@see NewCpAsset} instead.
 */
class CpAsset extends AssetBundle
{
    // Public Methods
    // =========================================================================

    public function init(): void
    {
        Craft::$app->getDeprecator()->log(
            self::class,
            '`' . self::class . '` has been deprecated. Use `' . NewCpAsset::class . '` instead.',
        );

        $this->depends = [
            NewCpAsset::class,
        ];

        parent::init();
    }
}
