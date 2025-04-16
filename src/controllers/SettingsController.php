<?php
namespace verbb\base\controllers;

use Craft;
use craft\web\Controller;

use yii\web\Response;

class SettingsController extends Controller
{
    // Public Methods
    // =========================================================================

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

        $settings = $plugin->getSettings();
        $settings->setAttributes($this->request->getParam('settings', []), false);

        if (!$settings->validate()) {
            $this->setFailFlash(Craft::t($pluginHandle, 'Couldn’t save settings.'));

            Craft::$app->getUrlManager()->setRouteParams([
                'settings' => $settings,
            ]);

            return null;
        }

        $pluginSettingsSaved = Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray());

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
