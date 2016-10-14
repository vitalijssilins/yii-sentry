<?php
/**
 * SentryClient class file.
 * @author Christoffer Niska <christoffer.niska@gmail.com>
 * @copyright Copyright &copy; Christoffer Niska 2013-
 * @license http://www.opensource.org/licenses/bsd-license.php New BSD License
 * @package crisu83.yii-sentry.components
 */

/**
 * Application component that allows to communicate with Sentry.
 *
 * Methods accessible through the 'ComponentBehavior' class:
 * @method createPathAlias($alias, $path)
 * @method import($alias)
 * @method string publishAssets($path, $forceCopy = false)
 * @method void registerCssFile($url, $media = '')
 * @method void registerScriptFile($url, $position = null)
 * @method string resolveScriptVersion($filename, $minified = false)
 * @method CClientScript getClientScript()
 * @method void registerDependencies($dependencies)
 * @method string resolveDependencyPath($name)
 */
class SentryClient extends CApplicationComponent
{
    // Sentry constants.
    const MAX_MESSAGE_LENGTH = 999999999;
    const MAX_TAG_KEY_LENGTH = 32;
    const MAX_TAG_VALUE_LENGTH = 200;
    const MAX_CULPRIT_LENGTH = 200;

    /**
     * @var string dns to use when connecting to Sentry.
     */
    public $dns;

    /**
     * @var string name of the active environment.
     */
    public $environment = 'dev';

    /**
     * @var array list of names for environments in which data will be sent to Sentry.
     */
    public $enabledEnvironments = array('production', 'staging');

    /**
     * @var array options to pass to the Raven client with the following structure:
     *   logger: (string) name of the logger
     *   auto_log_stacks: (bool) whether to automatically log stacktraces
     *   name: (string) name of the server
     *   site: (string) name of the installation
     *   tags: (array) key/value pairs that describe the event
     *   trace: (bool) whether to send stacktraces
     *   timeout: (int) timeout when connecting to Sentry (in seconds)
     *   exclude: (array) class names of exceptions to exclude
     *   shift_vars: (bool) whether to shift variables when creating a backtrace
     *   processors: (array) list of data processors
     */
    public $options = array();

    /**
     * @var array extra variables to send with exceptions to Sentry.
     */
    public $extraVariables = array();

    /**
     * Holds all event ids that were reported during this request
     */
    protected $_loggedEventIds = array();

    /**
     * @param mixed $loggedEventIds
     */
    public function setLoggedEventIds($loggedEventIds)
    {
        $this->_loggedEventIds = $loggedEventIds;
    }

    /**
     * @return mixed
     */
    public function getLoggedEventIds()
    {
        return $this->_loggedEventIds;
    }

    /** @var Raven_Client */
    private $_client;

    /**
     * Initializes the error handler.
     */
    public function init()
    {
        parent::init();
        $this->_client = $this->createClient();
    }
  
  /**
   * Records a breadcrumb to Sentry
   *
   * @param        $message string describing the event. The most common vector, often used as a drop-in for a traditional log message.
   * @param array  $data mapping (str => str) of metadata around the event. This is often used instead of message, but may also be used in addition.
   * @param string $category category to label the event under. This generally is similar to a logger name, and will let you more easily understand the area an event took place, such as auth.
   * @param string $level the level may be any of error, warn, info, or debug.
   *
   * @return bool
   * @throws CException
   */
    public function recordBreadcrumb($message, $data = [], $category = '', $level = Raven_Client::INFO) {
      try {
        $this->_client->breadcrumbs->record(
          [
            'message'  => $message,
            'data'     => $data,
            'category' => $category,
            'level'    => $level,
          ]
        );
      } catch (Exception $e) {
        if (YII_DEBUG) {
          throw new CException('SentryClient failed to log breadcrumb: ' . $e->getMessage(), (int)$e->getCode());
        } else {
          $this->log($e->getMessage(), CLogger::LEVEL_ERROR);
          throw new CException('SentryClient failed to log breadcrumb.', (int)$e->getCode());
        }
      }
      
      return true;
    }

