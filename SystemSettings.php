<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\QueuedTracking;

use Piwik\Cache;
use Piwik\Config;
use Piwik\Plugins\QueuedTracking\Settings\NumWorkers;
use Piwik\Settings\Setting;
use Piwik\Settings\FieldConfig;
use Piwik\Settings\Storage\Backend;
use Piwik\Plugins\QueuedTracking\Queue\Factory;
use Piwik\Piwik;
use Exception;

/**
 * Defines Settings for QueuedTracking.
 */
class SystemSettings extends \Piwik\Settings\Plugin\SystemSettings
{
    /** @var Setting */
    public $redisHost;

    /** @var Setting */
    public $redisTimeout;

    /** @var Setting */
    public $redisDatabase;

    /** @var Setting */
    public $redisPassword;

    /** @var Setting */
    public $queueEnabled;

    /** @var NumWorkers */
    public $numQueueWorkers;

    /** @var Setting */
    public $processDuringTrackingRequest;

    /** @var Setting */
    public $numRequestsToProcess;

    protected function init()
    {
        $this->redisHost = $this->createRedisHostSetting();
        $this->redisTimeout = $this->createRedisTimeoutSetting();
        $this->redisDatabase = $this->createRedisDatabaseSetting();
        $this->redisPassword = $this->createRedisPasswordSetting();
        $this->queueEnabled = $this->createQueueEnabledSetting();
        $this->numQueueWorkers = $this->createNumberOfQueueWorkerSetting();
        $this->numRequestsToProcess = $this->createNumRequestsToProcessSetting();
        $this->processDuringTrackingRequest = $this->createProcessInTrackingRequestSetting();
    }

    public function isUsingUnixSocket()
    {
        return substr($this->redisHost->getValue(), 0, 1) === '/';
    }

    private function createRedisHostSetting()
    {
        $self = $this;

        return $this->makeSetting('redisHost', $default = '127.0.0.1', FieldConfig::TYPE_STRING, function (FieldConfig $field) use ($self) {
            $field->title = 'Redis host or unix socket';
            $field->uiControl = FieldConfig::UI_CONTROL_TEXT;
            $field->uiControlAttributes = array('size' => 500);
            $field->inlineHelp = 'Remote host or unix socket of the Redis server. Max 500 characters are allowed.';

            $field->validate = function ($value) use ($self) {
                if (strlen($value) > 500) {
                    throw new \Exception('Max 500 characters allowed');
                }
            };

            $field->transform = function ($value) use ($self) {
                $hosts = $self->convertCommaSeparatedValueToArray($value);

                return implode(',', $hosts);
            };
        });
    }

    private function createRedisTimeoutSetting()
    {
        $setting = $this->makeSetting('redisTimeout', $default = 0.0, FieldConfig::TYPE_FLOAT, function (FieldConfig $field) {
            $field->title = 'Redis timeout';
            $field->uiControl = FieldConfig::UI_CONTROL_TEXT;
            $field->uiControlAttributes = array('size' => 5);
            $field->inlineHelp = 'Redis connection timeout in seconds. "0.0" meaning unlimited. Can be a float eg "2.5" for a connection timeout of 2.5 seconds.';
            $field->validate = function ($value) {

                if (!is_numeric($value)) {
                    throw new \Exception('Timeout should be numeric, eg "0.1"');
                }

                if (strlen($value) > 5) {
                    throw new \Exception('Max 5 characters allowed');
                }
            };
        });

        // we do not expose this one to the UI currently. That's on purpose
        $setting->setIsWritableByCurrentUser(false);

        return $setting;
    }

    private function createNumberOfQueueWorkerSetting()
    {
        $numQueueWorkers = new NumWorkers('numQueueWorkers', $default = 1, FieldConfig::TYPE_INT, $this->pluginName);
        $numQueueWorkers->setConfigureCallback(function (FieldConfig $field) {
            $field->title = 'Number of queue workers';
            $field->uiControl = FieldConfig::UI_CONTROL_TEXT;
            $field->uiControlAttributes = array('size' => 5);
            $field->inlineHelp = 'Number of allowed maximum queue workers. Accepts a number between 1 and 16. Best practice is to set the number of CPUs you want to make available for queue processing. Be aware you need to make sure to start the workers manually. We recommend to not use 9-15 workers, rather use 8 or 16 as the queue might not be distributed evenly into different queues. DO NOT USE more than 1 worker if you make use the UserId feature when tracking see https://github.com/piwik/piwik/issues/7691';
            $field->validate = function ($value) {

                if (!is_numeric($value)) {
                    throw new \Exception('Number of queue workers should be an integer, eg "6"');
                }

                $value = (int) $value;
                if ($value > 16 || $value < 1) {
                    throw new \Exception('Only 1-16 workers allowed');
                }
            };
        });

        $this->addSetting($numQueueWorkers);

        return $numQueueWorkers;
    }

