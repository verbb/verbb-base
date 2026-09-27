<?php

use craft\base\Model;
use craft\base\Plugin;
use yii\base\Action;
use yii\i18n\I18N;
use yii\web\HttpException;
use yii\web\Response;

$vendorPath = getenv('VERBB_BASE_CRAFT_VENDOR') ?: dirname(__DIR__) . '/vendor';

if (!is_file($vendorPath . '/autoload.php')) {
    throw new RuntimeException('Set VERBB_BASE_CRAFT_VENDOR to a Craft 5 vendor directory.');
}

require $vendorPath . '/autoload.php';
require $vendorPath . '/yiisoft/yii2/Yii.php';
require $vendorPath . '/craftcms/cms/src/Craft.php';
require dirname(__DIR__) . '/src/controllers/SettingsController.php';

Craft::setAlias('@translations', sys_get_temp_dir());

final class SettingsControllerFixtureRequest extends craft\web\Request
{
    public bool $cp = true;
    public bool $csrfValid = true;
    public bool $post = true;

    public function init(): void
    {
    }

    public function getIsConsoleRequest(): bool
    {
        return false;
    }

    public function getIsCpRequest(): bool
    {
        return $this->cp;
    }

    public function getIsPost(): bool
    {
        return $this->post;
    }

    public function getIsLivePreview(): bool
    {
        return false;
    }

    public function hasValidSiteToken(): bool
    {
        return false;
    }

    public function validateCsrfToken($clientSuppliedToken = null): bool
    {
        return $this->csrfValid;
    }

    public function getValidatedBodyParam(string $name): ?string
    {
        $value = $this->getBodyParam($name);

        return is_string($value) ? $value : null;
    }

    public function getPathInfo(bool $returnRealPathInfo = false): string
    {
        return 'fixture/settings';
    }
}

final class SettingsControllerFixtureSession
{
    public bool $admin = true;
    public bool $guest = false;
    public ?string $success = null;
    public ?string $error = null;

    public function getIsAdmin(): bool
    {
        return $this->admin;
    }

    public function getIsGuest(): bool
    {
        return $this->guest;
    }

    public function checkPermission(string $permission): bool
    {
        return $this->admin;
    }

    public function loginRequired(): void
    {
        throw new yii\web\ForbiddenHttpException('Fixture guest denied.');
    }

    public function setSuccess(string $message, array $settings = []): void
    {
        $this->success = $message;
    }

    public function setError(string $message, array $settings = []): void
    {
        $this->error = $message;
    }
}

final class SettingsControllerFixturePlugins
{
    public ?craft\base\PluginInterface $savedPlugin = null;
    public array $savedSettings = [];

    public function getStoredPluginInfo(string $handle): ?array
    {
        return [
            'settings' => [
                'changed' => 'old',
                'providers' => [
                    'google' => ['clientId' => 'stored-client'],
                ],
                'retained' => 'keep',
            ],
        ];
    }

    public function savePluginSettings(craft\base\PluginInterface $plugin, array $settings): bool
    {
        $this->savedPlugin = $plugin;
        $this->savedSettings = $settings;
        $plugin->setSettings($settings);

        return true;
    }
}

final class SettingsControllerFixtureApp extends yii\base\Component
{
    public bool $allowAdminChanges = true;
    public string $charset = 'UTF-8';
    public string $language = 'en';
    public array $loadedModules = [];
    public string $sourceLanguage = 'en';
    public SettingsControllerFixturePlugins $plugins;
    public SettingsControllerFixtureRequest $request;
    public Response $response;
    public SettingsControllerFixtureSession $session;

    private I18N $_i18n;

    public function __construct()
    {
        $this->plugins = new SettingsControllerFixturePlugins();
        $this->request = new SettingsControllerFixtureRequest();
        $this->session = new SettingsControllerFixtureSession();

        parent::__construct();
    }

    public function initialize(): void
    {
        $this->_i18n = new I18N();
        $this->response = new Response();
    }

    public function getConfig(): object
    {
        return new class($this->allowAdminChanges) {
            public function __construct(private bool $allowAdminChanges)
            {
            }

            public function getGeneral(): object
            {
                return (object)['allowAdminChanges' => $this->allowAdminChanges];
            }
        };
    }

    public function getErrorHandler(): object
    {
        return (object)['exception' => null];
    }

    public function getI18n(): I18N
    {
        return $this->_i18n;
    }