    /**
     * Logs an exception to Sentry.
     * @param Exception $exception exception to log.
     * @param array $options capture options that can contain the following structure:
     *   culprit: (string) function call that caused the event
     *   extra: (array) additional metadata to store with the event
     * @param string $logger name of the logger.
     * @param mixed $context exception context.
     * @return string event id (or null if not captured).
     * @throws CException if logging the exception fails.
     */
    public function captureException($exception, $options = array(), $logger = '', $context = null)
    {
        if (!$this->isEnvironmentEnabled()) {
            return null;
        }
        $this->processOptions($options);
        $this->processLevel($exception, $options);
        try {
            $eventId = $this->_client->getIdent(
                $this->_client->captureException($exception, $options, $logger, $context)
            );
        } catch (Exception $e) {
            if (YII_DEBUG) {
                throw new CException('SentryClient failed to log exception: ' . $e->getMessage(), (int)$e->getCode());
            } else {
                $this->log($e->getMessage(), CLogger::LEVEL_ERROR);
                throw new CException('SentryClient failed to log exception.', (int)$e->getCode());
            }
        }
        $this->_loggedEventIds[] = $eventId;
        $this->log(sprintf('Exception logged to Sentry with event id: %d', $eventId), CLogger::LEVEL_INFO);
        return $eventId;
    }

    /**
     * Logs a message to Sentry.
     * @param string $message message to log.
     * @param array $params message parameters.
     * @param array $options capture options that can contain the following structure:
     *   culprit: (string) function call that caused the event
     *   extra: (array) additional metadata to store with the event
     * @param bool $stack whether to send the stack trace.
     * @param mixed $context message context.
     * @return string event id (or null if not captured).
     * @throws CException if logging the message fails.
     */
    public function captureMessage($message, $params = array(), $options = array(), $stack = false, $context = null)
    {
        if (strlen($message) > self::MAX_MESSAGE_LENGTH) {
            throw new CException(sprintf(
                'SentryClient cannot send messages that contain more than %d characters.',
                self::MAX_MESSAGE_LENGTH
            ));
        }
        if (!$this->isEnvironmentEnabled()) {
            return null;
        }
        $this->processOptions($options);
        $this->processLevel($message, $options);
        try {
            $eventId = $this->_client->getIdent(
                $this->_client->captureMessage($message, $params, $options, $stack, $context)
            );
        } catch (Exception $e) {
            if (YII_DEBUG) {
                throw new CException('SentryClient failed to log message: ' . $e->getMessage(), (int)$e->getCode());
            } else {
                $this->log($e->getMessage(), CLogger::LEVEL_ERROR);
                throw new CException('SentryClient failed to log message.', (int)$e->getCode());
            }
        }
        $this->_loggedEventIds[] = $eventId;
        $this->log(sprintf('Message logged to Sentry with event id: %d', $eventId), CLogger::LEVEL_INFO);
        return $eventId;
    }

    /**
     * Logs a query to Sentry.
     * @param string $query query to log.
     * @param string $level log level.
     * @param string $engine name of the sql driver.
     * @return string event id (or null if not captured).
     * @throws CException if logging the query fails.
     */
    public function captureQuery($query, $level = CLogger::LEVEL_INFO, $engine = '')
    {
        if (!$this->isEnvironmentEnabled()) {
            return null;
        }
        try {
            $eventId = $this->_client->getIdent(
                $this->_client->captureQuery($query, $level, $engine)
            );
        } catch (Exception $e) {
            if (YII_DEBUG) {
                throw new CException('SentryClient failed to log query: ' . $e->getMessage(), (int)$e->getCode());
            } else {
                $this->log($e->getMessage(), CLogger::LEVEL_ERROR);
                throw new CException('SentryClient failed to log query.', (int)$e->getCode());
            }
        }
        $this->_loggedEventIds[] = $eventId;
        $this->log(sprintf('Query logged to Sentry with event id: %d', $eventId), CLogger::LEVEL_INFO);
        return $eventId;
    }

    /**
     * Returns whether the active environment is enabled.
     * @return bool the result.
     */
    protected function isEnvironmentEnabled()
    {
        return in_array($this->environment, $this->enabledEnvironments);
    }

