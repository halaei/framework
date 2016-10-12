<?php

namespace Illuminate\Database\Bus;

use Illuminate\Database\ConnectionInterface;

class PreventTransactionLeak
{
    public function handle($command, $next)
    {
        if ($command instanceof TransactionSafe) {

            $connection = $this->connection($command);

            $level = $connection->transactionLevel();

            try {
                return $next($command);
            } finally {
                while ($connection->transactionLevel() > $level) {
                    $connection->rollBack();
                }
            }
        }

        return $next($command);
    }

    /**
     * Get the database connection on which $command works.
     *
     * @param   mixed  $command
     * @return ConnectionInterface
     */
    protected function connection($command)
    {
        $connection = isset($command->connection) ? $command->connection : null;

        return \DB::connection($connection);
    }
}
