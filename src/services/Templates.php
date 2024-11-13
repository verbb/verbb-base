<?php
namespace verbb\base\services;

use verbb\base\twig\SecurityPolicy;

use Craft;
use craft\base\Component;
use craft\helpers\Json;
use craft\web\twig\Environment;
use craft\web\twig\Extension;
use craft\web\twig\GlobalsExtension;

use Twig\Error\LoaderError;
use Twig\Error\SyntaxError;
use Twig\Extension\SandboxExtension;
use Twig\Extension\StringLoaderExtension;
use Twig\Loader\FilesystemLoader;
use Twig\Parser;

use yii\base\Arrayable;
use yii\base\Model;
use yii\log\Logger;

use Exception;
use ReflectionClass;
use Throwable;

use nystudio107\closure\helpers\Reflection as ReflectionHelper;
use nystudio107\closure\twig\ClosureExpressionParser;

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
        $view = Craft::$app->getView();

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

        // Load in Craft's own Twig extensions
        $this->_twigEnv->addExtension(new StringLoaderExtension());
        $this->_twigEnv->addExtension(new Extension($view, $this->_twigEnv));
        $this->_twigEnv->addExtension(new GlobalsExtension());

        // Access any plugin-defined extensions (via a private property)
        $reflection = new ReflectionClass($view);

        $pluginExtensions = [];

        // Handle Craft 4.13.0+
        if ($reflection->hasProperty('_siteTwigExtensions')) {
            $property = $reflection->getProperty('_siteTwigExtensions');
            $property->setAccessible(true);
            $pluginExtensions = $property->getValue($view);
        } else if ($reflection->hasProperty('_twigExtensions')) {
            $property = $reflection->getProperty('_twigExtensions');
            $property->setAccessible(true);
            $pluginExtensions = $property->getValue($view);
        }

        foreach ($pluginExtensions as $pluginExtension) {
            $this->_twigEnv->addExtension($pluginExtension);
        }

        // Some plugins like Closure (https://github.com/nystudio107/craft-closure) don't register things in the traditional way
        if (class_exists(ClosureExpressionParser::class)) {
            try {
                $parserReflection = ReflectionHelper::getReflectionProperty($this->_twigEnv, 'parser');
                $parserReflection->setAccessible(true);
                $parser = $parserReflection->getValue($this->_twigEnv);

                if ($parser === null) {
                    $parser = new Parser($this->_twigEnv);
                    $parserReflection->setValue($this->_twigEnv, $parser);
                }

                $expressionParserReflection = ReflectionHelper::getReflectionProperty($parser, 'expressionParser');
                $expressionParserReflection->setAccessible(true);
                $expressionParser = new ClosureExpressionParser($parser, $this->_twigEnv);
                $expressionParserReflection->setValue($parser, $expressionParser);
            } catch (Throwable $e) {
                $this->pluginClass::error(Craft::t('app', 'Error parsing template: “{message}” {file}:{line}', [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]));
            }
        }
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
    }

    public function renderString(string $template, array $variables = []): string
    {
        // If there are no dynamic tags, just return the template
        if (!str_contains($template, '{')) {
            return $template;
        }

        $twig = $this->getTwig();

        return $twig->createTemplate($template)->render($variables);
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

            // Craft-specific
            // cache
            // css
            // dd
            // dump
            // exit
            // header
            // hook
            // html
            // js
            // namespace
            // nav
            // paginate
            // redirect
            // requireAdmin
            // requireEdition
            // requireGuest
            // requireLogin
            // requirePermission
            // script
            // switch
            // tag
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

            // Craft-specific
            // address
            // append
            // ascii
            // atom
            // attr
            // base64_decode
            // base64_encode
            // boolean
            'camel',
            // column
            'contains',
            'currency',
            'date',
            'datetime',
            // diff
            // duration
            // encenc
            // explodeClass
            // explodeStyle
            // filesize
            // filter
            // float
            // group
            // hash
            // httpdate
            'id',
            'index',
            'indexOf',
            // integer
            // intersect
            // json_encode
            // json_decode
            'kebab',
            'lcfirst',
            'length',
            // literal
            'markdown',
            'md',
            'merge',
            'money',
            // multisort
            // namespace or ns
            // namespaceAttributes
            // namespaceInputId
            // namespaceInputName
            // number
            // parseAttr
            // parseRefs
            'pascal',
            'percentage',
            // prepend
            'purify',
            // push
            // removeClass
            // rss
            'snake',
            // string
            'time',
            'timestamp',
            'translate',
            't',
            // truncate
            'ucfirst',
            // unique
            // unshift
            'ucwords',
            // values
            // where
            // widont
            // without
            // withoutKey
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
            'include',
            'max',
            'min',
            // 'parent',
            'random',
            'range',
            // 'source',
            // 'template_from_string',

            // Craft-specific
            // actionInput
            'actionUrl',
            // alias
            'attr',
            // beginBody
            // block
            // canCreateDrafts
            // canDelete
            // canDeleteForSite
            // canDuplicate
            // canSave
            // canView
            // ceil
            // className
            // clone
            'collect',
            // combine
            // configure
            // constant
            'cpUrl',
            // create
            // csrfInput
            'dataUrl',
            // dump
            // endBody
            // expression
            // failMessageInput
            // floor
            // getenv
            // gql
            // head
            // hiddenInput
            // input
            // ol
            // parseBooleanEnv
            // parseEnv
            // plugin
            'raw',
            // redirectInput
            // renderObjectTemplate
            // 'seq'
            // 'shuffle'
            'siteUrl',
            // source
            // successMessageInput
            'svg',
            'tag',
            // ul
            'url',
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
