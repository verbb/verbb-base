<?php
namespace verbb\base\assetbundles;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset as CraftCpAsset;

class CpAsset extends AssetBundle
{
    // Public Methods
    // =========================================================================

    public function init(): void
    {
        $this->sourcePath = '@verbb/base/resources/dist';

        $this->depends = [
            CraftCpAsset::class
        ];

        $this->css = [
            'css/verbb-ui.css',
        ];

        $this->js = [
            'js/verbb-ui.js'
        ];

        parent::init();
    }
}