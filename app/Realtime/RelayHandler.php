<?php

namespace App\Realtime;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Websocket\Client\WebsocketHandshake;
use Amp\Websocket\Server\WebsocketClientHandler;
use Amp\Websocket\WebsocketClient;
use App\Http\Controllers\RealtimeController;
use Psr\Log\LoggerInterface;

use function Amp\async;
use function Amp\Websocket\Client\connect;

/**
 * ブラウザ 1 接続ごとに呼ばれ、OpenAI Realtime API との間を双方向に中継する。
 *
 * ポイント:本物の APIキーはこのサーバー側だけで使う。ブラウザは中継サーバーにしか
 * 繋がず、OpenAI のキーを一切知らない。中継自体は「素通しのパイプ」で、
 * セッション設定(voice/VAD/文字起こし等)や音声はブラウザ↔OpenAI がそのまま流れる。
 */
class RelayHandler implements WebsocketClientHandler
{
    public function __construct(
        private readonly string $apiKey,
        private readonly LoggerInterface $logger,
        private readonly string $defaultModel,
    ) {
    }

    public function handleClient(WebsocketClient $client, Request $request, Response $response): void
    {
        // 1. ブラウザが ?model=... で指定したモデルを取り出す(未知の値は既定にフォールバック)
        parse_str($request->getUri()->getQuery(), $params);
        $model = $params['model'] ?? $this->defaultModel;
        if (! in_array($model, RealtimeController::MODELS, true)) {
            $model = $this->defaultModel;
        }
        $this->logger->info("ブラウザ接続 → OpenAI (model={$model})");

        // 2. 本物の APIキーで OpenAI に WebSocket 接続する
        //    ※ GA API では 'OpenAI-Beta: realtime=v1' ヘッダは付けない
        //      (付けると「Beta API は廃止。GA の /v1/realtime を使え」と拒否される)
        $handshake = (new WebsocketHandshake('wss://api.openai.com/v1/realtime?model=' . rawurlencode($model)))
            ->withHeader('Authorization', 'Bearer ' . $this->apiKey);

        try {
            $openai = connect($handshake);
        } catch (\Throwable $e) {
            $this->logger->error('OpenAI 接続失敗: ' . $e->getMessage());
            $client->sendText(json_encode([
                'type'  => 'relay.error',
                'error' => 'OpenAI への接続に失敗しました: ' . $e->getMessage(),
            ]));
            $client->close();
            return;
        }

        // 3. OpenAI → ブラウザ を別コルーチンで中継
        $pump = async(function () use ($openai, $client): void {
            try {
                while ($message = $openai->receive()) {
                    $client->sendText($message->buffer());
                }
            } catch (\Throwable $e) {
                $this->logger->error('OpenAI 受信エラー: ' . $e->getMessage());
            } finally {
                if (! $client->isClosed()) {
                    $client->close();
                }
            }
        });

        // 4. ブラウザ → OpenAI をこのコルーチンで中継(ブラウザが切れるまでブロック)
        try {
            while ($message = $client->receive()) {
                $openai->sendText($message->buffer());
            }
        } catch (\Throwable $e) {
            $this->logger->error('ブラウザ 受信エラー: ' . $e->getMessage());
        } finally {
            if (! $openai->isClosed()) {
                $openai->close();
            }
        }

        // OpenAI→ブラウザ側のコルーチンの後始末を待つ
        $pump->await();
    }
}
