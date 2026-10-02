<?php
namespace verbb\base\web\assets\cp;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset as CraftCpAsset;

class CpAsset extends AssetBundle
{
    // Public Methods
    // =========================================================================

    public function init(): void
    {
        $this->sourcePath = '@verbb/base/web/assets/cp/dist';

        $this->depends = [
            CraftCpAsset::class,
        ];

        $this->css = [
            'verbb-ui.css',
        ];

        $this->js = [
            'verbb-ui.js',
        ];

        parent::init();
    }
}
