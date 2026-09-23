<?php
namespace verbb\base\twig;

use Closure;

use Twig\Sandbox\SecurityNotAllowedMethodError;
use Twig\Sandbox\SecurityNotAllowedPropertyError;
use Twig\Sandbox\SecurityPolicyInterface;

/**
 * Explicit permissions for the new renderers, independent of legacy Craft class allowances.
 */
class FormattingSecurityPolicy implements SecurityPolicyInterface
{
    // Properties
    // =========================================================================

    private SecurityPolicy $_syntaxPolicy;
    private array $_allowedMethods = [];
    private array $_allowedProperties = [];


    // Public Methods
    // =========================================================================

    public function __construct(array $tags, array $filters, array $methods, array $properties, array $functions)
    {
        $this->_syntaxPolicy = new SecurityPolicy($tags, $filters, [], [], $functions, []);
        $this->setAllowedMethods($methods);
        $this->setAllowedProperties($properties);
    }

    public function setAllowedTags(array $tags): void
    {
        $this->_syntaxPolicy->setAllowedTags($tags);
    }

    public function setAllowedFilters(array $filters): void
    {
        $this->_syntaxPolicy->setAllowedFilters($filters);
    }

    public function setAllowedFunctions(array $functions): void
    {
        $this->_syntaxPolicy->setAllowedFunctions($functions);
    }

    public function setAllowedMethods(array $methods): void
    {
        $this->_allowedMethods = [];

        foreach ($methods as $class => $names) {
            $this->_allowedMethods[$class] = array_map('strtolower', (array)$names);
        }
    }

    public function setAllowedProperties(array $properties): void
    {
        $this->_allowedProperties = $properties;
    }

    public function getAllowedPropertyNames(object $object): array
    {
        $names = [];

        foreach ($this->_allowedProperties as $class => $properties) {
            if ($object instanceof $class && !$properties instanceof Closure) {
                $names = array_merge($names, (array)$properties);
            }
        }

        return array_unique($names);
    }

    public function checkSecurity($tags, $filters, $functions): void
    {
        $this->_syntaxPolicy->checkSecurity($tags, $filters, $functions);
    }

    public function checkMethodAllowed($obj, $method): void
    {
        foreach ($this->_allowedMethods as $class => $methods) {
            if ($obj instanceof $class && in_array(strtolower($method), $methods, true)) {
                return;
            }
        }

        throw new SecurityNotAllowedMethodError(sprintf('Calling "%s" method on a "%s" object is not allowed.', $method, $obj::class), $obj::class, $method);
    }

    public function checkPropertyAllowed($obj, $property): void
    {
        foreach ($this->_allowedProperties as $class => $properties) {
            if (!$obj instanceof $class) {
                continue;
            }

            // These predicates are application configuration, never template-provided callbacks.
            $allowed = $properties instanceof Closure ? $properties($obj, $property) : in_array($property, (array)$properties, true);

            if ($allowed) {
                return;
            }
        }

        throw new SecurityNotAllowedPropertyError(sprintf('Reading "%s" property on a "%s" object is not allowed.', $property, $obj::class), $obj::class, $property);
    }
}
