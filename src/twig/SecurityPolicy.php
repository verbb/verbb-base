<?php
namespace verbb\base\twig;

use Twig\Markup;
use Twig\Sandbox\SecurityNotAllowedFilterError;
use Twig\Sandbox\SecurityNotAllowedFunctionError;
use Twig\Sandbox\SecurityNotAllowedTagError;
use Twig\Sandbox\SecurityPolicyInterface;
use Twig\Template;

class SecurityPolicy implements SecurityPolicyInterface
{
    // Properties
    // =========================================================================

    private array $allowedTags = [];
    private array $allowedFilters = [];
    private array $allowedMethods = [];
    private array $allowedProperties = [];
    private array $allowedFunctions = [];


    // Public Methods
    // =========================================================================

    public function __construct(array $allowedTags = [], array $allowedFilters = [], array $allowedMethods = [], array $allowedProperties = [], array $allowedFunctions = [])
    {
        $this->allowedTags = $allowedTags;
        $this->allowedFilters = $allowedFilters;
        $this->setAllowedMethods($allowedMethods);
        $this->allowedProperties = $allowedProperties;
        $this->allowedFunctions = $allowedFunctions;
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
        // Allow all methods
    }

    public function checkPropertyAllowed($obj, $property): void
    {
        // Allow all proprties
    }
}
