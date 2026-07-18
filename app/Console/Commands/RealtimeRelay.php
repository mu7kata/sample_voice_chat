<?php

namespace App\Console\Commands;

use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\SocketHttpServer;
use Amp\Websocket\Server\Rfc6455Acceptor;
use Amp\Websocket\Server\Websocket;
use App\Realtime\RelayHandler;
use Illuminate\Console\Command;
use Psr\Log\AbstractLogger;

use function Amp\trapSignal;

/**
 * ブラウザ↔OpenAI Realtime API を中継する WebSocket サーバーを常駐起動する。
 *
 *   php artisan realtime:relay            # 既定 0.0.0.0:8081
 *   php artisan realtime:relay --port=9000
 */
class RealtimeRelay extends Command
{
    protected $signature = 'realtime:relay {--host=0.0.0.0} {--port=8081}';

    protected $description = 'ブラウザ↔OpenAI Realtime API を中継する WebSocket サーバー';

    public function handle(): int
    {
        $apiKey = config('services.openai.key');
        if (empty($apiKey)) {
            $this->error('OPENAI_API_KEY が未設定です。.env に本物のキーを入れてください。');
            return self::FAILURE;
        }

        $host  = (string) $this->option('host');
        $port  = (int) $this->option('port');
        $model = config('services.openai.realtime_model');

        // amphp は PSR ロガーを要求するので、コマンド出力へ流す簡易ロガーを渡す
        $logger = new class($this) extends AbstractLogger {
            public function __construct(private Command $cmd)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->cmd->line("[{$level}] {$message}");
            }
        };

        $server = SocketHttpServer::createForDirectAccess($logger);
        $server->expose("{$host}:{$port}");

        $handler   = new RelayHandler($apiKey, $logger, $model);
        $websocket = new Websocket($server, $logger, new Rfc6455Acceptor(), $handler);

        $server->start($websocket, new DefaultErrorHandler());

        $this->info("Realtime 中継サーバー起動: ws://{$host}:{$port}  (既定モデル: {$model})");
        $this->line('Ctrl+C で停止します。');

        // 終了シグナルが来るまでイベントループを回し続ける
        trapSignal([SIGINT, SIGTERM]);

        $server->stop();

        return self::SUCCESS;
    }
}
