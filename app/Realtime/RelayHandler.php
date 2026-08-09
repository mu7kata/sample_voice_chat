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
 * 繋がず、OpenAI のキーを一切知らない。中継は「素通しのパイプ」。
 * セッションの初期設定(instructions 等の既定値)はサーバー側から送るが、
 * ブラウザが session.update で instructions を送れば、それが上書きする。
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

        // 3. セッションの初期設定(既定値)をサーバー側から送る。
        //    instructions/voice/VAD/文字起こしの既定。ブラウザが後続の session.update で
        //    これらを送れば上書きされる(instructions もブラウザから変更可)。
        $openai->sendText(json_encode([
            'type'    => 'session.update',
            'session' => [
                'type'         => 'realtime',
                'instructions' => '日本語で話して', // 人格・口調
                'audio'        => [
                    'output' => ['voice' => 'marin'],          // 声色
                    'input'  => [
                        'turn_detection' => [                  // 発話の区切り(VAD)
                            'type' => 'server_vad',
                            'silence_duration_ms' => 500,      // 0.5秒黙ったら話し終わり
                        ],
                        'transcription' => ['model' => 'gpt-4o-mini-transcribe'], // 字幕用の文字起こし
                    ],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE));

        // 4. OpenAI → ブラウザ を別コルーチンで中継
        $pump = async(function () use ($openai, $client): void {
            try {
                while ($message = $openai->receive()) {
                    $payload = $message->buffer();
                    $this->logConversation($payload);
                    $client->sendText($payload);
                }
            } catch (\Throwable $e) {
                $this->logger->error('OpenAI 受信エラー: ' . $e->getMessage());
            } finally {
                if (! $client->isClosed()) {
                    $client->close();
                }
            }
        });

        // 5. ブラウザ → OpenAI をこのコルーチンで中継(ブラウザが切れるまでブロック)
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

    /**
     * 中継は素通しのまま、会話のアクションをサーバー側でも観測する。
     * WebSocket 中継構成の利点はここ:イベントがサーバーを通るので、
     * ログのほかに認証・利用制限・業務処理なども同じ場所に差し込める。
     */
    private function logConversation(string $json): void
    {
        // 音声チャンク(audio.delta)が大量に流れるため、対象イベントを含む時だけ解析する
        if (! str_contains($json, 'transcription.completed')
            && ! str_contains($json, 'audio_transcript.done')
            && ! str_contains($json, 'speech_started')
        ) {
            return;
        }

        $event = json_decode($json, true);

        match ($event['type'] ?? '') {
            // ユーザー発話の文字起こしが確定した
            'conversation.item.input_audio_transcription.completed' => $this->logger->info(
                '[会話] ユーザー: ' . trim($event['transcript'] ?? '')
            ),
            // AI発話の文字起こしが確定した
            'response.output_audio_transcript.done' => $this->logger->info(
                '[会話] AI: ' . trim($event['transcript'] ?? '')
            ),
            // ユーザーが話し始めた(AI発話中なら割り込み)
            'input_audio_buffer.speech_started' => $this->logger->info('[会話] ユーザー発話開始'),
            default => null,
        };
    }
}
