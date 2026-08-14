<?php
namespace verbb\base\twig;

use Closure;

use craft\base\Element as CraftElement;
use craft\base\ElementInterface;
use craft\base\Model;
use craft\elements\db\ElementQueryInterface;

use DateTimeInterface;

use ReflectionClass;
use ReflectionException;

use Twig\Markup;
use Twig\Sandbox\SecurityNotAllowedFilterError;
use Twig\Sandbox\SecurityNotAllowedFunctionError;
use Twig\Sandbox\SecurityNotAllowedMethodError;
use Twig\Sandbox\SecurityNotAllowedPropertyError;
use Twig\Sandbox\SecurityNotAllowedTagError;
use Twig\Sandbox\SecurityPolicyInterface;
use Twig\Template;

/**
 * Twig sandbox security policy for Verbb plugins.
 *
 * Tags/filters/functions stay deny-by-default via explicit allow-lists.
 * Methods/properties on common Craft value objects are allowed by class family,
 * matching Craft’s own sandbox approach (`allowedClasses` + `#[AllowedInSandbox]`),
 * so plugins don’t need to whitelist every legitimate `.one()` / `.title` call.
 */
class SecurityPolicy implements SecurityPolicyInterface
{
    // Properties
    // =========================================================================

    private array $allowedTags = [];
    private array $allowedFilters = [];
    private array $allowedMethods = [];
    private array $allowedProperties = [];
    private array $allowedFunctions = [];
    /** @var class-string[] */
    private array $allowedClasses = [];


    // Public Methods
    // =========================================================================

    public function __construct(array $allowedTags = [], array $allowedFilters = [], array $allowedMethods = [], array $allowedProperties = [], array $allowedFunctions = [], array $allowedClasses = [])
    {
        $this->allowedTags = $allowedTags;
        $this->allowedFilters = $allowedFilters;
        $this->setAllowedMethods($allowedMethods);
        $this->allowedProperties = $allowedProperties;
        $this->allowedFunctions = $allowedFunctions;
        $this->setAllowedClasses($allowedClasses);
    }

    public function setAllowedTags(array $tags): void
    {
        $this->allowedTags = $tags;
    }

    public function setAllowedFilters(array $filters): void
    {
        $this->allowedFilters = $filters;
    }

