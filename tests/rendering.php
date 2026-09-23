<?php

// Run against an installed Craft 5 project's dependencies without booting its application or database.
$autoload = getenv('CRAFT_VENDOR_AUTOLOAD') ?: dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Set CRAFT_VENDOR_AUTOLOAD to a Craft 5 project's vendor/autoload.php.\n");
    exit(1);
}
$loader = require $autoload;
$loader->addPsr4('verbb\\base\\', dirname(__DIR__) . '/src', true);
$loader->addClassMap([
    verbb\base\services\Templates::class => dirname(__DIR__) . '/src/services/Templates.php',
    verbb\base\twig\SecurityPolicy::class => dirname(__DIR__) . '/src/twig/SecurityPolicy.php',
    verbb\base\twigextensions\Extension::class => dirname(__DIR__) . '/src/twigextensions/Extension.php',
]);
require_once dirname($autoload) . '/yiisoft/yii2/Yii.php';
require_once dirname($autoload) . '/craftcms/cms/src/Craft.php';

use craft\web\View;
use Twig\Error\LoaderError;
use Twig\Extension\SandboxExtension;
use Twig\Sandbox\SecurityError;
use verbb\base\services\Templates;
use verbb\base\twig\SecurityPolicy;

final class TestLog
{
    public static array $messages = [];
    public static function error(string $message): void { self::$messages[] = $message; }
}

final class TestData extends yii\base\Model
{
    public $number = 42;
    public $totalPrice = 1234.5;
    public function getSuffix(): string { return 'ready'; }
    public function extraFields(): array { return ['suffix' => fn() => 'ready']; }
}

final class UntrustedValue
{
    public function getSecret(): string { throw new RuntimeException('Getter must not run.'); }
    public function __toString(): string { throw new RuntimeException('String conversion must not run.'); }
}

final class CountQuery extends craft\elements\db\ElementQuery
{
    public array $expressions = [];
    public function behaviors(): array { return []; }
    protected function queryScalar($selectExpression, $db): bool|string|null {
        $this->expressions[] = $selectExpression;
        return '2';
    }
}

