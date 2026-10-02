<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Jobs;
use PHPUnit\Framework\TestCase;

/**
 * Scheduling is small on purpose: every N minutes, or once a day at HH:MM. What
 * matters is that "due" is decided the same way twice, and that a job the reader
 * asked to run now is never silently dropped.
 */
final class JobsTest extends TestCase
{
    private string $path;

    private Jobs $jobs;

    protected function setUp(): void
    {
        $this->path = \sys_get_temp_dir() . '/quiesce-jobs-' . \bin2hex(\random_bytes(4)) . '.json';
        $this->jobs = new Jobs($this->path);
    }

    protected function tearDown(): void
    {
        @\unlink($this->path);
    }

    public function testAJobIsSavedWithAnIdAndReadBack(): void
    {
        $saved = $this->jobs->save(['name' => 'Daily notes', 'task' => 'Summarise notes.md', 'every_minutes' => 60]);

        self::assertTrue($saved['ok']);

        $all = $this->jobs->all();

        self::assertCount(1, $all);
        self::assertNotSame('', $all[0]['id']);
        self::assertSame('Summarise notes.md', $all[0]['task']);
    }

    public function testAJobWithNothingToDoIsNotSaved(): void
    {
        $saved = $this->jobs->save(['name' => 'Empty']);

        self::assertFalse($saved['ok']);
        self::assertSame([], $this->jobs->all());
    }

    public function testAJobThatHasNeverRunIsDue(): void
    {
        $this->jobs->save(['name' => 'First run', 'task' => 'Do something', 'enabled' => true, 'every_minutes' => 60]);

        $due = $this->jobs->due(\microtime(true));

        self::assertCount(1, $due);
    }

    public function testADisabledJobIsNotDue(): void
    {
        $this->jobs->save(['name' => 'Off', 'task' => 'Do something', 'enabled' => false, 'every_minutes' => 60]);

        self::assertSame([], $this->jobs->due(\microtime(true)));
    }

    public function testAnIntervalJobWaitsItsInterval(): void
    {
        $this->jobs->save(['name' => 'Hourly', 'task' => 'Do something', 'enabled' => true, 'every_minutes' => 60]);

        $job = $this->jobs->all()[0];
        $this->jobs->markRan($job['id'], 'done', 3.2);

        $now = \microtime(true);

        self::assertSame([], $this->jobs->due($now), 'not due again immediately');
        self::assertCount(1, $this->jobs->due($now + 3_601), 'due again after the interval');

        $view = $this->jobs->view($now)['jobs'][0];

        self::assertSame('done', $view['last_state']);
        self::assertFalse($view['requested'], 'the request flag is cleared by running');
    }

    public function testARunNowRequestSurvivesUntilItRuns(): void
    {
        $this->jobs->save(['name' => 'Manual', 'task' => 'Do something', 'enabled' => false, 'every_minutes' => 1440]);

        $job = $this->jobs->all()[0];

        self::assertSame([], $this->jobs->due(\microtime(true)), 'disabled and not yet asked for');

        $this->jobs->requestRun($job['id']);

        self::assertCount(1, $this->jobs->due(\microtime(true)), 'asked for, so due even though disabled');
    }

    public function testADailyJobLooksAtTheClock(): void
    {
        $this->jobs->save(['name' => 'Morning', 'task' => 'Do something', 'enabled' => true, 'at' => '07:30']);

        $job = $this->jobs->all()[0];
        $nineInTheMorning = \mktime(9, 0, 0);

        self::assertNotNull($job);

        $next = $this->jobs->nextRun($job, (float) $nineInTheMorning);

        self::assertNotNull($next);
        self::assertSame('07:30', \date('H:i', (int) $next), 'the next one is tomorrow morning');
        self::assertGreaterThan($nineInTheMorning, (int) $next);
    }

    public function testJobsCanBeToggledAndRemoved(): void
    {
        $this->jobs->save(['name' => 'Toggle me', 'task' => 'Do something', 'enabled' => true]);
        $id = $this->jobs->all()[0]['id'];

        $this->jobs->toggle($id, false);
        self::assertFalse($this->jobs->all()[0]['enabled']);

        $this->jobs->remove($id);
        self::assertSame([], $this->jobs->all());
    }

    public function testTheViewReportsWhenEachJobWillNextRun(): void
    {
        $this->jobs->save(['name' => 'Soon', 'task' => 'Do something', 'enabled' => true, 'every_minutes' => 15]);

        $view = $this->jobs->view(\microtime(true));

        self::assertSame($this->path, $view['path']);
        self::assertNotSame([], $view['jobs']);
        self::assertIsFloat($view['jobs'][0]['next_run']);
    }
}
