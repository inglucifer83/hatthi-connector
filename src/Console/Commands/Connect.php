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

    private function startArtisan() {
        $availablePorts = [];
        for ($i = 0; $i < 11; $i += 1) { 
            $port = 8000 + $i;
            $availablePorts[] = "$port";
        }

        $psProcess = Process::run("ps -o pid -o ppid -o args | grep 'php artisan serve'");
        $psLines = explode("\n", $psProcess->output());
        $runningProcesses = [];
        $foreignProcesses = [];
        foreach ($psLines as $procLine) {
            $cleanedLine = trim(preg_replace('/\s+/', ' ', $procLine));
            $columns = explode(' ', $cleanedLine);
            array_splice($columns, 2, count($columns), [implode(' ', array_slice($columns, 2, count($columns)))]);
            if ($columns[count($columns) - 1] == 'php artisan serve' && count($columns) > 1) {
                if ($columns[1] == 1) {
                    $runningProcesses[] = $columns[0];
                } else {
                    $foreignProcesses[] = $columns[0];
                }
            }
        }

        $currentAddress = '';

        if (count($runningProcesses) > 1) {
            $this->info('Restarting development server... ');
            foreach ($runningProcesses as $pid) {
                Process::run('kill -15 ' .$pid);
            }

            Process::run('php artisan serve &');
            sleep(2);

            $lsofProcess = Process::run('lsof -P -iTCP -sTCP:LISTEN');
            $lsofLines = explode("\n", $lsofProcess->output());

            
            foreach ($lsofLines as $index => $procLine) {
                if ($index == 0) {
                    continue;
                }
                $cleanedLine = trim(preg_replace('/\s+/', ' ', $procLine));
                $columns = explode(' ', $cleanedLine);
                if (Str::contains($columns[count($columns) - 2], $availablePorts) && !in_array($columns[1], $foreignProcesses)) {
                    $currentAddress = "http://{$columns[count($columns) - 2]}";
                }
            }
        }

        if ($currentAddress) {
            Process::run("open $currentAddress");
        }
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->trap([SIGTERM, SIGQUIT, SIGINT], function () {
            $client = new Client("ws://127.0.0.1:8080");
            $client->addMiddleware(new CloseHandler())->addMiddleware(new PingResponder());
            $client->text('sys: die');
            $client->close();
        });

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

                    $this->info('Sync file downloaded');
                    $disk = Storage::build(['driver' => 'local', 'root' => base_path("storage/app/data/sync")]);
                    $syncedFiles = $disk->files("/", true);

                    foreach ($syncedFiles as $file) {
                        if (Str::contains($file, 'composer')) {
                            continue;
                        }
                        File::move($disk->path($file), $file);
                        $this->line('Updated ' .basename($file));
                    }
                    
                    File::delete(storage_path("app/data/sync.zip"));
                    File::deleteDirectory(storage_path("app/data/sync/"));

                    $client = new Client("ws://127.0.0.1:8080");
                    $client->addMiddleware(new CloseHandler())->addMiddleware(new PingResponder());
                    $client->text('sys: synced');
                    $client->close();

                    //$this->startArtisan();
                } else {
                    $messageText = isset($decodedMessage['data']) ? $decodedMessage['data'] : null;
                    if ($messageText) {
                        if (Str::contains($messageText, 'Access Granted', true)) {
                            $messageParts = explode(':', $messageText);
                            $hasAddress = count($messageParts) > 1;
                            $address = $hasAddress ? trim(implode(':', array_slice($messageParts, 1))) : null;
                            $this->line('Connected!');
                            if ($address) {
                                $this->info('Logging in...');
                                //-a \"Opera\"
                                Process::run("open $address");
                                $this->info('Launching local WS server...');
                                Process::run('php artisan hatthi:listen &');
                                $this->info('...did it!');
                            }
                        } else {
                            $this->info($messageText);
                        }
                        
                    } else {
                        $this->error('Unknown Message Received');
                    }
                }
            })->start();

        return Command::SUCCESS;
    }
}
