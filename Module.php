<?php

declare(strict_types=1);

/**
 * Andre Cardoso LaminasSentry
 *
 * This source file is part of the Andre Cardoso LaminasSentry package
 *
 * @package    LaminasSentry\Module
 * @license    MIT License {@link /docs/LICENSE}
 * @copyright  Copyright (c) 2023, Andre Cardoso
 */

namespace LaminasSentry;

use Laminas\EventManager\EventManager;
use Laminas\Log\Logger;
use Laminas\Mvc\MvcEvent;
use Laminas\Mvc\View\Http\ExceptionStrategy;
use Laminas\ServiceManager\ServiceManager;
use Laminas\View\Helper\HeadScript;
use LaminasSentry\Mvc\View\Console\ExceptionStrategy as SentryConsoleStrategy;
use LaminasSentry\Mvc\View\Http\ExceptionStrategy as SentryHttpStrategy;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Raven_Client as Raven;
use Raven_Exception;

/**
 * Class Module
 *
 * @package LaminasSentry
 */
class Module
{
    const RAVENJS_VERSION = '3.27.0';

    /**
     * Translates Laminas Framework log levels to Raven log levels.
     */
    private $logLevels = [
        0 => Raven::FATAL,
        1 => Raven::FATAL,
        2 => Raven::FATAL,
        3 => Raven::ERROR,
        4 => Raven::WARNING,
        5 => Raven::INFO,
        6 => Raven::INFO,
        7 => Raven::DEBUG,
    ];

    /**
     * @var Raven $ravenClient
     */
    protected $ravenClient;

    /**
     * @var LaminasSentry $laminasSentry
     */
    protected $laminasSentry;

    /**
     * @var $config
     */
    protected $config;

    /**
     * @var EventManager $eventManager
     */
    protected $eventManager;

    /**
     * @param MvcEvent $event
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws Raven_Exception
     */
    public function onBootstrap(MvcEvent $event)
    {
        // Setup RavenClient (provided by Sentry) and Sentry (provided by this module)
        $this->config = $event->getApplication()->getServiceManager()->get('Config');

        if (!$this->config['laminas-sentry']['use-module']) {
            return;
        }

        if (isset($this->config['laminas-sentry']['raven-config']) && \is_array($this->config['laminas-sentry']['raven-config'])) {
            $ravenConfig = $this->config['laminas-sentry']['raven-config'];
        } else {
            $ravenConfig = [];
        }

        $sentryApiKey = $this->config['laminas-sentry']['sentry-api-key'];

        // Do preliminary checks only, Raven will parse the string further
        if (!$sentryApiKey || '' === $sentryApiKey || \is_null($sentryApiKey)) {
            throw new Raven_Exception('Missing Sentry API key.');
        }
        $ravenClient  = new Raven($sentryApiKey, $ravenConfig);

        // Register the RavenClient as a application wide service
        /** @var ServiceManager $serviceManager */
        $serviceManager = $event->getApplication()->getServiceManager();
        $serviceManager->setService('raven', $ravenClient);

        $this->ravenClient = $ravenClient;
        $this->laminasSentry  = new LaminasSentry($ravenClient);

        // Get the eventManager and set it as a member for convenience
        $this->eventManager = $event->getApplication()->getEventManager();

        // If LaminasSentry is configured to use the custom logger, attach the listener
        if ($this->config['laminas-sentry']['attach-log-listener']) {
            $this->setupBasicLogging($event);
        }

        // If LaminasSentry is configured to log exceptions, a few things need to be set up
        if ($this->config['laminas-sentry']['handle-exceptions']) {
            $this->setupExceptionLogging($event);
        }

        // If LaminasSentry is configured to log errors, register it as error handler
        if ($this->config['laminas-sentry']['handle-errors']) {
            $errorReportingLevel = $this->config['laminas-sentry']['error-reporting'] ?? -1;
            $this->laminasSentry->registerErrorHandler($this->config['laminas-sentry']['call-existing-error-handler'], $errorReportingLevel);
        }

        // If LaminasSentry is configured to log shutdown errors, register it
        if ($this->config['laminas-sentry']['handle-shutdown-errors']) {
            $this->laminasSentry->registerShutdownFunction();
        }

        // If LaminasSentry is configured to log Javascript errors, add needed scripts to the view
        if ($this->config['laminas-sentry']['handle-javascript-errors']) {
            $this->setupJavascriptLogging($event);
        }
    }

    /**
     * @return array
     */
    public function getAutoloaderConfig(): array
    {
        return [
            'Laminas\Loader\StandardAutoloader' => [
                'namespaces' => [
                    __NAMESPACE__ => __DIR__ . '/src/' . __NAMESPACE__,
                ]
            ]
        ];
    }

