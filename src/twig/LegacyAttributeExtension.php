<?php
namespace verbb\base\twig;

use Twig\Extension\AbstractExtension;

class LegacyAttributeExtension extends AbstractExtension
{
    // Public Methods
    // =========================================================================

    public function getNodeVisitors(): array
    {
        return [new SandboxedGetAttrVisitor(false)];
    }
}
