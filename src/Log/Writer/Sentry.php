<?php

/**
 * Andre Cardoso LaminasSentry
 *
 * This source file is part of the Andre Cardoso LaminasSentry package
 *
 * @package    LaminasSentry\Log\Writer\Sentry
 * @license    MIT License {@link /docs/LICENSE}
 * @copyright  Copyright (c) 2023, Andre Cardoso
 */

namespace LaminasSentry\Log\Writer;

use Laminas\Log\Writer\AbstractWriter;
use Raven_Client as Raven;

/**
 * @package    LaminasSentry\Log\Wrier\Sentry
 */
class Sentry extends AbstractWriter
{
    /**
     * @var Raven
     */
    protected $raven;

    /**
     * Translates Laminas Framework log levels to Raven log levels.
     */
    private $logLevels = [
        'DEBUG' => Raven::DEBUG,
        'INFO' => Raven::INFO,
        'NOTICE' => Raven::INFO,
        'WARN' => Raven::WARNING,
        'ERR' => Raven::ERROR,
        'CRIT' => Raven::FATAL,
        'ALERT' => Raven::FATAL,
        'EMERG' => Raven::FATAL,
    ];

    /**
     * @param Raven $raven
     * @param $options
     */
    public function __construct(
        Raven $raven,
        $options = null
    ) {
        $this->raven = $raven;
        parent::__construct($options);
    }

    /**
     * Write a message to the log
     *
     * @param array $event log data event
     *
     * @return string $eventID the event ID
     */
    protected function doWrite(array $event): string
    {
        $extra = [];
        $extra['timestamp'] = $event['timestamp'];
        $eventID = $this->raven->captureMessage($event['message'], [], $this->logLevels[$event['priorityName']], false, $event['extra']);

        return $eventID;
    }
}
