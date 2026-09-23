<?php
namespace verbb\base\services;

use verbb\base\twig\FormattingExtension;
use verbb\base\twig\FormattingSecurityPolicy;
use verbb\base\twig\LegacyAttributeExtension;
use verbb\base\twig\SandboxedEnvironment;
use verbb\base\twig\SecurityPolicy;

use Craft;
use craft\base\Component;
use craft\base\Element;
use craft\elements\Asset;
use craft\elements\ElementCollection;
use craft\elements\Entry;
use craft\elements\User;
use craft\elements\db\ElementQueryInterface;
use craft\fields\data\MultiOptionsFieldData;
use craft\fields\data\OptionData;
use craft\helpers\ArrayHelper;
use craft\helpers\DateTimeHelper;
use craft\models\EntryType;
use craft\models\Section;
use craft\models\Site;
use craft\web\twig\Environment;
use craft\web\twig\Extension;
use craft\web\twig\GlobalsExtension;
use craft\web\twig\TemplateLoader;
use craft\web\View;

use yii\base\Arrayable;
use yii\base\Model;

use DateTimeInterface;
use InvalidArgumentException;
use ReflectionClass;
use RuntimeException;
use SplObjectStorage;
use Throwable;

use nystudio107\closure\helpers\Reflection as ReflectionHelper;
use nystudio107\closure\twig\ClosureExpressionParser;
use Twig\Extension\SandboxExtension;
use Twig\Extension\StringLoaderExtension;
use Twig\Loader\FilesystemLoader;
use Twig\Markup;
use Twig\Parser;
use Twig\Sandbox\SecurityNotAllowedPropertyError;

class Templates extends Component
{
    // Properties
    // =========================================================================

    public string $pluginClass;
    public ?array $allowedTags = null;
    public ?array $allowedFilters = null;
    public ?array $allowedFunctions = null;
    public ?array $allowedMethods = null;
    public ?array $allowedProperties = null;
    public ?array $allowedClasses = null;
    public ?array $allowedTests = null;
    /** null follows Craft's template-name escaping; false is for non-HTML output contexts. */
    public string|false|null $sandboxedAutoescape = null;

    private ?Environment $_twigEnv = null;
    private array $_sandboxedTwigEnvs = [];
    private ?FormattingSecurityPolicy $_sandboxedPolicy = null;
    private array $_objectTemplates = [];


    // Public Methods
    // =========================================================================

    /**
     * The legacy environment preserves empty-list defaults and existing policy wrappers.
     */
    public function getTwig(): Environment
    {
        return $this->_twigEnv ??= $this->_createLegacyTwig();
    }

    /**
     * The explicit API uses a separate environment with replacement allowlist semantics.
     */
    public function getSandboxedTwig(): Environment
    {
        return $this->_getSandboxedTwig(null);
    }

