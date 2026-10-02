<?php
namespace verbb\base\twigextensions;

use verbb\base\web\twig\Extension as NewExtension;

use Craft;

/**
 * @deprecated Use {@see NewExtension} instead.
 */
class Extension extends NewExtension
{
    // Public Methods
    // =========================================================================

    public function getFunctions(): array
    {
        Craft::$app->getDeprecator()->log(
            self::class,
            '`' . self::class . '` has been deprecated. Use `' . NewExtension::class . '` instead.',
        );

        return parent::getFunctions();
    }
}
