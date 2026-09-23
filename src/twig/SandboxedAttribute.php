<?php
namespace verbb\base\twig;

use craft\elements\db\ElementQueryInterface;

use yii\base\BaseObject;
use yii\base\UnknownMethodException;

use Stringable;

use Twig\Environment;
use Twig\Error\RuntimeError;
use Twig\Extension\CoreExtension;
use Twig\Extension\SandboxExtension;
use Twig\Sandbox\SecurityError;
use Twig\Sandbox\SecurityNotAllowedPropertyError;
use Twig\Source;
use Twig\Template;

class SandboxedAttribute
{
    // Static Methods
    // =========================================================================

    public static function getAttribute(Environment $env, Source $source, mixed $object, mixed $item, array $arguments = [], string $type = Template::ANY_CALL, bool $isDefinedTest = false, bool $ignoreStrictCheck = false, int $lineno = -1, bool $strictProperties = true): mixed
    {
        // Check the same attribute name Craft will use, including dynamic Stringable names.
        if ($item instanceof Stringable) {
            $item = (string)$env->getExtension(SandboxExtension::class)->ensureToStringAllowed($item, $lineno, $source);
        }

        // Yii's count argument is a raw SQL expression. A method allowlist cannot inspect arguments.
        if ($object instanceof ElementQueryInterface && is_string($item) && strtolower($item) === 'count' && $arguments !== []) {
            throw new SecurityError('Element query count() does not accept arguments in sandboxed templates.', $lineno, $source);
        }

        // Like Craft, read Yii properties directly, including nulls. Check permission before either read or defined test.
        // Older Craft helpers do not forward sandbox checks, so keep this boundary here.
        if ($strictProperties && $type !== Template::METHOD_CALL && $object instanceof BaseObject && (is_string($item) || is_int($item)) && $object->canGetProperty($item)) {
            try {
                $env->getExtension(SandboxExtension::class)->checkPropertyAllowed($object, $item, $lineno, $source);
            } catch (SecurityNotAllowedPropertyError $e) {
                if ($isDefinedTest) {
                    return false;
                }

                throw $e;
            }

            return $isDefinedTest ? true : $object->$item;
        }

        try {
            // Follow the installed Twig API without imposing a separate version requirement.
            if (method_exists(CoreExtension::class, 'getAttribute')) {
                return CoreExtension::getAttribute($env, $source, $object, $item, $arguments, $type, $isDefinedTest, $ignoreStrictCheck, true, $lineno);
            }

            return \twig_get_attribute($env, $source, $object, $item, $arguments, $type, $isDefinedTest, $ignoreStrictCheck, true, $lineno);
        } catch (UnknownMethodException $e) {
            if ($ignoreStrictCheck || !$env->isStrictVariables()) {
                return null;
            }

            throw new RuntimeError($e->getMessage(), $lineno, $source, $e);
        }
    }
}
