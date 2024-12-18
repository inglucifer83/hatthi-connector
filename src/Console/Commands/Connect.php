<?php

namespace Hatthi\Connector\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use WebSocket\Client;
use WebSocket\Connection;
use WebSocket\Message\Message;
use WebSocket\Middleware\CloseHandler;
use WebSocket\Middleware\PingResponder;
use WebSocket\Server;
use ZipArchive;

class Connect extends Command {

    private static $serveProcs = [];
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
        $server = new Server(8080);
        $server->addMiddleware(new CloseHandler())
        ->addMiddleware(new PingResponder())
        ->onText(function(Server $server, Connection $connection, Message $message) {
            $this->line($message->getContent());
        })->start();

        $client = new Client("wss://ws.stx-software.com:8180");
        $client->setTimeout(86400);
        $client
            // Add standard middlewares
            ->addMiddleware(new CloseHandler())
            ->addMiddleware(new PingResponder())
            // Listen to incoming Text messages
            ->onText(function (Client $client, Connection $connection, Message $message) {
                $decodedMessage = json_decode($message->getContent(), true);
                if ($decodedMessage['data'] == 'auth') {
                    $message = ['id' => env('HATTHI_ID'), 'secret' => env('HATTHI_SECRET', '')];
                    $client->text(json_encode($message));
                } else if (is_array($decodedMessage['data']) && isset($decodedMessage['data']['action']) && $decodedMessage['data']['action'] == 'sync') {
                    $zipContents = file_get_contents($decodedMessage['data']['files']);

                    file_put_contents(storage_path("app/data/sync.zip"), $zipContents);

                    $zip = new ZipArchive;
                    $zip->open(storage_path("app/data/sync.zip"));
                    $zip->extractTo(storage_path("app/data/sync/"));
                    $zip->close();

                    $this->line('Zip file downloaded');
                    $disk = Storage::build(['driver' => 'local', 'root' => base_path("storage/app/data/sync")]);
                    $syncedFiles = $disk->files("/", true);

                    foreach ($syncedFiles as $file) {
                        File::move($disk->path($file), $file);
                        $this->line('Updated ' .basename($file));
                    }
                    
                    File::delete(storage_path("app/data/sync.zip"));
                    File::deleteDirectory(storage_path("app/data/sync/"));

                    foreach (self::$serveProcs as $proc) {
                        $proc->stop();
                    }

                    self::$serveProcs = [];

                    // $process = Process::start('php artisan serve', function($type, $output) {
                    //     $this->line('Artisan said: ' .$output);
                    //     if (Str::contains(strtolower($output), 'server running on')) {
                    //         $urlRe = '/\[(http:\/\/.*)\]/m';

                    //         preg_match_all($urlRe, strtolower($output), $matches, PREG_SET_ORDER, 0);

                    //         Process::run('open ');

                    //     }
                    // });

                    self::$serveProcs[] = $process;


                } else {
                    $this->line($message->getContent());
                }
            })->start();

        return Command::SUCCESS;
    }
}
