<?php
namespace verbb\base\twig;

use craft\web\twig\Environment;

use Twig\Loader\LoaderInterface;
use Twig\Sandbox\SecurityError;
use Twig\TwigTest;

class SandboxedEnvironment extends Environment
{
    // Properties
    // =========================================================================

    private string $_templateNamespace;
    private array $_allowedTests;


    // Public Methods
    // =========================================================================

    public function __construct(LoaderInterface $loader, array $options = [], array $allowedTests = [])
    {
        $this->_templateNamespace = bin2hex(random_bytes(16));
        $this->_allowedTests = $allowedTests;
        parent::__construct($loader, $options);
    }

    public function getTemplateClass(string $name, ?int $index = null): string
    {
        // Twig's process-wide compiled classes do not distinguish per-instance escaping configuration.
        return parent::getTemplateClass($name, $index) . '_' . $this->_templateNamespace;
    }

    public function getTest(string $name): ?TwigTest
    {
        // Twig 3's sandbox policy does not check tests, including PHP constant access.
        if (!in_array($name, $this->_allowedTests, true)) {
            throw new SecurityError(sprintf('Test "%s" is not allowed.', $name));
        }

        return parent::getTest($name);
    }
}