function same($expected, $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function raises(string $class, callable $callback): void
{
    try {
        $callback();
    } catch (Throwable $error) {
        if ($error instanceof $class) {
            return;
        }
        throw $error;
    }
    throw new RuntimeException("Expected $class.");
}

function check(string $name, callable $callback): void
{
    global $passed;
    $callback();
    $passed++;
    echo "PASS $name\n";
}

function service(array $config = [], string $class = Templates::class): Templates
{
    $service = new $class($config + ['pluginClass' => TestLog::class]);

    foreach ([$service->getTwig()] as $twig) {
        // Keep database-backed Craft global discovery out of this component test.
        // The production environment, extensions, compiler, loader and sandbox remain intact.
        $property = new ReflectionProperty(Twig\Environment::class, 'extensionSet');
        $property->setAccessible(true);
        $extensions = $property->getValue($twig);
        $property = new ReflectionProperty($extensions, 'globals');
        $property->setAccessible(true);
        $property->setValue($extensions, []);
    }

    return $service;
}

$passed = 0;
Craft::$app = null;
$tokens = new Templates();
check('tokens work without a Craft application', function() use ($tokens) {
    same(' Order 42 — en — a ', $tokens->renderTokens(' Order {number} — {site.handle} — {items.0} ', ['number' => 42, 'site' => ['handle' => 'en'], 'items' => ['a']]));
    same('42', $tokens->renderTokens('{number}', new TestData()));
    same('42', $tokens->renderTokens('{order.number}', ['order' => new TestData()]));
});
check('token scalar and missing-value rules', function() use ($tokens) {
    same('0|1|0|||', $tokens->renderTokens('{zero}|{yes}|{no}|{null}|{missing}|{list}', ['zero' => 0, 'yes' => true, 'no' => false, 'null' => null, 'list' => [1]]));
    same('||', $tokens->renderTokens('{value}|{value.secret}|{value.0}', ['value' => new UntrustedValue()]));
    $object = new UntrustedValue();
    $reference = $object;
    $data = ['value' => &$reference];
    same('', $tokens->renderTokens('{value}', $data));
    same($object, $reference);
});
check('tokens do not evaluate Twig or recursively replace inserted text', function() use ($tokens) {
    $source = 'Order {number} — {{ totalPrice|number_format(2) }} {% set x = "{number}" %} {# {number} #}';
    same('Order 42 — {{ totalPrice|number_format(2) }} {% set x = "{number}" %} {# {number} #}', $tokens->renderTokens($source, ['number' => 42]));
    same('{other} {{ 1 + 1 }}', $tokens->renderTokens('{number}', ['number' => '{other} {{ 1 + 1 }}', 'other' => 'oops']));
    same('{number|upper} {number()} {{number}}', $tokens->renderTokens('{number|upper} {number()} {{number}}', ['number' => 42]));
    $quoted = '{{ "}}" ~ "{number}" }} {% set value = "%} {number}" %} {% verbatim %}{number}{% endverbatim %}';
    same($quoted, $tokens->renderTokens($quoted, ['number' => 42]));
});
check('circular token data fails predictably', function() use ($tokens) {
    $data = [];
    $data['self'] = &$data;
    raises(InvalidArgumentException::class, fn() => $tokens->renderTokens('{self}', $data));
    $object = new class extends yii\base\Model {
        public function fields(): array { return ['self' => fn() => $this]; }
    };
    raises(InvalidArgumentException::class, fn() => $tokens->renderTokens('{self}', $object));
});

$root = sys_get_temp_dir() . '/verbb-base-rendering-' . bin2hex(random_bytes(8));
mkdir($root);
mkdir($root . '/site');
mkdir($root . '/cp');
$general = new craft\config\GeneralConfig();
$general->enableTwigSandbox = false;
$view = (new ReflectionClass(View::class))->newInstanceWithoutConstructor();
$app = new class($view, $root, $general) {
    public string $charset = 'UTF-8';
    public string $language = 'en-US';
    public function __construct(public View $view, public string $root, public $general) {}
    public function getView() { return $this->view; }
    public function getSecurity() { return new yii\base\Security(); }
    public function getFormatter() { return new craft\i18n\Formatter(['locale' => 'en-US', 'timeZone' => 'UTC', 'defaultTimeZone' => 'UTC']); }
    public function getIsInstalled() { return false; }
    public function getUser() { return new class {
        public function getIdentity() { return null; }
    }; }
    public function getConfig() { return new class($this->general) {
        public function __construct(public $general) {}
        public function getGeneral() { return $this->general; }
    }; }
    public function getPath() { return new class($this->root) {
        public function __construct(public string $root) {}
        public function getCpTemplatesPath() { return $this->root . '/cp'; }
        public function getSiteTemplatesPath() { return $this->root . '/site'; }
    }; }
    public function getI18n() { return new class {
        public function translate($category, $message, $params, $language) {
            return strtr($message, array_combine(array_map(fn($key) => '{' . $key . '}', array_keys($params)), array_values($params)));
        }
    }; }
};
Craft::$app = $app;
$view->setTemplateMode(View::TEMPLATE_MODE_SITE);

try {
    $base = service(['allowedProperties' => [TestData::class => ['number', 'totalPrice', 'suffix']] + (new Templates())->getDefaultSandboxedAllowedProperties()]);
    check('sandbox is on even when Craft sandbox configuration is off', function() use ($base) {
        same(true, $base->getSandboxedTwig()->getExtension(SandboxExtension::class)->isSandboxed());
        same('en: 1,234.50', $base->renderSandboxedString("{{ site.handle == 'en' ? 'en' : 'de' }}: {{ totalPrice|number_format(2) }}", ['site' => ['handle' => 'en'], 'totalPrice' => 1234.5]));
        raises(Twig\Error\SyntaxError::class, fn() => $base->renderSandboxedString("{{ getenv('PATH') }}"));
        raises(SecurityError::class, fn() => $base->renderSandboxedString("{{ value.secret }}", ['value' => new UntrustedValue()]));
    });
    check('object shorthand and Twig share the sandbox', function() use ($base) {
        $template = ' Order {number} — {{ totalPrice|number_format(2) }} — {suffix} ';
        same('Order 42 — 1,234.50 — ready', $base->renderSandboxedObjectTemplate($template, new TestData()));
        same('Order 99 — 1,234.50 — ready', $base->renderSandboxedObjectTemplate($template, new TestData(), ['number' => 99]));
        same('en — en', $base->renderSandboxedObjectTemplate('{site.handle} — {{ site.handle }}', ['site' => ['handle' => 'en']]));
        same('de — de', $base->renderSandboxedObjectTemplate('{site.handle} — {{ site.handle }}', ['site' => ['handle' => 'en']], ['site' => ['handle' => 'de']]));
        same('<b>:&lt;b&gt;', $base->renderSandboxedObjectTemplate('{number}:{{ number }}', new TestData(), ['number' => '<b>']));
        raises(Twig\Error\SyntaxError::class, fn() => $base->renderSandboxedObjectTemplate("{number} {{ getenv('PATH') }}", new TestData()));
    });
    check('model exports cannot bypass object property permissions', function() {
        $data = new class extends yii\base\Model {
            public $number = 42;
            public $secret = 'private';
            public function extraFields(): array { return ['exported' => fn() => throw new RuntimeException('Export must not run.')]; }
            public function getConfig(): string { throw new RuntimeException('Getter must not run.'); }
        };
        $renderer = service(['allowedProperties' => [$data::class => ['number']]]);
        same('42', $renderer->renderSandboxedObjectTemplate('{{ number }}', $data));
        raises(Twig\Error\RuntimeError::class, fn() => $renderer->renderSandboxedObjectTemplate('{{ secret }}', $data));
        raises(SecurityError::class, fn() => $renderer->renderSandboxedObjectTemplate('{{ object.secret }}', $data));
        raises(SecurityError::class, fn() => $renderer->renderSandboxedObjectTemplate('{{ object.config }}', $data));
        raises(Twig\Error\RuntimeError::class, fn() => $renderer->renderSandboxedObjectTemplate('{{ exported }}', $data));
    });
    check('relation counting accepts no arguments across direct and dynamic calls', function() {
        $query = (new ReflectionClass(CountQuery::class))->newInstanceWithoutConstructor();
        $renderer = service(['allowedFunctions' => array_merge((new Templates())->getDefaultSandboxedAllowedFunctions(), ['attribute'])]);
        foreach (['{{ query.count() }}', '{{ query.CoUnT() }}', '{{ query.count(...args) }}', '{{ attribute(query, name, args) }}', '{{ query.(name)() }}'] as $source) {
            same('2', $renderer->renderSandboxedString($source, ['query' => $query, 'args' => [], 'name' => 'count']));
        }
        same(array_fill(0, 5, 'COUNT(*)'), $query->expressions);
        raises(SecurityError::class, fn() => service(['allowedMethods' => []])->renderSandboxedString('{{ query.count() }}', ['query' => $query]));
        same(5, count($query->expressions));
    });
    check('query count expressions are rejected before the SQL boundary in every renderer', function() use ($root) {
        $query = (new ReflectionClass(CountQuery::class))->newInstanceWithoutConstructor();
        $renderer = service(['allowedFunctions' => array_merge((new Templates())->getDefaultSandboxedAllowedFunctions(), ['attribute'])]);
        $variables = ['query' => $query, 'sql' => 'CASE WHEN (SELECT 1) = 1 THEN 1 END', 'args' => ['*'], 'name' => 'CoUnT'];
        foreach (['{{ query.count(sql) }}', '{{ query.CoUnT(sql) }}', '{{ query.count(q: sql) }}', '{{ query.count(q = sql) }}', '{{ query.count(...args) }}', '{{ query.(name)(sql) }}', '{{ attribute(query, name, args) }}', '{{ query.count(null) }}'] as $source) {
            raises(SecurityError::class, fn() => $renderer->renderSandboxedString($source, $variables));
            raises(SecurityError::class, fn() => $renderer->renderSandboxedObjectTemplate($source, $variables));
        }
        raises(SecurityError::class, fn() => $renderer->renderSandboxedObjectTemplate('{query.count(sql)}', $variables));
        file_put_contents($root . '/site/count.twig', '{{ query.count(sql) }}');
        raises(SecurityError::class, fn() => $renderer->renderSandboxedTemplate('count', $variables));
        $variables['name'] = new Twig\Markup('count', 'UTF-8');
        raises(SecurityError::class, fn() => $renderer->renderSandboxedString('{{ attribute(query, name, args) }}', $variables));
        same([], $query->expressions);
    });
    check('legacy renderer rejects query count expressions without changing normal counts', function() {
        $query = (new ReflectionClass(CountQuery::class))->newInstanceWithoutConstructor();
        $renderer = service();
        $attributeRenderer = service(['allowedFunctions' => ['attribute']]);
        same('2', $renderer->renderString('{{ query.count() }}', ['query' => $query]));
        same('', $renderer->renderString('{{ query.count(sql) }}', ['query' => $query, 'sql' => '*']));
        same('', $attributeRenderer->renderString('{{ attribute(query, name, args) }}', ['query' => $query, 'name' => 'count', 'args' => ['*']]));
        same(['COUNT(*)'], $query->expressions);
    });
    check('permitted nullable Yii properties work without granting getter methods', function() {
        $data = new class extends yii\base\Model {
            public ?string $publicValue = null;
            public function getUrl(): ?string { return null; }
            public function getSecret(): ?string { throw new RuntimeException('Denied getter must not run.'); }
        };
        $renderer = service(['allowedProperties' => [$data::class => ['url', 'publicValue']]]);
        foreach (['{url}', '{{ url }}', '{{ object.url }}', '{{ object["url"] }}', '{{ object.publicValue }}'] as $source) {
            same('', $renderer->renderSandboxedObjectTemplate($source, $data));
        }
        same('fallback', $renderer->renderSandboxedObjectTemplate('{{ object.url ?? "fallback" }}', $data));
        same('fallback', $renderer->renderSandboxedObjectTemplate('{{ object.url|default("fallback") }}', $data));
        same('yes', $renderer->renderSandboxedObjectTemplate('{{ object.url is defined ? "yes" : "no" }}', $data));
        same('no', $renderer->renderSandboxedObjectTemplate('{{ object.secret is defined ? "yes" : "no" }}', $data));
        foreach (['{{ object.secret }}', '{{ object.getSecret() }}', '{{ object.getUrl() }}'] as $source) {
            raises(SecurityError::class, fn() => $renderer->renderSandboxedObjectTemplate($source, $data));
        }
        same('missing', $renderer->renderSandboxedString('{{ missing.child ?? "missing" }}'));
        same('two', $renderer->renderSandboxedString('{{ data[key] }}', ['data' => ['a' => 'two'], 'key' => 'a']));
    });
    check('baseline excludes ambient services, loading and callback helpers', function() use ($base) {
        foreach (["{{ svg('x') }}", "{{ dataUrl('x') }}", "{{ source('x') }}", "{{ include('x') }}", "{{ [1]|map('strval')|join }}", "{{ [1]|sort }}", "{{ [1]|column('secret') }}", "{% extends 'x' %}", "{% use 'x' %}", "{{ 1 is constant('PHP_INT_SIZE') }}"] as $source) {
            raises(Twig\Error\Error::class, fn() => $base->renderSandboxedString($source));
        }
        foreach (['craft', 'view', 'app', 'currentUser', 'currentSite'] as $name) {
            same('absent', $base->renderSandboxedString('{{ ' . $name . ' is defined ? "present" : "absent" }}'));
        }
        raises(SecurityError::class, fn() => service(['allowedTests' => []])->renderSandboxedString('{{ 1 is even }}'));
        same('yes', $base->renderSandboxedString('{{ 2 is even ? "yes" : "no" }}'));
        $object = new UntrustedValue();
        foreach (['{{ value }}', '{{ [value]|join }}'] as $source) {
            raises(SecurityError::class, fn() => $base->renderSandboxedString($source, ['value' => $object]));
        }
        $date = new DateTime('2026-01-01');
        same('2026', $base->renderSandboxedString('{{ date|date("Y") }}', ['date' => $date]));
        raises(SecurityError::class, fn() => $base->renderSandboxedString('{{ date.modify("+1 year") }}', ['date' => $date]));
        same('2026', $date->format('Y'));
    });
    check('dynamic mapping keys check __toString without removing permitted string conversion', function() {
        $probe = new class {
            public bool $converted = false;
            public function __toString(): string { $this->converted = true; return 'key'; }
        };
        $template = '{% set values = {(value): 42} %}{{ values.key }}';
        raises(SecurityError::class, fn() => service()->renderSandboxedString($template, ['value' => $probe]));
        same(false, $probe->converted);
        same('', service()->renderString($template, ['value' => $probe]));
        same(false, $probe->converted);
        $allowed = service(['allowedMethods' => [$probe::class => ['__toString']]]);
        same('42', $allowed->renderSandboxedString($template, ['value' => $probe]));
        same(true, $probe->converted);
        same('key', $allowed->renderSandboxedString('{{ value }}', ['value' => $probe]));
        same('42', service(['allowedMethods' => [$probe::class => ['__toString']]])->renderString($template, ['value' => $probe]));
        same('yes', service()->renderSandboxedString('{% set values = {(value): "yes"} %}{{ values["1.5"] }}', ['value' => 1.5]));
    });
    // Keep the unrelated upstream comparison limitation visible.
    $probe = new class {
        public bool $converted = false;
        public function __toString(): string { $this->converted = true; return 'sentinel'; }
    };
    try {
        $base->renderSandboxedString('{{ value in ["x"] }}', ['value' => $probe]);
    } catch (SecurityError $e) {
        same(false, $probe->converted);
    }
    echo ($probe->converted ? 'KNOWN UPSTREAM LIMITATION' : 'UPSTREAM LIMITATION NOT OBSERVED') . ': membership comparison' . "\n";
    check('inline escaping is configured separately from permissions', function() {
        $renderer = service(['sandboxedAutoescape' => false]);
        same('A&B', $renderer->renderSandboxedString('{{ title }}', ['title' => 'A&B']));
        same('A&amp;B', service()->renderSandboxedString('{{ title }}', ['title' => 'A&B']));
    });
    check('one sandbox policy serves both output escaping modes', function() use ($root) {
        $renderer = service();
        $vars = ['title' => 'A&B'];
        same('A&amp;B', $renderer->renderSandboxedString('{{ title }}', $vars));
        same('A&B', $renderer->renderSandboxedString('{{ title }}', $vars, autoescape: false));
        same('A&amp;B', $renderer->renderSandboxedString('{{ title }}', $vars));
        same('A&B', $renderer->renderSandboxedObjectTemplate('{{ title }}', $vars, autoescape: false));
        same('A&amp;B', $renderer->renderSandboxedObjectTemplate('{{ title }}', $vars));
        raises(SecurityError::class, fn() => $renderer->renderSandboxedString('{{ value.secret }}', ['value' => new UntrustedValue()], autoescape: false));
        file_put_contents($root . '/site/escape.twig', '{{ title }}');
        same('A&amp;B', $renderer->renderSandboxedTemplate('escape', $vars));
        same('A&B', $renderer->renderSandboxedTemplate('escape', $vars, autoescape: false));
        same('A&amp;B', $renderer->renderSandboxedTemplate('escape', $vars));
    });
    check('empty and reduced configuration is enforced per instance', function() use ($base) {
        $empty = service(['allowedTags' => [], 'allowedFilters' => [], 'allowedFunctions' => [], 'allowedClasses' => [], 'allowedMethods' => []]);
        raises(SecurityError::class, fn() => $empty->renderSandboxedString('{% if true %}yes{% endif %}'));
        raises(SecurityError::class, fn() => $empty->renderSandboxedString('{{ 12|number_format(2) }}'));
        raises(SecurityError::class, fn() => $empty->renderSandboxedString('{{ range(1, 2) }}'));
        raises(SecurityError::class, fn() => $empty->renderSandboxedString("{{ date.format('Y') }}", ['date' => new DateTimeImmutable('2026-01-01')]));
        same('2026', $base->renderSandboxedString("{{ date.format('Y') }}", ['date' => new DateTimeImmutable('2026-01-01')]));
        $limited = service(['allowedFilters' => array_values(array_diff($base->getDefaultSandboxedAllowedFilters(), ['raw']))]);
        raises(SecurityError::class, fn() => $limited->renderSandboxedString('{{ value|raw }}', ['value' => 'ok']));
        same('ok', $base->renderSandboxedString('{{ value|raw }}', ['value' => 'ok']));
    });
    check('collection callbacks and higher-order proxies are denied', function() use ($base) {
        $items = new craft\elements\ElementCollection(['a', 'b']);
        same('2:a', $base->renderSandboxedString('{{ items.count() }}:{{ items[0] }}', ['items' => $items]));
        foreach (['map', 'mapSpread', 'reduceSpread', 'eachSpread', 'flatMap', 'first', 'filter', 'mapInto'] as $method) {
            raises(SecurityError::class, fn() => $base->renderSandboxedString('{{ items.' . $method . '("strtoupper") }}', ['items' => $items]));
        }
        raises(SecurityError::class, fn() => $base->renderSandboxedString('{{ items.map }}', ['items' => $items]));
        raises(SecurityError::class, fn() => $base->renderSandboxedString('{{ items.each }}', ['items' => $items]));
        $policy = new SecurityPolicy();
        $policy->checkPropertyAllowed($items, '0');
        raises(SecurityError::class, fn() => $policy->checkPropertyAllowed($items, 'map'));
        $policy->setAllowedClasses([]);
        same($policy->getDefaultAllowedClasses(), $policy->getAllowedClasses());
        $policy->checkMethodAllowed($items, 'count');
    });
    check('legacy aliases keep output and logged empty-string failures', function() use ($base) {
        $errorCount = count(TestLog::$messages);
        same('Order 42', $base->renderObjectTemplate(' Order {number} ', new TestData()));
        same('<b>:<b>', $base->renderObjectTemplate('{number}:{{ number }}', new TestData(), ['number' => '<b>']));
        same(' 42 ', $base->renderString(' {{ number }} ', ['number' => 42]));
        same('<b>', $base->renderString('{{ value }}', ['value' => '<b>']));
        same('&lt;b&gt;', $base->renderString('{{ value }}', ['value' => '<b>'], true));
        same('<b>', $base->renderString('{{ value }}', ['value' => '<b>']));
        same('', $base->renderObjectTemplate("{{ getenv('PATH') }}", []));
        same('', $base->renderString("{{ getenv('PATH') }}"));
        same($errorCount + 2, count(TestLog::$messages));
    });
    check('legacy empty lists and additive classes remain compatible', function() {
        $legacy = service(['allowedTags' => [], 'allowedFilters' => [], 'allowedFunctions' => [], 'allowedClasses' => [UntrustedValue::class]]);
        same('12.00', $legacy->renderString('{{ 12|number_format(2) }}'));
        same('42.00', $legacy->renderObjectTemplate('{{ number|number_format(2) }}', new TestData()));
        raises(InvalidArgumentException::class, fn() => $legacy->renderSandboxedObjectTemplate('{{ number|number_format(2) }}', new TestData()));
        same('2026', $legacy->renderString("{{ date.format('Y') }}", ['date' => new DateTimeImmutable('2026-01-01')]));
        raises(InvalidArgumentException::class, fn() => $legacy->renderSandboxedString('{{ 12|number_format(2) }}'));
        raises(InvalidArgumentException::class, fn() => $legacy->getSandboxedTwig());
    });
    check('subclass policy customization still controls legacy rendering', function() {
        $prototype = new class extends Templates {
            public function init(): void {
                parent::init();
                $sandbox = $this->getTwig()->getExtension(SandboxExtension::class);
                $sandbox->getSecurityPolicy()->setAllowedFunctions([]);
            }
        };
        $wrapped = service([], $prototype::class);
        same('', $wrapped->renderString('{{ max(1, 2) }}'));
        same('2', $wrapped->renderSandboxedString('{{ max(1, 2) }}'));
    });
    check('plugins can allow specific methods and properties without sharing policy', function() {
        $value = new class {
            public string $title = 'Title';
            public function label(): string { return 'Label'; }
        };
        $custom = service(['allowedClasses' => [], 'allowedMethods' => [$value::class => ['label']], 'allowedProperties' => [$value::class => ['title']]]);
        same('Label:Title', $custom->renderSandboxedString('{{ value.label() }}:{{ value.title }}', ['value' => $value]));
        $other = service();
        raises(SecurityError::class, fn() => $other->renderSandboxedString('{{ value.label() }}', ['value' => $value]));
        raises(SecurityError::class, fn() => $other->renderSandboxedString('{{ value.title }}', ['value' => $value]));
    });
    check('registered extensions cannot replace the Base sandbox', function() use ($view) {
        $view->registerTwigExtension(new SandboxExtension(new Twig\Sandbox\SecurityPolicy(), false));
        $view->registerTwigExtension(new class extends Twig\Extension\AbstractExtension {
            public function getFunctions(): array { return [new Twig\TwigFunction('testLabel', fn() => 'registered')]; }
        });
        $custom = service(['allowedFunctions' => ['testLabel']]);
        raises(Twig\Error\SyntaxError::class, fn() => $custom->renderSandboxedString('{{ testLabel() }}'));
        same('registered', $custom->renderString('{{ testLabel() }}'));
        same(true, $custom->getSandboxedTwig()->getExtension(SandboxExtension::class)->isSandboxed());
        raises(Twig\Error\SyntaxError::class, fn() => service()->renderSandboxedString('{{ testLabel() }}'));
        same([], $custom->getSandboxedTwig()->getGlobals());
    });
    file_put_contents($root . '/site/main.twig', "Site {{ include('child.twig') }}");
    file_put_contents($root . '/site/child.twig', '{{ number }}');
    file_put_contents($root . '/cp/main.twig', 'CP {{ number }}');
    file_put_contents($root . '/site/denied.twig', "{{ include('bad.twig') }}");
    file_put_contents($root . '/site/bad.twig', "{{ getenv('PATH') }}");
    file_put_contents($root . '/cp/bad.twig', "{{ getenv('PATH') }}");
    file_put_contents($root . '/site/macros.twig', '{% macro value(number) %}{{ number }}{% endmacro %}');
    file_put_contents($root . '/site/import.twig', "{% import 'macros.twig' as m %}{{ m.value(number) }}");
    file_put_contents($root . '/site/text.txt', '{{ value }}');
    check('file rendering uses Craft resolution and keeps includes sandboxed', function() use ($base, $view) {
        $base = service(['allowedFunctions' => ['include']]);
        $loader = $base->getSandboxedTwig()->getLoader();
        same('Site 42', $base->renderSandboxedTemplate('main', ['number' => 42]));
        same('CP 42', $base->renderSandboxedTemplate('main', ['number' => 42], View::TEMPLATE_MODE_CP));
        same('Site 43', $base->renderSandboxedTemplate('main', ['number' => 43]));
        same('<b>', $base->renderSandboxedTemplate('text.txt', ['value' => '<b>']));
        raises(Twig\Error\SyntaxError::class, fn() => $base->renderSandboxedTemplate('denied'));
        raises(Twig\Error\SyntaxError::class, fn() => $base->renderSandboxedTemplate('bad', [], View::TEMPLATE_MODE_CP));
        same(View::TEMPLATE_MODE_SITE, $view->getTemplateMode());
        same($loader, $base->getSandboxedTwig()->getLoader());
        raises(LoaderError::class, fn() => $base->renderSandboxedString("{{ include('child.twig') }}"));
    });
    check('file imports require permission and use the same policy', function() use ($base) {
        raises(SecurityError::class, fn() => $base->renderSandboxedTemplate('import', ['number' => 42]));
        $macros = service(['allowedTags' => array_merge($base->getDefaultAllowedTags(), ['import', 'macro'])]);
        same('42', $macros->renderSandboxedTemplate('import', ['number' => 42]));
        raises(SecurityError::class, fn() => $base->renderSandboxedTemplate('import', ['number' => 42]));
        raises(SecurityError::class, fn() => $macros->renderSandboxedTemplate('denied'));
    });

    check('proxyField dispatch treats the type as data', function() {
        $twig = new Twig\Environment(new Twig\Loader\ArrayLoader());
        $twig->addExtension(new verbb\base\twigextensions\Extension());
        $twig->addFilter(new Twig\TwigFilter('t', fn($text, $category, $variables) => strtr($text, ['{label}' => $variables['label']])));
        $source = file_get_contents(dirname(__DIR__) . '/src/templates/_macros/index.html');
        preg_match_all('/forms\.(\w+)\(config\)/', $source, $matches);
        $forms = '';
        foreach ($matches[1] as $name) {
            $forms .= '{% macro ' . $name . '(config) %}' . $name . ':{{ config.instructions }}{% endmacro %}';
        }
        $twig->setLoader(new Twig\Loader\ArrayLoader(['_includes/forms' => $forms, 'base' => $source, 'test' => "{% import 'base' as b %}{{ b.proxyField({plugin: 'test'}, type, {label: 'Name', instructions: 'Enter {label}'}) }}"]));
        foreach (['textField', 'selectField', 'autosuggestField', 'booleanMenuField', 'checkboxSelectField', 'editableTableField', 'lightswitchField'] as $type) {
            same($type . ':Enter Name', trim($twig->render('test', ['type' => $type])));
        }
        raises(Twig\Error\RuntimeError::class, fn() => $twig->render('test', ['type' => "textField(config) }}INJECTED{{ forms.textField"]));
        raises(Twig\Error\RuntimeError::class, fn() => $twig->render('test', ['type' => 'unknown']));
    });

    echo "\n$passed checks passed (PHP " . PHP_VERSION . ', Twig ' . Twig\Environment::VERSION . ").\n";
} finally {
    foreach (glob($root . '/*/*') as $file) { unlink($file); }
    rmdir($root . '/site');
    rmdir($root . '/cp');
    rmdir($root);
    Craft::$app = null;
}
