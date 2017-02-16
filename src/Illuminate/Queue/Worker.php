<?php

namespace Illuminate\Queue;

use Exception;
use Throwable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Symfony\Component\Debug\Exception\FatalThrowableError;
use Illuminate\Contracts\Cache\Repository as CacheContract;

class Worker
{
    /**
     * The queue manager instance.
     *
     * @var \Illuminate\Queue\QueueManager
     */
    protected $manager;

    /**
     * The event dispatcher instance.
     *
     * @var \Illuminate\Contracts\Events\Dispatcher
     */
    protected $events;

    /**
     * The cache repository implementation.
     *
     * @var \Illuminate\Contracts\Cache\Repository
     */
    protected $cache;

    /**
     * The exception handler instance.
     *
     * @var \Illuminate\Foundation\Exceptions\Handler
     */
    protected $exceptions;

    /**
     * Indicates if the worker should exit.
     *
     * @var bool
     */
    protected $shouldQuit = false;

    /**
     * Indicates if the worker is paused.
     *
     * @var bool
     */
    protected $paused = false;

    /**
     * @var JobProcessor
     */
    private $processor;

    /**
     * Create a new queue worker.
     *
     * @param  \Illuminate\Queue\QueueManager  $manager
     * @param  \Illuminate\Contracts\Events\Dispatcher  $events
     * @param  \Illuminate\Contracts\Debug\ExceptionHandler  $exceptions
     * @param  \Illuminate\Queue\JobProcessor  $processor
     * @return void
     */
    public function __construct(QueueManager $manager,
                                Dispatcher $events,
                                ExceptionHandler $exceptions,
                                JobProcessor $processor)
    {
        $this->events = $events;
        $this->manager = $manager;
        $this->exceptions = $exceptions;
        $this->processor = $processor;
    }

    /**
     * Listen to the given queue in a loop.
     *
     * @param  string  $connectionName
     * @param  string  $queue
     * @param  \Illuminate\Queue\WorkerOptions  $options
     * @return void
     */
    public function daemon($connectionName, $queue, WorkerOptions $options)
    {
        $this->listenForSignals();

        $lastRestart = $this->getTimestampOfLastQueueRestart();

        while (true) {
            // Before reserving any jobs, we will make sure this queue is not paused and
            // if it is we will just pause this worker for a given amount of time and
            // make sure we do not need to kill this worker process off completely.
            if (! $this->daemonShouldRun($options)) {
                $this->pauseWorker($options, $lastRestart);

                continue;
            }

            // First, we will attempt to get the next job off of the queue. We will also
            // register the timeout handler and reset the alarm for this job so it is
            // not stuck in a frozen state forever. Then, we can fire off this job.
            $job = $this->getNextJob(
                $this->manager->connection($connectionName), $queue
            );

            $this->registerTimeoutHandler($job, $options);

            // If the daemon should run (not in maintenance mode, etc.), then we can run
            // fire off this job for processing. Otherwise, we will need to sleep the
            // worker so no more jobs are processed until they should be processed.
            if ($job) {
                $this->runJob($job, $connectionName, $options);
            } else {
                $this->sleep($options->sleep);
            }

            // Finally, we will check to see if we have exceeded our memory limits or if
            // the queue should restart based on other indications. If so, we'll stop
            // this worker and let whatever is "monitoring" it restart the process.
            $this->stopIfNecessary($options, $lastRestart);
        }
    }

    /**
     * Register the worker timeout handler (PHP 7.1+).
     *
     * @param  \Illuminate\Contracts\Queue\Job|null  $job
     * @param  WorkerOptions  $options
     * @return void
     */
    protected function registerTimeoutHandler($job, WorkerOptions $options)
    {
        if ($options->timeout > 0 && $this->supportsAsyncSignals()) {
            // We will register a signal handler for the alarm signal so that we can kill this
            // process if it is running too long because it has frozen. This uses the async
            // signals supported in recent versions of PHP to accomplish it conveniently.
            pcntl_signal(SIGALRM, function () {
                $this->kill(1);
            });

            pcntl_alarm($this->timeoutForJob($job, $options) + $options->sleep);
        }
    }

    /**
     * Get the appropriate timeout for the given job.
     *
     * @param  \Illuminate\Contracts\Queue\Job|null  $job
     * @param  WorkerOptions  $options
     * @return int
     */
    protected function timeoutForJob($job, WorkerOptions $options)
    {
        return $job && ! is_null($job->timeout()) ? $job->timeout() : $options->timeout;
    }

    /**
     * Determine if the daemon should process on this iteration.
     *
     * @param  WorkerOptions  $options
     * @return bool
     */
    protected function daemonShouldRun(WorkerOptions $options)
    {
        return ! (($this->manager->isDownForMaintenance() && ! $options->force) ||
            $this->paused ||
            $this->events->until(new Events\Looping) === false);
    }

