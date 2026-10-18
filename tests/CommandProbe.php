<?php

namespace NightGuardian\Laravel\Tests;

use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Assert;

/**
 * Runs an Artisan command and asserts on its output and exit code. A small stand-in for Laravel 9's
 * PendingCommand::expectsOutputToContain(), which older Laravel versions do not have.
 */
class CommandProbe
{
    /** @var TestCase */
    private $test;

    /** @var string */
    private $command;

    /** @var array<string, mixed> */
    private $parameters;

    /** @var array<int, string> */
    private $expected = [];

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function __construct(TestCase $test, string $command, array $parameters)
    {
        $this->test = $test;
        $this->command = $command;
        $this->parameters = $parameters;
    }

    /**
     * @return $this
     */
    public function expectsOutputToContain(string $text)
    {
        $this->expected[] = $text;

        return $this;
    }

    /**
     * @return $this
     */
    public function assertExitCode(int $code)
    {
        $actual = Artisan::call($this->command, $this->parameters);
        $output = Artisan::output();

        Assert::assertSame($code, $actual, "Unexpected exit code. Output:\n".$output);

        foreach ($this->expected as $text) {
            Assert::assertStringContainsString($text, $output);
        }

        return $this;
    }

    /**
     * @return $this
     */
    public function assertSuccessful()
    {
        return $this->assertExitCode(0);
    }
}
