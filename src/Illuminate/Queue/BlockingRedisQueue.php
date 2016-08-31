<?php

namespace Illuminate\Queue;

use Illuminate\Queue\Jobs\RedisJob;
use Illuminate\Redis\Database;

class BlockingRedisQueue extends RedisQueue
{
    /**
     * Timeout for blocking pop.
     *
     * @var int
     */
    private $timeout = 10;

    /**
     * Create a new blocking Redis queue instance.
     *
     * @param  \Illuminate\Redis\Database  $redis
     * @param  string  $default
     * @param  string  $connection
     * @param  int  $expire
     * @param  int  $timeout
     * @return void
     */
    public function __construct(Database $redis, $default = 'default', $connection = null, $expire = 60, $timeout = 10)
    {
        parent::__construct($redis, $default, $connection, $expire);
        $this->timeout = $timeout;
    }

    /**
     * Pop the next job off of the queue.
     *
     * @param  string  $queue
     * @return \Illuminate\Contracts\Queue\Job|null
     */
    public function pop($queue = null)
    {
        $rawBody = $this->getConnection()->blpop($this->getQueue($queue), $this->timeout);

        if (! is_null($rawBody)) {
            $payload = json_decode($rawBody[1], true);
            $payload['attempts']++;
            $reserved = json_encode($payload);
            $this->getConnection()->zadd($this->getQueue($queue).':reserved', [
                $reserved => $this->getTime() + $this->expire
            ]);
            return new RedisJob($this->container, $this, $rawBody[1], $reserved, $queue ?: $this->default);
        }
    }
}
