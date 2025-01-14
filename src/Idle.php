<?php

namespace Webklex\PHPIMAP;

use Carbon\Carbon;
use Throwable;
use Webklex\PHPIMAP\Connection\Protocols\Response;
use Webklex\PHPIMAP\Exceptions\ConnectionClosedException;
use Webklex\PHPIMAP\Exceptions\ConnectionTimedOutException;

class Idle
{
    /**
     * The idle IMAP client.
     */
    protected Client $client;

    /**
     * Constructor.
     */
    public function __construct(
        protected Folder $folder,
        protected int $timeout,
    ) {
        $this->client = $folder->getClient()->clone();
    }

    /**
     * Await new messages on the connection.
     */
    public function await(callable $callback): void
    {
        $this->client->getConnection()->setStreamTimeout($this->timeout);

        $this->connect();

        $ttl = $this->getNextTimeout();

        $sequence = ClientManager::get('options.sequence', IMAP::ST_MSGN);

        while (true) {
            try {
                $line = $this->getNextLine();
            } catch (ConnectionTimedOutException|ConnectionClosedException) {
                $this->reconnect();

                $ttl = $this->getNextTimeout();

                continue;
            }

            if (($pos = strpos($line, 'EXISTS')) !== false) {
                $msgn = (int) substr($line, 2, $pos - 2);

                $callback($msgn, $sequence, $ttl);

                $ttl = $this->getNextTimeout();
            }

            if (! Carbon::now()->greaterThanOrEqualTo($ttl)) {
                continue;
            }

            // If we've been idle too long, politely send DONE & re-issue IDLE.
            // This keeps the server from killing the connection behind our back.
            try {
                // End current IDLE by sending DONE. Some servers
                // require this to avoid a forced disconnect.
                $this->client->getConnection()->done();
            } catch (Throwable) {
                // If done fails, we're likely already disconnected.
                // We'll attempt to reconnect and re-issue IDLE.
            }

            $this->reconnect();

            // Reset the time-to-live.
            $ttl = $this->getNextTimeout();
        }
    }

    /**
     * Reconnect the client and restart IDLE.
     */
    protected function reconnect(): void
    {
        $this->client->getConnection()->reset();

        $this->connect();
    }

    /**
     * Connect the client and begin IDLE.
     */
    protected function connect(): void
    {
        $this->client->connect();

        $this->client->openFolder($this->folder->path, true);

        $this->client->getConnection()->idle();
    }

    /**
     * Get the next line from the connection.
     *
     * @throws ConnectionTimedOutException|ConnectionClosedException
     */
    protected function getNextLine(): string
    {
        return $this->client->getConnection()->nextLine(Response::empty());
    }

    /**
     * Get the next timeout.
     */
    protected function getNextTimeout(): Carbon
    {
        return Carbon::now()->addSeconds($this->timeout);
    }
}