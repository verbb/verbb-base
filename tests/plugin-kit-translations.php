<?php

use craft\i18n\I18N;
use craft\i18n\PhpMessageSource;
use craft\web\View;
use verbb\base\helpers\PluginKit;

// Runs Craft's real translator and `View::registerTranslations()` against a minimal application, without a database.
$vendorPath = getenv('VERBB_BASE_CRAFT_VENDOR') ?: dirname(__DIR__) . '/vendor';

if (!is_file($vendorPath . '/autoload.php')) {
    throw new RuntimeException('Set VERBB_BASE_CRAFT_VENDOR to a Craft 5 vendor directory.');
}

$loader = require $vendorPath . '/autoload.php';
$loader->addPsr4('verbb\\base\\', dirname(__DIR__) . '/src', true);
require $vendorPath . '/yiisoft/yii2/Yii.php';
require $vendorPath . '/craftcms/cms/src/Craft.php';

// A site translations folder with a German override, as a Craft project would have.
$siteTranslations = sys_get_temp_dir() . '/verbb-base-plugin-kit-' . getmypid();
@mkdir($siteTranslations . '/de', 0777, true);
file_put_contents($siteTranslations . '/de/plugin-kit.php', "<?php\n\nreturn [\n    'Clear search' => 'Suche löschen',\n    '{label} pagination' => 'Seitennavigation für {label}',\n];\n");
Craft::setAlias('@translations', $siteTranslations);
register_shutdown_function(function() use ($siteTranslations) {
    @unlink($siteTranslations . '/de/plugin-kit.php');
    @rmdir($siteTranslations . '/de');
    @rmdir($siteTranslations);
});

final class PluginKitFixtureApp extends yii\base\Component
{
    public string $language = 'en-US';
    public string $sourceLanguage = 'en-US';

    private I18N $_i18n;

    public function initialize(): void
    {
        $this->_i18n = new I18N();
    }

    public function getConfig(): object
    {
        return new class() {
            public function getGeneral(): object
            {
                return (object)['devMode' => false, 'translationDebugOutput' => false];
            }
        };
    }

    public function getI18n(): I18N
    {
        return $this->_i18n;
    }
}

// Skips Craft's request-dependent setup. Only script registration is under test.
final class PluginKitFixtureView extends View
{
    public function init(): void
    {
    }

    public function registeredJs(): string
    {
        return implode("\n", $this->js[self::POS_BEGIN] ?? []);
    }
}

function fixtureAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function fixtureApp(string $language): PluginKitFixtureApp
{
    $app = new PluginKitFixtureApp();
    $app->language = $language;
    Craft::$app = $app;
    $app->initialize();

    return $app;
}

// The English file is the generated source list: every key is its own translation.
$english = require dirname(__DIR__) . '/src/translations/en/plugin-kit.php';
fixtureAssert($english !== [] && array_keys($english) === array_values($english), 'Every English Plugin Kit string must map to itself.');
fixtureAssert(PluginKit::translationMessages() === array_keys($english), 'The registered messages must be the English file\'s keys.');
fixtureAssert(in_array('Clear search', PluginKit::translationMessages(), true), 'The data table labels must be included.');

// The category is registered once, and a site's own configuration is kept.
$app = fixtureApp('en-US');
PluginKit::registerTranslationSource();
$source = $app->getI18n()->translations['plugin-kit'];
fixtureAssert($source['class'] === PhpMessageSource::class && $source['allowOverrides'] === true && $source['sourceLanguage'] === 'en-US', 'The category must allow site overrides of US English strings.');
fixtureAssert(realpath($source['basePath']) === realpath(dirname(__DIR__) . '/src/translations'), 'The category must read Verbb Base\'s translations.');

$app = fixtureApp('en-US');
$app->getI18n()->translations['plugin-kit'] = ['class' => PhpMessageSource::class, 'basePath' => '/site/configured'];
PluginKit::registerTranslationSource();
fixtureAssert($app->getI18n()->translations['plugin-kit']['basePath'] === '/site/configured', 'A site-configured category must not be replaced.');

// A German control panel gets the site's translations, and only the strings that differ from English.
fixtureApp('de');
PluginKit::registerTranslationSource();
fixtureAssert(Craft::t('plugin-kit', 'Clear search') === 'Suche löschen', 'Site translations must override the English source.');
fixtureAssert(Craft::t('plugin-kit', 'Filters') === 'Filters', 'Untranslated strings must fall back to English.');
$view = new PluginKitFixtureView();
PluginKit::registerTranslations($view);
PluginKit::registerTranslations($view);
$js = $view->registeredJs();
fixtureAssert(str_contains($js, 'Craft.translations["plugin-kit"]["Clear search"] = "Suche löschen";'), 'Translated strings must reach Craft.t().');
fixtureAssert(str_contains($js, 'Craft.translations["plugin-kit"]["{label} pagination"] = "Seitennavigation für {label}";'), 'Placeholders must be kept for Plugin Kit to fill.');
fixtureAssert(!str_contains($js, '["Filters"]'), 'Untranslated strings must be left to Plugin Kit\'s English defaults.');
fixtureAssert(substr_count($js, '["Clear search"]') === 1, 'Registering twice on one page must not repeat the script.');

// An English control panel needs no script at all.
fixtureApp('en-US');
$view = new PluginKitFixtureView();
PluginKit::registerTranslations($view);
fixtureAssert($view->registeredJs() === '', 'An English control panel must not register Plugin Kit translations.');

echo "Plugin Kit translations fixture passed.\n";
