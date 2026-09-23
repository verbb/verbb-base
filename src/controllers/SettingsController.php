<?php
namespace verbb\base\controllers;

use Craft;
use craft\web\Controller;

use yii\web\Response;

class SettingsController extends Controller
{
    // Public Methods
    // =========================================================================

    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireAdmin();

        return true;
    }

    public function actionSaveSettings(): ?Response
    {
        $this->requirePostRequest();

        $pluginHandle = $this->request->getParam('pluginHandle');

        if (!$pluginHandle) {
            $this->setFailFlash(Craft::t('app', 'Invalid plugin handle.'));

            return null;
        }

        $plugin = Craft::$app->getPlugins()->getPlugin($pluginHandle);

        if (!$plugin) {
            $this->setFailFlash(Craft::t('app', 'Invalid plugin.'));

            return null;
        }

        $submittedSettings = $this->request->getParam('settings', []);
        $settings = $plugin->getSettings();
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
}
