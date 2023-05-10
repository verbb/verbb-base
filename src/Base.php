<?php
namespace verbb\base;

use verbb\base\base\Module;
use verbb\base\twigextensions\Extension;

use Craft;

class Base extends Module
{
    // Public Methods
    // =========================================================================

    public function init(): void
    {
        parent::init();

        $this->_registerTwigExtensions();
    }

    // Public Methods
    // =========================================================================

    private function _registerTwigExtensions(): void
    {
        Craft::$app->getView()->registerTwigExtension(new Extension);
    }
}