    public function getIsLive(): bool
    {
        return true;
    }

    public function getPlugins(): SettingsControllerFixturePlugins
    {
        return $this->plugins;
    }

    public function getRequest(): SettingsControllerFixtureRequest
    {
        return $this->request;
    }

    public function getResponse(): Response
    {
        return $this->response;
    }

    public function getSession(): SettingsControllerFixtureSession
    {
        return $this->session;
    }

    public function getUser(): SettingsControllerFixtureSession
    {
        return $this->session;
    }
}

final class SettingsControllerFixtureModel extends Model
{
    public string $changed = '';
    public array $providers = [];
    public string $retained = '';
}

final class SettingsControllerFixturePlugin extends Plugin
{
    protected function createSettingsModel(): SettingsControllerFixtureModel
    {
        return new SettingsControllerFixtureModel([
            'changed' => 'old',
            'providers' => [
                'google' => ['clientId' => 'stored-client'],
            ],
            'retained' => 'keep',
        ]);
    }
}

final class SettingsControllerFixturePreparingController extends verbb\base\controllers\SettingsController
{
    protected function prepareSubmittedSettings(array $settings): array
    {
        $settings['changed'] = strtoupper($settings['changed']);

        return $settings;
    }
}

function fixtureAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function fixtureApp(): SettingsControllerFixtureApp
{
    $app = new SettingsControllerFixtureApp();
    Craft::$app = $app;
    $app->initialize();

    return $app;
}

function dispatchSettingsController(bool $admin, bool $allowAdminChanges, bool $cp = true, bool $csrfValid = true): string
{
    $app = fixtureApp();
    $app->allowAdminChanges = $allowAdminChanges;
    $app->request->cp = $cp;
    $app->request->csrfValid = $csrfValid;
    $app->session->admin = $admin;
    $plugin = new SettingsControllerFixturePlugin('fixture-plugin');
    $controller = new verbb\base\controllers\SettingsController('settings', $plugin, [
        'request' => $app->request,
        'response' => $app->response,
    ]);

    try {
        return $controller->beforeAction(new Action('save-settings', $controller)) ? 'reachable' : 'stopped';
    } catch (HttpException $exception) {
        return (string)$exception->statusCode;
    }
}

fixtureAssert(dispatchSettingsController(false, true) === '403', 'A lower-privilege CP user must not save settings.');
fixtureAssert(dispatchSettingsController(true, false) === '403', 'A read-only administrator must not save settings.');
fixtureAssert(dispatchSettingsController(true, true, false) === '400', 'Settings saves must require a control panel request.');
fixtureAssert(dispatchSettingsController(true, true, true, false) === '400', 'Settings saves must require a valid CSRF token.');
fixtureAssert(dispatchSettingsController(true, true) === 'reachable', 'A writable administrator may save settings.');

$app = fixtureApp();
$app->request->post = false;
$plugin = new SettingsControllerFixturePlugin('fixture-plugin');
$controller = new verbb\base\controllers\SettingsController('settings', $plugin, [
    'request' => $app->request,
    'response' => $app->response,
]);

try {
    $controller->actionSaveSettings();
    throw new RuntimeException('Settings saves must require POST.');
} catch (yii\web\MethodNotAllowedHttpException) {
}

$app = fixtureApp();
$plugin = new SettingsControllerFixturePlugin('fixture-plugin');
$controller = new SettingsControllerFixturePreparingController('settings', $plugin, [
    'request' => $app->request,
    'response' => $app->response,
]);
$app->request->setBodyParams([
    'pluginHandle' => 'foreign-plugin',
    'settings' => [
        'changed' => 'new',
    ],
]);

$controller->actionSaveSettings();

fixtureAssert($app->plugins->savedPlugin === $plugin, 'The controller must save its owning plugin.');
fixtureAssert($app->plugins->savedSettings === [
    'changed' => 'NEW',
    'providers' => [
        'google' => ['clientId' => 'stored-client'],
    ],
    'retained' => 'keep',
], 'Partial settings must retain omitted stored values.');
fixtureAssert($plugin->getSettings()->changed === 'NEW', 'Plugin-specific settings preparation must run before saving.');
fixtureAssert($plugin->getSettings()->providers === ['google' => ['clientId' => 'stored-client']], 'Omitted provider settings must remain intact.');
fixtureAssert($plugin->getSettings()->retained === 'keep', 'Omitted settings must remain intact.');

echo "Settings controller fixture passed.\n";
