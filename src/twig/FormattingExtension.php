<?php
namespace verbb\base\twig;

use craft\web\twig\Extension;

class FormattingExtension extends Extension
{
    // Public Methods
    // =========================================================================

    public function getFilters(): array
    {
        $allowed = ['camel', 'currency', 'date', 'datetime', 'kebab', 'money', 'percentage', 't', 'time', 'timestamp', 'translate'];

        return array_values(array_filter(parent::getFilters(), static fn($filter) => in_array($filter->getName(), $allowed, true)));
    }

    // Keep Craft's formatting semantics without registering its other template capabilities.
    public function getFunctions(): array
    {
        return [];
    }

    public function getGlobals(): array
    {
        return [];
    }

    public function getNodeVisitors(): array
    {
        return [new SandboxedGetAttrVisitor()];
    }

    public function getTokenParsers(): array
    {
        return [];
    }

    public function getTests(): array
    {
        return [];
    }

    public function getOperators(): array
    {
        return [];
    }
}
