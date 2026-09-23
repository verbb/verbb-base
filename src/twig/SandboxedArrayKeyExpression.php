<?php
namespace verbb\base\twig;

use Twig\Compiler;
use Twig\Extension\SandboxExtension;
use Twig\Node\Expression\AbstractExpression;

class SandboxedArrayKeyExpression extends AbstractExpression
{
    // Public Methods
    // =========================================================================

    public function __construct(AbstractExpression $key)
    {
        parent::__construct(['key' => $key], [], $key->getTemplateLine());
    }

    public function compile(Compiler $compiler): void
    {
        // Match Twig's implicit string cast, checking the sandbox policy first.
        $compiler
            ->raw('(string)$this->env->getExtension(')->repr(SandboxExtension::class)
            ->raw(')->ensureToStringAllowed(')
            ->subcompile($this->getNode('key'))
            ->raw(', ')->repr($this->getTemplateLine())
            ->raw(', $this->source)');
    }
}
