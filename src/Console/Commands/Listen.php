<?php

namespace Hatthi\Connector\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use WebSocket\Connection;
use WebSocket\Message\Message;
use WebSocket\Middleware\CloseHandler;
use WebSocket\Middleware\PingResponder;
use Illuminate\Support\Str;
use WebSocket\Server;

class Listen extends Command {

    private static $connections = [];
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hatthi:listen';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $server = new Server(8080);
        $server->addMiddleware(new CloseHandler())
        ->addMiddleware(new PingResponder())
        ->onConnect(function(Server $server, Connection $connection) {
            $id = $connection->getName() .'_' .$connection->getRemoteName();
            self::$connections[$id] = $connection;
        })->onDisconnect(function(Server $server, Connection $connection) {
            $id = $connection->getName().'_' .$connection->getRemoteName();
            unset(self::$connections[$id]);
        })
        ->onText(function(Server $server, Connection $connection, Message $message) {
            $textMessage = trim($message->getContent());
            Log::info($textMessage);
            if (Str::contains($textMessage, 'sys:')) {
                $messageParts = explode(':', $textMessage);
                if (count($messageParts) > 1) {
                    if (trim($messageParts[1]) == 'synced') {
                        foreach (self::$connections as $id => $conn) {
                            if ($id == $connection->getName().'_' .$connection->getRemoteName()) {
                                continue;
                            }
                            
                            $conn->text('sys: reload');
                        }
                    } else if (trim($messageParts[1]) == 'die') {
                        $server->stop();
                    }
                }
            }
        })->start();

        return Command::SUCCESS;
    }
}