    private function createRedisPasswordSetting()
    {
        return $this->makeSetting('redisPassword', $default = '', FieldConfig::TYPE_STRING, function (FieldConfig $field) {
            $field->title = 'Redis password';
            $field->uiControl = FieldConfig::UI_CONTROL_PASSWORD;
            $field->uiControlAttributes = array('size' => 100);
            $field->inlineHelp = 'Password set on the Redis server, if any. Redis can be instructed to require a password before allowing clients to execute commands.';
            $field->validate = function ($value) {
                if (strlen($value) > 100) {
                    throw new \Exception('Max 100 characters allowed');
                }
            };
        });
    }

    private function createRedisDatabaseSetting()
    {
        return $this->makeSetting('redisDatabase', $default = 0, FieldConfig::TYPE_INT, function (FieldConfig $field) {
            $field->title = 'Redis database';
            $field->uiControl = FieldConfig::UI_CONTROL_TEXT;
            $field->uiControlAttributes = array('size' => 5);
            $field->inlineHelp = 'In case you are using Redis for caching make sure to use a different database.';
            $field->validate = function ($value) {
                if (!is_numeric($value) || false !== strpos($value, '.')) {
                    throw new \Exception('The database has to be an integer');
                }

                if (strlen($value) > 5) {
                    throw new \Exception('Max 5 digits allowed');
                }
            };
        });
    }

    private function createQueueEnabledSetting()
    {
        $self = $this;

        return $this->makeSetting('queueEnabled', $default = false, FieldConfig::TYPE_BOOL, function (FieldConfig $field) use ($self) {
            $field->title = 'Queue enabled';
            $field->uiControl = FieldConfig::UI_CONTROL_CHECKBOX;
            $field->inlineHelp = 'If enabled, all tracking requests will be written into a queue instead of the directly into the database. Requires a Redis server and phpredis PHP extension.';
            /*$field->validate = function ($value) use ($self) {
                $value = (bool) $value;

                if ($value) {
                    $systemCheck = new SystemCheck();

                    $systemCheck->checkRedisIsInstalled();
                    $backend = Factory::makeBackendFromSettings($self);

                    $systemCheck->checkConnectionDetails($backend);
                }
            };*/
        });
    }

    private function createNumRequestsToProcessSetting()
    {
        return $this->makeSetting('numRequestsToProcess', $default = 25, FieldConfig::TYPE_INT, function (FieldConfig $field) {
            $field->title = 'Number of requests that are processed in one batch';
            $field->uiControl = FieldConfig::UI_CONTROL_TEXT;
            $field->uiControlAttributes = array('size' => 3);
            $field->inlineHelp = 'Defines how many requests will be picked out of the queue and processed at once. Enter a number which is >= 1.';
            $field->validate = function ($value, $setting) {

                if (!is_numeric($value)) {
                    throw new \Exception('Value should be a number');
                }

                if ((int) $value < 1) {
                    throw new \Exception('Number should be 1 or higher');
                }
            };
        });
    }

    private function createProcessInTrackingRequestSetting()
    {
        return $this->makeSetting('processDuringTrackingRequest', $default = true, FieldConfig::TYPE_BOOL, function (FieldConfig $field) {
            $field->title = 'Process during tracking request';
            $field->uiControl = FieldConfig::UI_CONTROL_CHECKBOX;
            $field->inlineHelp = 'If enabled, we will process all requests within a queue during a normal tracking request once there are enough requests in the queue. This will not slow down the tracking request. If disabled, you have to setup a cronjob that executes the "./console queuedtracking:process" console command eg every minute to process the queue.';
        });
    }

    public function convertCommaSeparatedValueToArray($value)
    {
        if ($value === '' || $value === false || $value === null) {
            return array();
        }

        $values = explode(',', $value);
        $values = array_map('trim', $values);

        return $values;
    }

    public function save()
    {
        parent::save();

        $oldNumWorkers = $this->numQueueWorkers->getOldValue();
        $newNumWorkers = $this->numQueueWorkers->getValue();

        if ($newNumWorkers && $oldNumWorkers) {
            try {
                $manager = Factory::makeQueueManager(Factory::makeBackend());
                $manager->setNumberOfAvailableQueues($newNumWorkers);
                $manager->moveSomeQueuesIfNeeded($newNumWorkers, $oldNumWorkers);
            } catch (\Exception $e) {
                // it is ok if this fails. then it is most likely not enabled etc.
            }
        }
    }

}