    /**
     * Substitute dotted data tokens without evaluating Twig or traversing live objects.
     * Arrayable data is explicitly exported; its normal fields() contract applies.
     */
    public function renderTokens(string $template, array|Arrayable $data): string
    {
        // Skip complete Twig constructs, including any token-like text inside them.
        $pattern = <<<'REGEX'
~(?:
    \{% -? \s* verbatim \s* -? %\} .*? \{% -? \s* endverbatim \s* -? %\}
    | \{\{ (?: "(?:\\.|[^"\\])*" | '(?:\\.|[^'\\])*' | (?!\}\})[^"'] )* \}\}
    | \{% (?: "(?:\\.|[^"\\])*" | '(?:\\.|[^'\\])*' | (?!%\})[^"'] )* %\}
    | \{\# .*? \#\}
)(*SKIP)(*F)
| (?<!\{)\{([a-zA-Z0-9_]+(?:\.[a-zA-Z0-9_]+)*)\}(?!\})
~xs
REGEX;

        $hasTokens = preg_match($pattern, $template);

        if ($hasTokens === false) {
            throw new RuntimeException('Unable to parse tokens: ' . preg_last_error_msg());
        }

        if ($hasTokens === 0) {
            return $template;
        }

        $values = $this->_normalizeTokenData($data, new SplObjectStorage());

        $result = preg_replace_callback($pattern, function(array $matches) use ($values): string {
            // Only arrays and scalar values reach ArrayHelper, so lookup cannot invoke getters.
            $value = ArrayHelper::getValue($values, $matches[1]);

            if (is_bool($value)) {
                return $value ? '1' : '0';
            }

            return is_scalar($value) ? (string)$value : '';
        }, $template);

        if ($result === null) {
            throw new RuntimeException('Unable to replace tokens: ' . preg_last_error_msg());
        }

        return $result;
    }

    /**
     * Null inherits the service's escaping mode; false preserves raw non-HTML output.
     */
    public function renderSandboxedString(string $template, array $variables = [], string|false|null $autoescape = null): string
    {
        if (!str_contains($template, '{')) {
            return $template;
        }

        return $this->_getSandboxedTwig($autoescape)->createTemplate($template)->render($variables + $this->getSandboxedVariables());
    }

    /**
     * Render Craft's object shorthand and Twig in the same sandboxed environment.
     * Craft 5 shorthand is rendered raw, while explicit Twig follows the configured escaping mode.
     */
    public function renderSandboxedObjectTemplate(string $template, mixed $object, array $variables = [], string|false|null $autoescape = null): string
    {
        if (!str_contains($template, '{')) {
            return trim($template);
        }

        return $this->_renderObjectTemplate($this->_getSandboxedTwig($autoescape), $template, $object, $variables + $this->getSandboxedVariables(), true);
    }

    /**
     * Resolve a file through Craft, but render it and its dependencies in Base's sandbox.
     */
    public function renderSandboxedTemplate(string $template, array $variables = [], ?string $templateMode = null, string|false|null $autoescape = null): string
    {
        $twig = $this->_getSandboxedTwig($autoescape);
        $view = Craft::$app->getView();
        $originalMode = $view->getTemplateMode();
        $originalLoader = $twig->getLoader();

        try {
            if ($templateMode !== null) {
                $view->setTemplateMode($templateMode);
            }

            $twig->setLoader(new TemplateLoader($view));

            return $twig->render($template, $variables + $this->getSandboxedVariables());
        } finally {
            // String rendering must not inherit filesystem access from a previous file render.
            $twig->setLoader($originalLoader);
            $view->setTemplateMode($originalMode);
        }
    }

    /**
     * @deprecated Use renderSandboxedObjectTemplate(). This alias retains logged, empty-string failures.
     */
    public function renderObjectTemplate(string $template, mixed $object, array $variables = []): string
    {
        if (!str_contains($template, '{')) {
            return trim($template);
        }

        try {
            $twig = $this->getTwig();
            // Craft 5 renders object templates with escaping disabled, including explicit Twig expressions.
            $twig->setDefaultEscaperStrategy(false);

            try {
                return $this->_renderObjectTemplate($twig, $template, $object, $variables, false);
            } finally {
                $twig->setDefaultEscaperStrategy();
            }
        } catch (Throwable $e) {
            $this->_logError($template, $e);
            return '';
        }
    }

    /**
     * @deprecated Use renderSandboxedString(). This alias retains logged, empty-string failures.
     */
    public function renderString(string $template, array $variables = [], bool $escapeHtml = false): string
    {
        if (!str_contains($template, '{')) {
            return $template;
        }

        try {
            $twig = $this->getTwig();

            if (!$escapeHtml) {
                $twig->setDefaultEscaperStrategy(false);
            }

            try {
                // Twig caches compiled string templates by source and name, so separate both escape modes.
                return $twig->createTemplate($template, $escapeHtml ? 'base:legacy-string-html' : 'base:legacy-string-raw')->render($variables);
            } finally {
                if (!$escapeHtml) {
                    $twig->setDefaultEscaperStrategy();
                }
            }
        } catch (Throwable $e) {
            $this->_logError($template, $e);
            return '';
        }
    }

    public function getDefaultAllowedTags(): array
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

    public function getDefaultAllowedFilters(): array
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

    public function getDefaultAllowedFunctions(): array
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
            // collect — removed: Collection::map/each/etc. accept string callables (sandbox escape)
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

    public function getDefaultAllowedMethods(): array
    {
        return [];
    }

    public function getDefaultAllowedProperties(): array
    {
        return [];
    }

    public function getDefaultAllowedClasses(): array
    {
        return (new SecurityPolicy())->getDefaultAllowedClasses();
    }

    public function getDefaultSandboxedAllowedTags(): array
    {
        return ['for', 'if', 'set'];
    }

    public function getDefaultSandboxedAllowedFilters(): array
    {
        return [
            'abs', 'capitalize', 'date', 'default', 'escape', 'e', 'first', 'format',
            'join', 'keys', 'last', 'length', 'lower', 'merge', 'nl2br', 'number_format',
            'raw', 'replace', 'reverse', 'round', 'slice', 'split', 'striptags', 'title',
            'trim', 'upper',
            'camel', 'currency', 'datetime', 'kebab', 'money', 'percentage',
            't', 'time', 'timestamp', 'translate',
        ];
    }

    public function getDefaultSandboxedAllowedFunctions(): array
    {
        return ['date', 'max', 'min', 'range'];
    }

    public function getDefaultSandboxedAllowedMethods(): array
    {
        return [
            Element::class => ['__toString'],
            ElementQueryInterface::class => ['one', 'all', 'count'],
            ElementCollection::class => ['all', 'count', 'isEmpty', 'isNotEmpty'],
            MultiOptionsFieldData::class => ['__toString'],
            OptionData::class => ['__toString'],
            DateTimeInterface::class => ['format', 'getTimestamp', 'getOffset'],
            Markup::class => ['__toString'],
        ];
    }

    public function getDefaultSandboxedAllowedProperties(): array
    {
        return [
            Element::class => static function(Element $element, string $name): bool {
                if (in_array($name, ['id', 'uid', 'title', 'slug', 'uri', 'url', 'site', 'siteId', 'status', 'enabled', 'dateCreated', 'dateUpdated'], true)) {
                    return true;
                }

                // A visitor's profile fields are not necessarily public template data.
                return !$element instanceof User && $element->getFieldLayout()?->getFieldByHandle($name) !== null;
            },
            Asset::class => ['filename', 'extension', 'kind', 'size', 'width', 'height', 'mimeType', 'alt'],
            Entry::class => ['postDate', 'expiryDate', 'section', 'sectionId', 'type', 'typeId', 'author', 'authorId'],
            User::class => ['username', 'email', 'firstName', 'lastName', 'fullName', 'friendlyName', 'name', 'admin'],
            EntryType::class => ['id', 'uid', 'name', 'handle'],
            Section::class => ['id', 'uid', 'name', 'handle'],
            Site::class => ['id', 'uid', 'name', 'handle', 'language', 'baseUrl'],
            OptionData::class => ['label', 'value', 'selected', 'valid'],
            ElementCollection::class => static function(ElementCollection $collection, string $name): bool {
                return ctype_digit($name) && $collection->offsetExists((int)$name);
            },
        ];
    }

    public function getSandboxedVariables(): array
    {
        return [];
    }

    /**
     * Plugins opt into these current-request values without importing Craft's service globals.
     */
    public function getSiteTemplateVariables(): array
    {
        $site = Craft::$app->getSites()->getCurrentSite();

        return [
            'currentSite' => $site,
            'currentUser' => Craft::$app->getUser()->getIdentity(),
            'siteName' => Craft::t('site', $site->getName()),
            'siteUrl' => $site->getBaseUrl(),
            'now' => DateTimeHelper::currentUTCDateTime(),
        ];
    }

    public function getDefaultSandboxedAllowedTests(): array
    {
        return ['defined', 'divisible by', 'empty', 'even', 'iterable', 'mapping', 'none', 'null', 'odd', 'same as', 'sequence', 'true'];
    }


    // Private Methods
    // =========================================================================

    private function _renderObjectTemplate(Environment $twig, string $template, mixed $object, array $variables, bool $sandboxed): string
    {
        $cacheKey = spl_object_id($twig) . ':' . md5($template);
        $template = Craft::$app->getView()->normalizeObjectTemplate($template);

        if ($sandboxed) {
            // Craft 5 dropped shorthand's |raw suffix because its own renderer disables escaping globally.
            // Restore the suffix only on normalized shorthand so explicit {{ expressions }} stay escaped.
            $template = preg_replace('/\{\{ (\(_variables\.(\w+) \?\? object\.\2\)[^{}]*?) \}\}/', '{{ $1|raw }}', $template);
        }

        if (!isset($this->_objectTemplates[$cacheKey])) {
            $this->_objectTemplates[$cacheKey] = $twig->createTemplate($template, $sandboxed ? 'base:sandboxed-object' : 'base:legacy-object');
        }

        if ($sandboxed) {
            $variables = $this->_getSandboxedObjectVariables($twig, $template, $object, $variables);
        } else if ($object instanceof Model) {
            foreach ($object->attributes() as $name) {
                if (!isset($variables[$name]) && str_contains($template, $name)) {
                    $variables[$name] = $object->$name;
                }
            }
        }

        if (!$sandboxed && $object instanceof Arrayable) {
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

        return trim($this->_objectTemplates[$cacheKey]->render($variables));
    }

    private function _getSandboxedObjectVariables(Environment $twig, string $template, mixed $object, array $variables): array
    {
        if (is_array($object)) {
            return $variables + $object;
        }

        if (!is_object($object)) {
            return $variables;
        }

        $names = $object instanceof Model ? $object->attributes() : array_keys(get_object_vars($object));
        $policy = $twig->getExtension(SandboxExtension::class)->getSecurityPolicy();

        if ($policy instanceof FormattingSecurityPolicy) {
            $names = array_merge($names, $policy->getAllowedPropertyNames($object));
        }

        if ($object instanceof Element) {
            $names = array_merge($names, ['url', 'site', 'status']);

            foreach ($object->getFieldLayout()?->getCustomFields() ?? [] as $field) {
                $names[] = $field->handle;
            }
        }

        foreach (array_unique($names) as $name) {
            if (array_key_exists($name, $variables) || !str_contains($template, $name)) {
                continue;
            }

            try {
                $policy->checkPropertyAllowed($object, $name);
            } catch (SecurityNotAllowedPropertyError $e) {
                // Denied data must not become unrestricted top-level template variables.
                continue;
            }

            $variables[$name] = $object->$name;
        }

        return $variables;
    }

    private function _getSandboxedTwig(string|false|null $autoescape): Environment
    {
        // Escaping is compiled into Twig templates, so each output mode needs its own environment.
        // Null inherits the service default; every mode shares the same sandbox policy instance.
        $autoescape ??= $this->sandboxedAutoescape;
        $key = $autoescape === false ? 'false' : ($autoescape === null ? 'null' : 'strategy:' . $autoescape);

        return $this->_sandboxedTwigEnvs[$key] ??= $this->_createSandboxedTwig($autoescape);
    }

    private function _createSandboxedTwig(string|false|null $autoescape): Environment
    {
        if ($this->allowedClasses) {
            throw new InvalidArgumentException('The explicit sandbox uses allowedMethods and allowedProperties; broad allowedClasses permissions are only supported by the legacy renderers.');
        }

        $policy = $this->_sandboxedPolicy ??= new FormattingSecurityPolicy(
            $this->allowedTags ?? $this->getDefaultSandboxedAllowedTags(),
            $this->allowedFilters ?? $this->getDefaultSandboxedAllowedFilters(),
            $this->allowedMethods ?? $this->getDefaultSandboxedAllowedMethods(),
            $this->allowedProperties ?? $this->getDefaultSandboxedAllowedProperties(),
            $this->allowedFunctions ?? $this->getDefaultSandboxedAllowedFunctions(),
        );

        $twig = new SandboxedEnvironment(new FilesystemLoader(), ['strict_variables' => true], $this->allowedTests ?? $this->getDefaultSandboxedAllowedTests());
        $twig->setDefaultEscaperStrategy($autoescape);
        $twig->addExtension(new SandboxExtension($policy, true));

        $twig->addExtension(new FormattingExtension(Craft::$app->getView(), $twig));

        return $twig;
    }

    private function _normalizeTokenData(mixed $value, SplObjectStorage $ancestors, int $depth = 0): mixed
    {
        if ($depth > 64) {
            throw new InvalidArgumentException('Token data exceeds the maximum nesting depth of 64.');
        }

        if ($value instanceof Arrayable) {
            if ($ancestors->contains($value)) {
                throw new InvalidArgumentException('Token data contains a circular Arrayable reference.');
            }

            $ancestors->attach($value);

            try {
                return $this->_normalizeTokenData($value->toArray([], [], false), $ancestors, $depth + 1);
            } finally {
                $ancestors->detach($value);
            }
        }

        if (is_array($value)) {
            $normalized = [];

            foreach ($value as $key => $item) {
                $normalized[$key] = $this->_normalizeTokenData($item, $ancestors, $depth + 1);
            }

            return $normalized;
        }

        // Arbitrary objects, ArrayAccess and Stringable are not a token data contract.
        return is_scalar($value) || $value === null ? $value : null;
    }

    private function _logError(string $template, Throwable $e): void
    {
        $this->pluginClass::error(Craft::t('app', 'Error parsing template: “{template}”: “{message}” {file}:{line}', [
            'template' => $template,
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]));
    }

    private function _createLegacyTwig(): Environment
    {
        $view = Craft::$app->getView();

        $tags = $this->allowedTags ?? $this->getDefaultAllowedTags();
        $filters = $this->allowedFilters ?? $this->getDefaultAllowedFilters();
        $functions = $this->allowedFunctions ?? $this->getDefaultAllowedFunctions();
        $methods = $this->allowedMethods ?? $this->getDefaultAllowedMethods();
        $properties = $this->allowedProperties ?? $this->getDefaultAllowedProperties();
        $classes = $this->allowedClasses ?? $this->getDefaultAllowedClasses();

        // Existing callers (including Formie's wrapper) use [] to request defaults.
        $tags = $tags ?: $this->getDefaultAllowedTags();
        $filters = $filters ?: $this->getDefaultAllowedFilters();
        $functions = $functions ?: $this->getDefaultAllowedFunctions();
        $classes = array_values(array_unique(array_merge($this->getDefaultAllowedClasses(), $classes)));

        $policy = new SecurityPolicy($tags, $filters, $methods, $properties, $functions, $classes);
        $loader = new FilesystemLoader();
        $sandbox = new SandboxExtension($policy, true);

        $twig = new Environment($loader);
        $twig->addExtension($sandbox);
        $twig->addExtension(new LegacyAttributeExtension());

        // Load in Craft's own Twig extensions
        $twig->addExtension(new StringLoaderExtension());
        $twig->addExtension(new Extension($view, $twig));
        $twig->addExtension(new GlobalsExtension());

        // Access any plugin-defined extensions (via a private property)
        $reflection = new ReflectionClass(View::class);

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
            // A registered Craft sandbox must never replace this service's own policy.
            if (!$pluginExtension instanceof SandboxExtension) {
                $twig->addExtension($pluginExtension);
            }
        }

        // Some plugins like Closure (https://github.com/nystudio107/craft-closure) don't register things in the traditional way
        if (class_exists(ClosureExpressionParser::class)) {
            try {
                $parserReflection = ReflectionHelper::getReflectionProperty($twig, 'parser');
                $parserReflection->setAccessible(true);
                $parser = $parserReflection->getValue($twig);

                if ($parser === null) {
                    $parser = new Parser($twig);
                    $parserReflection->setValue($twig, $parser);
                }

                $expressionParserReflection = ReflectionHelper::getReflectionProperty($parser, 'expressionParser');
                $expressionParserReflection->setAccessible(true);
                $expressionParser = new ClosureExpressionParser($parser, $twig);
                $expressionParserReflection->setValue($parser, $expressionParser);
            } catch (Throwable $e) {
                $this->pluginClass::error(Craft::t('app', 'Error parsing template: “{message}” {file}:{line}', [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]));
            }
        }

        return $twig;
    }
}
