<?php
namespace verbb\base\twigextensions;

use craft\helpers\ArrayHelper;

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
            new TwigFunction('displayName', [$this, 'displayName']),
        ];
    }

    public function getValue(array $array, string $key, mixed $default = null): mixed
    {
        if (is_array($array)) {
            return ArrayHelper::getValue($array, $key, $default);
        }

        return null;
    }

    public function displayName(object|string $value): ?string
    {
        if ((is_string($value) && class_exists($value)) || is_object($value)) {
            if (method_exists($value, 'displayName')) {
                return $value::displayName();
            }

            if (is_object($value)) {
                $value = $value::class;
            }

            $classNameParts = explode('\\', $value);

            return array_pop($classNameParts);
        }

        return '';
    }
}
