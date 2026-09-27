<?php
namespace verbb\base\controllers;

use Craft;
use craft\base\PluginInterface;
use craft\web\Controller;

use yii\web\Response;
use yii\web\ServerErrorHttpException;

class SettingsController extends Controller
{
    // Public Methods
    // =========================================================================

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requireAdmin();

        return true;
    }

    public function actionSaveSettings(): ?Response
    {
        $this->requirePostRequest();

        $plugin = $this->_plugin();
        $pluginHandle = $plugin->getHandle();
        $submittedSettings = $this->prepareSubmittedSettings($this->request->getBodyParam('settings', []));
        $settings = $plugin->getSettings();

        if ($settings === null) {
            throw new ServerErrorHttpException('The plugin does not define settings.');
        }

        $settings->setAttributes($submittedSettings, false);

        if (!$settings->validate()) {
            $this->setFailFlash(Craft::t($pluginHandle, 'Couldn’t save settings.'));

            Craft::$app->getUrlManager()->setRouteParams([
                'settings' => $settings,
            ]);

            return null;
        }

        // Settings pages can submit one section at a time, so retain stored values omitted from the request.
        $storedSettings = Craft::$app->getPlugins()->getStoredPluginInfo($pluginHandle)['settings'] ?? [];
        $pluginSettingsSaved = Craft::$app->getPlugins()->savePluginSettings($plugin, array_replace($storedSettings, $submittedSettings));

        if (!$pluginSettingsSaved) {
            $this->setFailFlash(Craft::t($pluginHandle, 'Couldn’t save settings.'));

            Craft::$app->getUrlManager()->setRouteParams([
                'settings' => $settings,
            ]);

            return null;
        }

        $this->setSuccessFlash(Craft::t($pluginHandle, 'Settings saved.'));

        return $this->redirectToPostedUrl();
    }


    // Protected Methods
    // =========================================================================

    protected function prepareSubmittedSettings(array $settings): array
    {
        return $settings;
    }


    // Private Methods
    // =========================================================================

    private function _plugin(): PluginInterface
    {
        if (!$this->module instanceof PluginInterface) {
            throw new ServerErrorHttpException('Settings controllers must belong to a Craft plugin.');
        }

        return $this->module;
    }
}
