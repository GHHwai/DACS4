<?php

namespace App\Console\Commands;

use App\Chat\Gateway;
use Illuminate\Console\Command;

class ChatServe extends Command
{
    protected $signature = 'chat:serve {--host= : Interface to bind (default CHAT_TCP_HOST)} {--port= : Port to bind (default CHAT_TCP_PORT)}';

    protected $description = 'Run the real-time chat gateway (raw TCP + WebSocket on one port)';

    public function handle(Gateway $gateway): int
    {
        $host = $this->option('host') ?: config('chat.host');
        $port = (int) ($this->option('port') ?: config('chat.port'));

        $gateway->onLog(fn (string $line) => $this->line($line));

        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            foreach ([SIGINT, SIGTERM] as $signal) {
                pcntl_signal($signal, fn () => $gateway->stop());
            }
        }

        try {
            $gateway->run($host, $port);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Gateway stopped.');

        return self::SUCCESS;
    }
}
