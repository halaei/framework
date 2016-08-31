<?php

use Illuminate\Queue\Jobs\RedisJob;
use Illuminate\Support\Arr;
use Mockery as m;
use Illuminate\Redis\Database;
use Illuminate\Queue\BlockingRedisQueue;
use Illuminate\Container\Container;

class BlockingRedisQueueIntegrationTest extends PHPUnit_Framework_TestCase
{
    /**
     * @var bool
     */
    private static $connectionFailedOnceWithDefaultsSkip = false;

    /**
     * @var Database
     */
    private $redis;

    public function setUp()
    {
        parent::setUp();

        $host = getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = getenv('REDIS_PORT') ?: 6379;

        if (static::$connectionFailedOnceWithDefaultsSkip) {
            $this->markTestSkipped('Trying default host/port failed, please set environment variable REDIS_HOST & REDIS_PORT to enable '.__CLASS__);

            return;
        }

        $this->redis = new Database([
            'cluster' => false,
            'default' => [
                'host' => $host,
                'port' => $port,
                'database' => 5,
                'timeout' => 0.5,
            ],
        ]);

        try {
            $this->redis->connection()->flushdb();
        } catch (\Exception $e) {
            if ($host === '127.0.0.1' && $port === 6379 && getenv('REDIS_HOST') === false) {
                $this->markTestSkipped('Trying default host/port failed, please set environment variable REDIS_HOST & REDIS_PORT to enable '.__CLASS__);
                static::$connectionFailedOnceWithDefaultsSkip = true;

                return;
            }
        }
    }

    public function tearDown()
    {
        parent::tearDown();
        m::close();
        if ($this->redis) {
            $this->redis->connection()->flushdb();
        }
    }

    private function getQueue($timeout, $redis = null)
    {
        $queue = new BlockingRedisQueue($redis ?: $this->redis, 'default', null, 60, $timeout);
        $queue->setContainer(m::mock(Container::class));
        return $queue;
    }

    public function test_blocking_pop_nothing()
    {
        $queue = $this->getQueue(1);
        $this->assertNull($queue->pop());
    }

    public function test_blocking_pop_something()
    {
        $queue = $this->getQueue(0);
        $queue->push(new BlockingRedisQueueIntegrationTestJob(1));
        $job = $queue->pop();
        $this->assertInstanceOf(RedisJob::class, $job);
        $this->assertEquals(1, $this->redis->connection()->zcard('queues:default:reserved'));
        $this->assertEquals(2, Arr::get(json_decode($job->getReservedJob(), true), 'attempts'));
        $job->delete();
        $this->assertEquals(0, $this->redis->connection()->zcard('queues:default:reserved'));
        $this->assertEquals(0, $this->redis->connection()->zcard('queues:default:delayed'));
    }

    public function test_blocking_pop_waits()
    {
        $queue = $this->getQueue(0);
        if (!pcntl_fork()) {
            $host = getenv('REDIS_HOST') ?: '127.0.0.1';
            $port = getenv('REDIS_PORT') ?: 6379;
            $redis = new Database([
                'cluster' => false,
                'default' => [
                    'host' => $host,
                    'port' => $port,
                    'database' => 5,
                    'timeout' => 0.5,
                ],
            ]);
            $queue = $this->getQueue(0, $redis);

            sleep(1);

            $queue->push(new BlockingRedisQueueIntegrationTestJob(1));
            die;
        }
        $job = $queue->pop();
        $this->assertInstanceOf(RedisJob::class, $job);
        $this->assertEquals(1, $this->redis->connection()->zcard('queues:default:reserved'));
        $job->delete();
        $this->assertEquals(0, $this->redis->connection()->zcard('queues:default:reserved'));
    }
}

class BlockingRedisQueueIntegrationTestJob
{
    public $i;

    public function __construct($i)
    {
        $this->i = $i;
    }

    public function handle()
    {
    }
}
