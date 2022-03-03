<?php
namespace verbb\base\base;

use Craft;
use craft\events\RegisterTemplateRootsEvent;
use craft\helpers\ArrayHelper;
use craft\i18n\PhpMessageSource;
use craft\web\View;

use yii\base\Event;
use yii\base\Module as YiiModule;

class Module extends YiiModule
{
    // Properties
    // =========================================================================

    public $t9nCategory;
    public string $sourceLanguage = 'en-AU';


    // Public Methods
    // =========================================================================

    public function __construct($id, $parent = null, array $config = [])
    {
        $this->t9nCategory = ArrayHelper::remove($config, 't9nCategory', $this->t9nCategory ?? $id);
        $this->sourceLanguage = ArrayHelper::remove($config, 'sourceLanguage', $this->sourceLanguage);

        if (($basePath = ArrayHelper::remove($config, 'basePath')) !== null) {
            $this->setBasePath($basePath);
        }

        // Translation category
        $i18n = Craft::$app->getI18n();
        if (!isset($i18n->translations[$this->t9nCategory]) && !isset($i18n->translations[$this->t9nCategory . '*'])) {
            $i18n->translations[$this->t9nCategory] = [
                'class' => PhpMessageSource::class,
                'sourceLanguage' => $this->sourceLanguage,
                'basePath' => $this->getBasePath() . DIRECTORY_SEPARATOR . 'translations',
                'forceTranslation' => true,
                'allowOverrides' => true,
            ];
        }

        // Base template directory
        Event::on(View::class, View::EVENT_REGISTER_CP_TEMPLATE_ROOTS, function(RegisterTemplateRootsEvent $e) {
            if (is_dir($baseDir = $this->getBasePath() . DIRECTORY_SEPARATOR . 'templates')) {
                $e->roots[$this->id] = $baseDir;
            }
        });

        // Set this as the global instance of this plugin class
        static::setInstance($this);

        // Set the default controller namespace
        if ($this->controllerNamespace === null && ($pos = strrpos(static::class, '\\')) !== false) {
            $namespace = substr(static::class, 0, $pos);

            if (Craft::$app->getRequest()->getIsConsoleRequest()) {
                $this->controllerNamespace = $namespace . '\\console\\controllers';
            } else {
                $this->controllerNamespace = $namespace . '\\controllers';
            }

            // Define a custom alias named after the namespace
            Craft::setAlias('@' . str_replace('\\', '/', $namespace), $this->getBasePath());
        }

        parent::__construct($id, $parent, $config);
    }

}