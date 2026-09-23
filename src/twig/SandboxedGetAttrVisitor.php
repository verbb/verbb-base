<?php
namespace verbb\base\twig;

use Twig\Environment;
use Twig\Node\Expression\ArrayExpression;
use Twig\Node\Expression\GetAttrExpression;
use Twig\Node\Expression\NameExpression;
use Twig\Node\Node;
use Twig\NodeVisitor\NodeVisitorInterface;

class SandboxedGetAttrVisitor implements NodeVisitorInterface
{
    public function __construct(private bool $strictProperties = true)
    {
    }

    // Public Methods
    // =========================================================================

    public function enterNode(Node $node, Environment $env): Node
    {
        if ($node instanceof ArrayExpression) {
            // Twig implicitly casts context-variable mapping keys without checking __toString.
            $index = 0;

            foreach ($node as $name => $child) {
                if ($index % 2 === 0 && $child instanceof NameExpression) {
                    $node->setNode((string)$name, new SandboxedArrayKeyExpression($child));
                }

                $index++;
            }
        }

        return get_class($node) === GetAttrExpression::class ? new SandboxedGetAttrExpression($node, $this->strictProperties) : $node;
    }

    public function leaveNode(Node $node, Environment $env): ?Node
    {
        return $node;
    }

    public function getPriority(): int
    {
        return 0;
    }
}
