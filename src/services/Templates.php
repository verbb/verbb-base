<?php
namespace verbb\base\services;

use Craft;
use craft\base\Component;
use craft\helpers\Json;
use craft\web\twig\Environment;

use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;
use Twig\Extension\SandboxExtension;
use Twig\Loader\FilesystemLoader;
use Twig\Sandbox\SecurityPolicy;

use yii\base\Arrayable;
use yii\base\Model;
use yii\log\Logger;

use Exception;
use Throwable;

class Templates extends Component
{
    // Properties
    // =========================================================================

    public string $pluginClass;
    public array $allowedTags = [];
    public array $allowedFilters = [];
    public array $allowedFunctions = [];
    public array $allowedMethods = [];
    public array $allowedProperties = [];

    private Environment $_twigEnv;
    private array $_objectTemplates = [];


    // Public Methods
    // =========================================================================

    public function init(): void
    {
        $tags = $this->allowedTags ?: $this->_getTags();
        $filters = $this->allowedFilters ?: $this->_getFilters();
        $functions = $this->allowedFunctions ?: $this->_getFunctions();
        $methods = $this->allowedMethods ?: $this->_getMethods();
        $properties = $this->allowedProperties ?: $this->_getProperties();

        $policy = new SecurityPolicy($tags, $filters, $methods, $properties, $functions);
        $loader = new FilesystemLoader();
        $sandbox = new SandboxExtension($policy, true);

        $this->_twigEnv = new Environment($loader);
        $this->_twigEnv->addExtension($sandbox);
    }

    public function getTwig(): Environment
    {
        return $this->_twigEnv;
    }

    public function renderObjectTemplate(string $template, mixed $object, array $variables = []): string
    {
        $originalTemplate = $template;
        
        // If there are no dynamic tags, just return the template
        if (!str_contains($template, '{')) {
            return trim($template);
        }

        $twig = $this->getTwig();

        try {
            // Is this the first time we've parsed this template?
            $cacheKey = md5($template);

            if (!isset($this->_objectTemplates[$cacheKey])) {
                // Replace shortcut "{var}"s with "{{object.var}}"s, without affecting normal Twig tags
                $template = Craft::$app->getView()->normalizeObjectTemplate($template);

                $this->_objectTemplates[$cacheKey] = $twig->createTemplate($template);
            }

            // Get the variables to pass to the template
            if ($object instanceof Model) {
                foreach ($object->attributes() as $name) {
                    if (!isset($variables[$name]) && str_contains($template, $name)) {
                        $variables[$name] = $object->$name;
                    }
                }
            }

            if ($object instanceof Arrayable) {
                // See if we should be including any of the extra fields
                $extra = [];

                foreach ($object->extraFields() as $field => $definition) {
                    if (is_int($field)) {
                        $field = $definition;
                    }

                    if (preg_match('/\b' . preg_quote($field, '/') . '\b/', $template)) {
                        $extra[] = $field;
                    }
                }

                $variables += $object->toArray([], $extra, false);
            }

            $variables['object'] = $object;
            $variables['_variables'] = $variables;

            // Render it!
            /** @var TwigTemplate $templateObj */
            $templateObj = $this->_objectTemplates[$cacheKey];
            return trim($templateObj->render($variables));
        } catch (Throwable $e) {
            $this->pluginClass::error(Craft::t('app', 'Error parsing template: “{template}”: “{message}” {file}:{line}', [
                'template' => $originalTemplate,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]));
        }

        return '';
    }

    public function renderString(string $template, array $variables = []): string
    {
        // If there are no dynamic tags, just return the template
        if (!str_contains($template, '{')) {
            return $template;
        }

        $twig = $this->getTwig();

        try {
            return $twig->createTemplate($template)->render($variables);
        } catch (Throwable $e) {
            $this->pluginClass::error(Craft::t('app', 'Error parsing template: “{template}”: “{message}” {file}:{line}', [
                'template' => $template,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]));
        }

        return '';
    }


    // Public Methods
    // =========================================================================

    private function _getTags(): array
    {
        return [
            // 'apply',
            // 'autoescape',
            // 'block',
            // 'deprecated',
            // 'do',
            // 'embed',
            // 'extends',
            // 'flush',
            'for',
            // 'from',
            'if',
            // 'import',
            // 'include',
            // 'macro',
            // 'sandbox',
            'set',
            // 'use',
            // 'verbatim',
            // 'with',
        ];
    }

    private function _getFilters(): array
    {
        return [
            // 'abs',
            // 'batch',
            'capitalize',
            // 'column',
            // 'convert_encoding',
            // 'country_name',
            // 'country_timezones',
            // 'currency_name',
            // 'currency_symbol',
            // 'data_uri',
            'date',
            // 'date_modify',
            // 'default',
            'escape',
            // 'filter',
            'first',
            // 'format',
            // 'format_currency',
            // 'format_date',
            // 'format_datetime',
            // 'format_number',
            // 'format_time',
            // 'inky',
            // 'inline_css',
            'join',
            // 'json_encode',
            'keys',
            // 'language_name',
            'last',
            'length',
            // 'locale_name',
            'lower',
            // 'map',
            'markdown',
            // 'merge',
            'nl2br',
            'number_format',
            'raw',
            // 'reduce',
            'replace',
            // 'reverse',
            // 'round',
            // 'slice',
            'sort',
            // 'spaceless',
            'split',
            'striptags',
            // 'timezone_name',
            'title',
            'trim',
            'upper',
            // 'url_encode',
        ];
    }

    private function _getFunctions(): array
    {
        return [
            // 'attribute',
            // 'block',
            // 'constant',
            // 'cycle',
            'date',
            // 'dump',
            // 'html_classes',
            // 'include',
            'max',
            'min',
            // 'parent',
            'random',
            'range',
            // 'source',
            // 'template_from_string',
        ];
    }

    private function _getMethods(): array
    {
        return [];
    }

    private function _getProperties(): array
    {
        return [];
    }

}