    /**
     * @return mixed
     */
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    /**
     * Gives us the possibility to write logs to Sentry from anywhere in the application
     * Doesn't use the ZF compatible Log Writer because we want to return the Sentry event ID
     * ZF Logging doesn't provide the possibility to return values from writers
     *
     * @param MvcEvent $event
     */
    protected function setupBasicLogging(MvcEvent $event)
    {
        // Get the shared event manager and attach a logging listener for the log event on application level
        $sharedManager = $this->eventManager->getSharedManager();
        $raven         = $this->ravenClient;
        $logLevels     = $this->logLevels;

        $sharedManager->attach(
            '*', 'log', function($event) use ($raven, $logLevels) {
            /** @var $event MvcEvent */
            if (\is_object($event->getTarget())) {
                $target = \get_class($event->getTarget());
            } else {
                $target = (string)$event->getTarget();
            }
            $message  = $event->getParam('message', 'No message provided');
            $priority = (int)$event->getParam('priority', Logger::INFO);
            $message  = sprintf('%s: %s', $target, $message);
            $tags     = $event->getParam('tags', []);
            $extra    = $event->getParam('extra', []);
            $eventID  = $raven->captureMessage(
                $message, [], ['tags' => $tags, 'level' => $logLevels[$priority], 'extra' => $extra]
            );
            return $eventID;
        }, 2
        );
    }

    /**
     * 1. Registers Sentry as exception handler
     * 2. Replaces the default ExceptionStrategy so Exception that are caught by Laminas Framework can still be logged
     *
     * @param MvcEvent $event
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    protected function setupExceptionLogging(MvcEvent $event)
    {
        // Register Sentry as exception handler for exception that bubble up to the top
        $this->laminasSentry->registerExceptionHandler($this->config['laminas-sentry']['call-existing-exception-handler']);

        // Replace the default ExceptionStrategy with LaminasSentry's strategy
        if ($event->getApplication()->getServiceManager()->has('HttpExceptionStrategy')) {
            /** @var $exceptionStrategy ExceptionStrategy */
            $exceptionStrategy = $event->getApplication()->getServiceManager()->get('HttpExceptionStrategy');
            $exceptionStrategy->detach($this->eventManager);
        }

        // Check if script is running in console
        $exceptionStrategy = (PHP_SAPI == 'cli') ? new SentryConsoleStrategy() : new SentryHttpStrategy();
        $exceptionStrategy->attach($this->eventManager);
        $exceptionStrategy->setDisplayExceptions($this->config['laminas-sentry']['display-exceptions']);
        $exceptionStrategy->setDefaultExceptionMessage($this->config['laminas-sentry'][(PHP_SAPI == 'cli') ? 'default-exception-console-message' : 'default-exception-message']);
        if ($exceptionStrategy instanceof SentryHttpStrategy && isset($this->config['view_manager']['exception_template'])) {
            $exceptionStrategy->setExceptionTemplate($this->config['view_manager']['exception_template']);
        }
        $ravenClient = $this->ravenClient;

        // Attach an exception listener for the LaminasSentry exception strategy, can be triggered from anywhere else too
        $this->eventManager->getSharedManager()->attach(
            '*', 'logException', function ($event) use ($ravenClient) {
            /** @var $event MvcEvent */
            $exception = $event->getParam('exception');
            $tags      = $event->getParam('tags', []);
            return $ravenClient->captureException($exception, ['tags' => $tags]);
        }
        );
    }

    /**
     * Adds the necessary javascript, tries to prepend
     *
     * @param MvcEvent $event
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    protected function setupJavascriptLogging(MvcEvent $event)
    {
        /** @var HeadScript $headScript */
        $headScript    = $event->getApplication()->getServiceManager()->get('ViewHelperManager')->get('headscript');

        $useRavenjsCDN = $this->config['laminas-sentry']['use-ravenjs-cdn'] ?? false;

        if ($useRavenjsCDN) {
            $ravenjsVersion = $this->config['laminas-sentry']['ravenjs-version'] ?? self::RAVENJS_VERSION;
            $cdnUri = sprintf('//cdn.ravenjs.com/%s/raven.min.js', $ravenjsVersion);
            $headScript->offsetSetFile(0, $cdnUri);
        } else {
            $ravenjsSource = $this->config['laminas-sentry']['ravenjs-source'] ?? false;

            if ($ravenjsSource) {
                $headScript->offsetSetFile(0, $ravenjsSource);
            }
        }

        $publicApiKey  = $this->convertKeyToPublic($this->config['laminas-sentry']['sentry-api-key']);
        $ravenjsConfig = json_encode($this->config['laminas-sentry']['ravenjs-config']);

        $attributes = \is_null($this->laminasSentry->getCSPNonce()) ? [] : ['nonce' => $this->laminasSentry->getCSPNonce()];

        $headScript->offsetSetScript(1, sprintf("if (typeof Raven !== 'undefined') Raven.config('%s', %s).install()", $publicApiKey, $ravenjsConfig), 'text/javascript', $attributes);
    }

    /**
     * @param string $key
     *
     * @return string $publicKey
     */
    private function convertKeyToPublic($key): string
    {
        // If new DSN is configured, no converting is needed
        if (substr_count($key, ':') == 1) {
            return $key;
        }
        // If legacy DSN with private part is configured...
        // ...find private part
        $start       = strpos($key, ':', 6);
        $end         = strpos($key, '@');
        $privatePart = substr($key, $start, $end - $start);

        // ... replace it with an empty string
        return str_replace($privatePart, '', $key);
    }
}