    public function setAllowedMethods(array $methods): void
    {
        $this->allowedMethods = [];
        foreach ($methods as $class => $m) {
            $this->allowedMethods[$class] = array_map(function ($value) { return strtr($value, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'); }, \is_array($m) ? $m : [$m]);
        }
    }

    public function setAllowedProperties(array $properties): void
    {
        $this->allowedProperties = $properties;
    }

    public function setAllowedFunctions(array $functions): void
    {
        $this->allowedFunctions = $functions;
    }

    /**
     * @param class-string[] $classes
     */
    public function setAllowedClasses(array $classes): void
    {
        // Always keep the built-in safe defaults; plugins can only add classes.
        $this->allowedClasses = array_values(array_unique(array_merge(
            $this->getDefaultAllowedClasses(),
            $classes
        )));
    }

    /**
     * @return class-string[]
     */
    public function getAllowedClasses(): array
    {
        return $this->allowedClasses;
    }

    /**
     * Safe object types for method/property access in sandboxed templates.
     *
     * These are value/query objects commonly exposed in email notifications and
     * similar user-editable Twig — not service containers or app internals.
     *
     * @return class-string[]
     */
    public function getDefaultAllowedClasses(): array
    {
        $classes = [
            ElementInterface::class,
            ElementQueryInterface::class,
            DateTimeInterface::class,
        ];

        // ElementCollection exists from Craft 4.3+
        if (class_exists(\craft\elements\ElementCollection::class)) {
            $classes[] = \craft\elements\ElementCollection::class;
        }

        // Illuminate collections when present
        if (interface_exists(\Illuminate\Support\Enumerable::class)) {
            $classes[] = \Illuminate\Support\Enumerable::class;
        }

        return $classes;
    }

    public function checkSecurity($tags, $filters, $functions): void
    {
        foreach ($tags as $tag) {
            if (!in_array($tag, $this->allowedTags)) {
                throw new SecurityNotAllowedTagError(sprintf('Tag "%s" is not allowed.', $tag), $tag);
            }
        }

        foreach ($filters as $filter) {
            if (!in_array($filter, $this->allowedFilters)) {
                throw new SecurityNotAllowedFilterError(sprintf('Filter "%s" is not allowed.', $filter), $filter);
            }
        }

        foreach ($functions as $function) {
            if (!in_array($function, $this->allowedFunctions)) {
                throw new SecurityNotAllowedFunctionError(sprintf('Function "%s" is not allowed.', $function), $function);
            }
        }
    }

    public function checkMethodAllowed($obj, $method): void
    {
        if ($obj instanceof Template || $obj instanceof Markup) {
            return;
        }

        $methodLower = strtolower($method);

        foreach ($this->allowedMethods as $class => $methods) {
            if ($obj instanceof $class && in_array($methodLower, $methods, true)) {
                return;
            }
        }

        // Honour Craft’s #[AllowedInSandbox] attributes when available (Craft 4.17+)
        if ($this->_hasAllowedInSandboxMethod($obj, $method)) {
            return;
        }

        // Allow all non-dangerous methods on safe value/query object families
        if ($this->_isClassAllowed($obj) && $this->_isSafeMethodName($method)) {
            return;
        }

        $class = $obj::class;
        throw new SecurityNotAllowedMethodError(sprintf('Calling "%s" method on a "%s" object is not allowed.', $method, $class), $class, $method);
    }

    public function checkPropertyAllowed($obj, $property): void
    {
        foreach ($this->allowedProperties as $class => $properties) {
            if ($obj instanceof $class && $this->_isPropertyAllowed($obj, $property, $properties)) {
                return;
            }
        }

        if ($this->_hasAllowedInSandboxProperty($obj, $property)) {
            return;
        }

        if ($this->_isClassAllowed($obj)) {
            return;
        }

        if ($this->_isDefaultPropertyAllowed($obj, $property)) {
            return;
        }

        $class = $obj::class;
        throw new SecurityNotAllowedPropertyError(sprintf('Calling "%s" property on a "%s" object is not allowed.', $property, $class), $class, $property);
    }


    // Private Methods
    // =========================================================================

    private function _isPropertyAllowed(object $obj, string $property, mixed $properties): bool
    {
        if ($properties instanceof Closure) {
            return (bool)$properties($obj, $property);
        }

        return in_array($property, is_array($properties) ? $properties : [$properties], true);
    }

    private function _isDefaultPropertyAllowed(object $obj, string $property): bool
    {
        if ($obj instanceof Model && in_array($property, $obj->attributes(), true)) {
            return true;
        }

        if ($obj instanceof CraftElement) {
            return $obj->getFieldLayout()?->getFieldByHandle($property) !== null;
        }

        return false;
    }

    private function _isClassAllowed(object $obj): bool
    {
        if ($this->_hasAllowedInSandboxClass($obj)) {
            return true;
        }

        foreach ($this->allowedClasses as $class) {
            if (is_string($class) && class_exists($class) === false && interface_exists($class) === false) {
                continue;
            }

            if (is_string($class) && $obj instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * Block PHP magic methods on allowed classes, except `__toString` which is
     * needed for printing elements/queries. Mirrors Craft’s sandbox behaviour.
     */
    private function _isSafeMethodName(string $method): bool
    {
        if (!str_starts_with($method, '__')) {
            return true;
        }

        return strtolower($method) === '__tostring';
    }

    private function _allowedInSandboxAttribute(): ?string
    {
        $attribute = 'craft\\web\\twig\\AllowedInSandbox';

        return class_exists($attribute) ? $attribute : null;
    }

    private function _hasAllowedInSandboxClass(object $obj): bool
    {
        if ($this->_allowedInSandboxAttribute() === null) {
            return false;
        }

        return $this->_hasAllowedInSandboxAttribute($obj, null, true);
    }

    private function _hasAllowedInSandboxMethod(object $obj, string $method): bool
    {
        if ($this->_allowedInSandboxAttribute() === null) {
            return false;
        }

        return $this->_hasAllowedInSandboxAttribute($obj, $method, true);
    }

    private function _hasAllowedInSandboxProperty(object $obj, string $property): bool
    {
        $attribute = $this->_allowedInSandboxAttribute();

        if ($attribute === null) {
            return false;
        }

        try {
            $classRef = new ReflectionClass($obj);

            if ($classRef->hasProperty($property)) {
                $propertyRef = $classRef->getProperty($property);
                if (!empty($propertyRef->getAttributes($attribute))) {
                    return true;
                }
            }

            // Twig property access often maps to getFoo()
            $getter = 'get' . $property;
            if ($classRef->hasMethod($getter) && $this->_hasAllowedInSandboxAttribute($obj, $getter, true)) {
                return true;
            }
        } catch (ReflectionException $e) {
        }

        return false;
    }

    /**
     * @param object|class-string $obj
     */
    private function _hasAllowedInSandboxAttribute($obj, ?string $method, bool $checkInterfaces): bool
    {
        $attribute = $this->_allowedInSandboxAttribute();

        if ($attribute === null) {
            return false;
        }

        try {
            $classRef = new ReflectionClass($obj);

            if ($method === null) {
                if (!empty($classRef->getAttributes($attribute))) {
                    return true;
                }
            } else {
                if ($classRef->hasMethod($method)) {
                    $methodRef = $classRef->getMethod($method);
                    if (!empty($methodRef->getAttributes($attribute))) {
                        return true;
                    }
                }
            }

            $parentClass = $classRef->getParentClass();
            if ($parentClass && $this->_hasAllowedInSandboxAttribute($parentClass->getName(), $method, false)) {
                return true;
            }

            if ($checkInterfaces) {
                foreach ($classRef->getInterfaceNames() as $interfaceName) {
                    if ($this->_hasAllowedInSandboxAttribute($interfaceName, $method, false)) {
                        return true;
                    }
                }
            }
        } catch (ReflectionException $e) {
        }

        return false;
    }
}