    /**
     * Pause the worker for the current loop.
     *
     * @param  WorkerOptions  $options
     * @param  int  $lastRestart
     * @return void
     */
    protected function pauseWorker(WorkerOptions $options, $lastRestart)
    {
        $this->sleep($options->sleep > 0 ? $options->sleep : 1);

        $this->stopIfNecessary($options, $lastRestart);
    }

    /**
     * Stop the process if necessary.
     *
     * @param  WorkerOptions  $options
     * @param  int  $lastRestart
     */
    protected function stopIfNecessary(WorkerOptions $options, $lastRestart)
    {
        if ($this->shouldQuit) {
            $this->kill();
        }

        if ($this->memoryExceeded($options->memory)) {
            $this->stop(12);
        } elseif ($this->queueShouldRestart($lastRestart)) {
            $this->stop();
        }
    }

    /**
     * Process the next job on the queue.
     *
     * @param  string  $connectionName
     * @param  string  $queue
     * @param  \Illuminate\Queue\WorkerOptions  $options
     * @return void
     */
    public function runNextJob($connectionName, $queue, WorkerOptions $options)
    {
        $job = $this->getNextJob(
            $this->manager->connection($connectionName), $queue
        );

        // If we're able to pull a job off of the stack, we will process it and then return
        // from this method. If there is no job on the queue, we will "sleep" the worker
        // for the specified number of seconds, then keep processing jobs after sleep.
        if ($job) {
            return $this->runJob($job, $connectionName, $options);
        }

        $this->sleep($options->sleep);
    }

    /**
     * Get the next job from the queue connection.
     *
     * @param  \Illuminate\Contracts\Queue\Queue  $connection
     * @param  string  $queue
     * @return \Illuminate\Contracts\Queue\Job|null
     */
    protected function getNextJob($connection, $queue)
    {
        try {
            foreach (explode(',', $queue) as $queue) {
                if (with($jobs = $connection->pop(1, $queue))->isNotEmpty()) {
                    return $jobs->first();
                }
            }
        } catch (Exception $e) {
            $this->exceptions->report($e);
        } catch (Throwable $e) {
            $this->exceptions->report(new FatalThrowableError($e));
        }
    }

    /**
     * Process the given job.
     *
     * @param  \Illuminate\Contracts\Queue\Job  $job
     * @param  string  $connectionName
     * @param  \Illuminate\Queue\WorkerOptions  $options
     * @return void
     */
    protected function runJob($job, $connectionName, WorkerOptions $options)
    {
        try {
            return $this->processor->process($connectionName, $job, $options);
        } catch (Exception $e) {
            $this->exceptions->report($e);
        } catch (Throwable $e) {
            $this->exceptions->report(new FatalThrowableError($e));
        }
    }

    /**
     * Determine if the queue worker should restart.
     *
     * @param  int|null  $lastRestart
     * @return bool
     */
    protected function queueShouldRestart($lastRestart)
    {
        return $this->getTimestampOfLastQueueRestart() != $lastRestart;
    }

    /**
     * Get the last queue restart timestamp, or null.
     *
     * @return int|null
     */
    protected function getTimestampOfLastQueueRestart()
    {
        if ($this->cache) {
            return $this->cache->get('illuminate:queue:restart');
        }
    }

    /**
     * Enable async signals for the process.
     *
     * @return void
     */
    protected function listenForSignals()
    {
        if ($this->supportsAsyncSignals()) {
            pcntl_async_signals(true);

            pcntl_signal(SIGTERM, function () {
                $this->shouldQuit = true;
            });

            pcntl_signal(SIGUSR2, function () {
                $this->paused = true;
            });

            pcntl_signal(SIGCONT, function () {
                $this->paused = false;
            });
        }
    }

    /**
     * Determine if "async" signals are supported.
     *
     * @return bool
     */
    protected function supportsAsyncSignals()
    {
        return version_compare(PHP_VERSION, '7.1.0') >= 0 &&
               extension_loaded('pcntl');
    }

    /**
     * Determine if the memory limit has been exceeded.
     *
     * @param  int   $memoryLimit
     * @return bool
     */
    public function memoryExceeded($memoryLimit)
    {
        return (memory_get_usage() / 1024 / 1024) >= $memoryLimit;
    }

    /**
     * Stop listening and bail out of the script.
     *
     * @param  int  $status
     * @return void
     */
    public function stop($status = 0)
    {
        $this->events->fire(new Events\WorkerStopping);

        exit($status);
    }

    /**
     * Kill the process.
     *
     * @param  int  $status
     * @return void
     */
    public function kill($status = 0)
    {
        if (extension_loaded('posix')) {
            posix_kill(getmypid(), SIGKILL);
        }

        exit($status);
    }

    /**
     * Sleep the script for a given number of seconds.
     *
     * @param  int   $seconds
     * @return void
     */
    public function sleep($seconds)
    {
        sleep($seconds);
    }

    /**
     * Set the cache repository implementation.
     *
     * @param  \Illuminate\Contracts\Cache\Repository  $cache
     * @return void
     */
    public function setCache(CacheContract $cache)
    {
        $this->cache = $cache;
    }
}
