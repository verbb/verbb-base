<?php
namespace verbb\base\twig;

use Twig\Compiler;
use Twig\Node\Expression\GetAttrExpression;

class SandboxedGetAttrExpression extends GetAttrExpression
{
    // Public Methods
    // =========================================================================

    public function __construct(GetAttrExpression $node, private bool $strictProperties = true)
    {
        parent::__construct($node->getNode('node'), $node->getNode('attribute'), $node->hasNode('arguments') ? $node->getNode('arguments') : null, $node->getAttribute('type'), $node->getTemplateLine());

        foreach (['is_defined_test', 'ignore_strict_check', 'optimizable', 'spread'] as $name) {
            if ($node->hasAttribute($name)) {
                $this->setAttribute($name, $node->getAttribute($name));
            }
        }
    }

    public function compile(Compiler $compiler): void
    {
        if ($this->getAttribute('ignore_strict_check')) {
            $this->getNode('node')->setAttribute('ignore_strict_check', true);
        }

        // All attribute forms share the argument guard; do not optimise array access around it.
        $compiler
            ->raw('\\' . SandboxedAttribute::class . '::getAttribute($this->env, $this->source, ')
            ->subcompile($this->getNode('node'))
            ->raw(', ')
            ->subcompile($this->getNode('attribute'))
            ->raw(', ');

        if ($this->hasNode('arguments')) {
            $compiler->subcompile($this->getNode('arguments'));
        } else {
            $compiler->raw('[]');
        }

        $compiler
            ->raw(', ')->repr($this->getAttribute('type'))
            ->raw(', ')->repr($this->getAttribute('is_defined_test'))
            ->raw(', ')->repr($this->getAttribute('ignore_strict_check'))
            ->raw(', ')->repr($this->getTemplateLine())
            ->raw(', ')->repr($this->strictProperties)
            ->raw(')');
    }
}
