<?php
namespace verbb\base;

use verbb\base\base\Module;
use verbb\base\helpers\PluginKit;
use verbb\base\web\twig\Extension;

use Craft;

class Base extends Module
{
    // Public Methods
    // =========================================================================

    public function init(): void
    {
        parent::init();

        $this->_registerTwigExtensions();

        // Plugin Kit's shared strings, so every Verbb plugin on a page uses the same translations.
        PluginKit::registerTranslationSource();
    }

    // Public Methods
    // =========================================================================

    private function _registerTwigExtensions(): void
    {
        Craft::$app->getView()->registerTwigExtension(new Extension());
    }
}
