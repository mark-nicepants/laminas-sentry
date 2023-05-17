<?php

declare(strict_types=1);

/**
 * Andre Cardoso LaminasSentry
 *
 * This source file is part of the Andre Cardoso LaminasSentry package
 *
 * @package    LaminasSentry\LaminasSentry
 * @license    MIT License {@link /docs/LICENSE}
 * @copyright  Copyright (c) 2023, Andre Cardoso
 */

namespace LaminasSentry;

use Raven_Client as RavenClient;
use Raven_ErrorHandler as RavenErrorHandler;

/**
 * Class LaminasSentry
 * @package LaminasSentry
 */
class LaminasSentry
{
    /**
     * @var string $nonce
     */
    private static $nonce;

    /**
     * @var RavenClient $ravenClient
     */
    private $ravenClient;

    /**
     * @var RavenErrorHandler $ravenErrorHandler
     */
    private $ravenErrorHandler;

    /**
     * @param RavenClient $ravenClient
     * @param RavenErrorHandler|null $ravenErrorHandler
     */
    public function __construct(
        RavenClient $ravenClient,
        ?RavenErrorHandler $ravenErrorHandler = null
    ) {
        $this->ravenClient = $ravenClient;
        $this->setOrLoadRavenErrorHandler($ravenErrorHandler);
    }

    /**
     * @param null|RavenErrorHandler $ravenErrorHandler
     */
    private function setOrLoadRavenErrorHandler(?RavenErrorHandler $ravenErrorHandler)
    {
        if ($ravenErrorHandler !== null) {
            $this->ravenErrorHandler = $ravenErrorHandler;
        } else {
            $this->ravenErrorHandler = new RavenErrorHandler($this->ravenClient);
        }
    }

    /**
     * @param string $nonce
     */
    public static function setCSPNonce(string $nonce)
    {
        self::$nonce = $nonce;
    }

    /**
     * @param bool $callExistingHandler
     * @param int $errorReporting
     * @return $this
     */
    public function registerErrorHandler(
        bool $callExistingHandler = true,
        int $errorReporting = E_ALL
    ): LaminasSentry {
        $this->ravenErrorHandler->registerErrorHandler($callExistingHandler, $errorReporting);
        return $this;
    }

    /**
     * @param bool $callExistingHandler
     *
     * @return LaminasSentry
     */
    public function registerExceptionHandler(bool $callExistingHandler = true): LaminasSentry
    {
        $this->ravenErrorHandler->registerExceptionHandler($callExistingHandler);
        return $this;
    }

    /**
     * @param int $reservedMemorySize
     *
     * @return LaminasSentry
     */
    public function registerShutdownFunction(int $reservedMemorySize = 10): LaminasSentry
    {
        $this->ravenErrorHandler->registerShutdownFunction($reservedMemorySize);
        return $this;
    }

    /**
     * @return null|string
     */
    public function getCSPNonce(): ?string
    {
        return self::$nonce;
    }
}
