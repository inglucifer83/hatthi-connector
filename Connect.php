<?php

namespace Hatthi\Connector\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use WebSocket\Client;
use WebSocket\Connection;
use WebSocket\Message\Message;
use WebSocket\Middleware\CloseHandler;
use WebSocket\Middleware\PingResponder;
use ZipArchive;

class Connect extends Command {
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hatthi:connect';

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
        $client = new Client("wss://ws.martipansapte.com:8180");
        $client->setTimeout(86400);
        $client
            // Add standard middlewares
            ->addMiddleware(new CloseHandler())
            ->addMiddleware(new PingResponder())
            // Listen to incoming Text messages
            ->onText(function (Client $client, Connection $connection, Message $message) {
                $decodedMessage = json_decode($message->getContent(), true);
                if ($decodedMessage['data'] == 'auth') {
                    $message = ['id' => 1, 'secret' => env('HATTHI_SECRET', '')];
                    $client->text(json_encode($message));
                } else if (is_array($decodedMessage['data']) && isset($decodedMessage['data']['action']) && $decodedMessage['data']['action'] == 'save') {
                    $zipContents = file_get_contents($decodedMessage['data']['files']);

                    file_put_contents(storage_path("app/data/update.zip"), $zipContents);
                    $zip = new ZipArchive;
                    $zip->open(storage_path("app/data/update.zip"));
                    $zip->extractTo(storage_path("app/data/update/"));
                    $zip->close();

                    $this->line('Downloaded updated Zip');
                    $disk = Storage::build(['driver' => 'local', 'root' => base_path("storage/app/data/update")]);
                    $updatedFiles = $disk->files("/", true);
                    foreach ($updatedFiles as $file) {
                        File::copy($disk->path($file), $file);
                        $this->line('Updated ' .basename($file));
                    }
                } else {
                    $this->line($message->getContent());
                }
            })->start();

        return Command::SUCCESS;
    }
}