    /**
     * Processes the given options.
     * @param array $options the options to process.
     */
    protected function processOptions(&$options)
    {
        if (isset(Yii::app()->session['user'])) {
          $user = Yii::app()->session['user'];
          
          $options = CMap::mergeArray(
            array(
              'user' => array(
                'id' => $user->id,
                'username' => $user->username,
                'name' => $user->first_name . ' ' . $user->last_name,
                'email' => $user->email,
                'phone' => $user->phone,
                'two_factor_auth' => $user->two_factor_auth,
                'email_confirm' => $user->email_confirm,
                'phone_confirm' => $user->phone_confirm,
                'country' => $user->country,
                'reg_date' => $user->reg_date,
                'last_auth' => $user->date_last_auth,
                'ip_address' => Yii::app()->session['remote_addr']
              )
            ),
            $options
          );
        }
  
        if (isset(Yii::app()->session['company'])) {
          $company = Yii::app()->session['company'];
      
          $options = CMap::mergeArray(
            array(
              'user' => array(
                'company_id' => $company->id,
                'company_name' => $company->companyName,
              )
            ),
            $options
          );
        }
        
        /*
         * Exec often is not allowed
         * $options['release'] = exec('git log --pretty="%H" -n1 HEAD');*/
      
        if (!isset($options['extra'])) {
            $options['extra'] = array();
        }
        $options['extra'] = CMap::mergeArray($this->extraVariables, $options['extra']);
    }
  
    /**
     * Add level based on exception data
     * @param $exception
     * @param $options
     */
    private function processLevel($exception, &$options) {
      $statusCode = null;
      if (isset($exception->statusCode)) {
        $statusCode = $exception->statusCode;
      } elseif(isset($option['extra']['category']))  {
        $statusCode = end(explode('.', $options['extra']['category']));
      }
      
      if (!is_numeric($statusCode) && isset($options['extra']['level'])) {
        $level = $options['extra']['level'];
      } else {
        switch ($statusCode) {
          case 404:
            $level = Raven_Client::WARN;
            break;
          case 500:
            $level = Raven_Client::FATAL;
            break;
          default:
            $level = Raven_Client::ERROR;
        }
      }

      $options['level'] = $level;
    }

    /**
     * Writes a message to the log.
     * @param string $message message to log.
     * @param string $level log level.
     */
    protected function log($message, $level)
    {
        Yii::log($message, $level, 'vitalijssilins.sentry.components.SentryClient');
    }

    /**
     * Creates a Raven client
     * @return Raven_Client client instance.
     * @throws CException if the client could not be created.
     */
    protected function createClient()
    {
        $options = CMap::mergeArray(
            array(
                'message_limit' => self::MAX_MESSAGE_LENGTH,
                'logger' => 'yii',
                'tags' => array(
                    'environment' => $this->environment,
                    'php_version' => phpversion(),
                ),
            ),
            $this->options
        );
        try {
            $this->checkTags($options['tags']);
            return new Raven_Client($this->dns, $options);
        } catch (Exception $e) {
            if (YII_DEBUG) {
                throw new CException('SentryClient failed to create client: ' . $e->getMessage(), (int)$e->getCode());
            } else {
                $this->log($e->getMessage(), CLogger::LEVEL_ERROR);
                throw new CException('SentryClient failed to create client.', (int)$e->getCode());
            }
        }
    }

    /**
     * Checks that the given tags are valid.
     * @param array $tags tags to check.
     * @throws CException if a tag is invalid.
     */
    protected function checkTags($tags)
    {
        foreach ($tags as $key => $value) {
            if (strlen($key) > self::MAX_TAG_KEY_LENGTH) {
                throw new CException(sprintf(
                    'SentryClient does not allow tag keys that contain more than %d characters.',
                    self::MAX_TAG_KEY_LENGTH
                ));
            }
            if (strlen($value) > self::MAX_TAG_VALUE_LENGTH) {
                throw new CException(sprintf(
                    'SentryClient does not allow tag values that contain more than %d characters.',
                    self::MAX_TAG_VALUE_LENGTH
                ));
            }
        }
    }
}