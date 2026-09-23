<?php
namespace verbb\base\twigextensions;

use craft\helpers\ArrayHelper;

use InvalidArgumentException;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class Extension extends AbstractExtension
{
    // Public Methods
    // =========================================================================

    public function getName(): string
    {
        return 'Verbb Twig Extensions';
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('vuiGetValue', [$this, 'getValue']),
            new TwigFunction('vuiUnsupportedFieldType', [$this, 'unsupportedFieldType']),
        ];
    }

    public function unsupportedFieldType(string $type): void
    {
        throw new InvalidArgumentException(sprintf('Unsupported proxyField type "%s".', $type));
    }

    public function getValue($array, $key, $default = null)
    {
        if (is_array($array)) {
            return ArrayHelper::getValue($array, $key, $default);
        }

        return null;
    }
}
