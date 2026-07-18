<?php

namespace App\Http\Controllers;

class RealtimeController extends Controller
{
    /** UI のプルダウンに出す、選択可能なモデル一覧(2026年時点の主要Realtimeモデル)。 */
    public const MODELS = [
        'gpt-realtime-2.1',        // 最新・高性能(推論つき)
        'gpt-realtime-2.1-mini',   // 高速・低コスト
        'gpt-realtime-2',          // GPT-5級の推論
        'gpt-realtime',            // GA エイリアス
        'gpt-realtime-mini',
        'gpt-4o-realtime-preview', // 旧世代(比較用)
        'gpt-4o-mini-realtime-preview',
    ];

    /** 選択可能な音声。 */
    public const VOICES = [
        'marin', 'cedar', 'alloy', 'ash', 'ballad',
        'coral', 'echo', 'sage', 'shimmer', 'verse',
    ];

    /**
     * 音声チャットの画面を表示する。
     * モデル/音声の候補と既定値を Blade に渡す。
     *
     * ※ WebSocket 中継構成では、本物のキーは中継サーバー(php artisan realtime:relay)
     *   側だけで使う。ブラウザは中継サーバーに WS 接続するだけなので、
     *   このコントローラは短命トークンの発行を行わない(画面表示のみ)。
     */
    public function index()
    {
        return view('voice', [
            'models'       => self::MODELS,
            'voices'       => self::VOICES,
            'defaultModel' => config('services.openai.realtime_model'),
            'defaultVoice' => config('services.openai.realtime_voice'),
            'relayPort'    => (int) env('RELAY_PORT', 8081),
        ]);
    }
}
