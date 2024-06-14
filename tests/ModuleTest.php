<?php

namespace LaminasSentryTests;

use Laminas\Loader\StandardAutoloader;
use LaminasSentry\Module as LaminasSentryModule;
use PHPUnit\Framework\TestCase;

/**
 * Class ModuleTest
 * @package LaminasSentryTests
 */
class ModuleTest extends TestCase
{
    /**
     * @var LaminasSentryModule
     */
    private $module;

    public function setUp(): void
    {
        parent::setUp();
        $this->module = new LaminasSentryModule();
    }

    public function testDefaultModuleConfig()
    {
        $expectedConfig = [];

        $actualConfig = $this->module->getConfig();

        $this->assertEquals($expectedConfig, $actualConfig);
    }

    public function testAutoloaderConfig()
    {
        $expectedConfig = [
            StandardAutoloader::class => [
                'namespaces' => [
                    'LaminasSentry' => realpath(__DIR__ . '/../../src/LaminasSentry')
                ]
            ]
        ];

        $actualConfig = $this->module->getAutoloaderConfig();

        $this->assertEquals($expectedConfig, $actualConfig);
    }
}
