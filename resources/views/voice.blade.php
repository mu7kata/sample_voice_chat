<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- ブラウザが接続する中継WebSocketサーバーのポート --}}
    <meta name="relay-port" content="{{ $relayPort }}">
    <title>OpenAI Realtime 音声チャットサンプル</title>
    <style>
        :root { color-scheme: light dark; }
        body {
            font-family: system-ui, -apple-system, "Hiragino Sans", sans-serif;
            max-width: 720px; margin: 40px auto; padding: 0 20px; line-height: 1.7;
        }
        h1 { font-size: 1.4rem; }
        .status {
            display: inline-block; padding: 4px 12px; border-radius: 999px;
            font-size: .85rem; background: #8883; margin-bottom: 16px;
        }
        .status.live { background: #22c55e33; color: #16a34a; }
        .status.error { background: #ef444433; color: #dc2626; }
        fieldset {
            border: 1px solid #8884; border-radius: 12px; padding: 16px; margin: 0 0 20px;
        }
        legend { padding: 0 8px; font-weight: 600; font-size: .9rem; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .field { display: flex; flex-direction: column; gap: 4px; }
        .field.full { grid-column: 1 / -1; }
        label { font-size: .8rem; color: #888; }
        select, input, textarea {
            font: inherit; padding: 8px 10px; border-radius: 8px;
            border: 1px solid #8886; background: transparent; color: inherit;
        }
        textarea { resize: vertical; min-height: 60px; }
        .range-val { font-variant-numeric: tabular-nums; color: #2563eb; }
        button {
            font-size: 1rem; padding: 12px 24px; border-radius: 10px;
            border: none; cursor: pointer; margin-right: 8px;
        }
        #connect { background: #2563eb; color: #fff; }
        #disconnect { background: #8883; }
        button:disabled { opacity: .4; cursor: not-allowed; }
        #log {
            margin-top: 24px; padding: 12px; border-radius: 10px;
            background: #8881; font-size: .8rem; white-space: pre-wrap;
            max-height: 240px; overflow-y: auto;
        }
        .hint { color: #888; font-size: .85rem; }
        .vad-server, .vad-semantic { display: contents; }
        /* 会話(字幕)表示 */
        #transcript {
            margin-top: 20px; display: flex; flex-direction: column; gap: 10px;
            min-height: 60px;
        }
        .bubble {
            display: flex; gap: 8px; align-items: flex-start; max-width: 85%;
        }
        .bubble .avatar { font-size: 1.5rem; line-height: 1.3; flex: 0 0 auto; }
        .bubble .body {
            padding: 10px 14px; border-radius: 14px;
            white-space: pre-wrap; word-break: break-word; line-height: 1.5;
        }
        .bubble .who { display: block; font-size: .7rem; opacity: .6; margin-bottom: 2px; }
        .bubble.user { align-self: flex-end; flex-direction: row-reverse; }
        .bubble.ai   { align-self: flex-start; }
        .bubble.user .body { background: #2563eb22; border: 1px solid #2563eb55; }
        .bubble.ai   .body { background: #8882; border: 1px solid #8884; }
        .bubble.pending { opacity: .6; }
        details#raw { margin-top: 20px; }
        details#raw summary { cursor: pointer; color: #888; font-size: .85rem; }
        /* 折りたたみ設定 */
        details.settings {
            border: 1px solid #8884; border-radius: 12px; padding: 4px 16px 8px; margin: 0 0 20px;
        }
        details.settings > summary {
            font-weight: 600; font-size: .9rem; cursor: pointer; padding: 10px 0; list-style: none;
        }
        details.settings > summary::-webkit-details-marker { display: none; }
        details.settings > summary::before { content: '▸ '; }
        details.settings[open] > summary::before { content: '▾ '; }
        details.settings[open] { padding-bottom: 16px; }
        /* 心電図モニタ(左=あなた / 右=AI) */
        .monitors { display: flex; gap: 12px; margin: 16px 0 6px; }
        .monitor {
            flex: 1; display: flex; flex-direction: column; align-items: center; gap: 6px;
        }
        .monitor canvas {
            display: block; width: 100%; height: 90px;
            border-radius: 10px; background: #08120d;
        }
        .monitor .avatar { font-size: 1.6rem; line-height: 1; transition: transform .1s; }
        .monitor.active .avatar { transform: scale(1.18); }
        .monitor .mon-label { font-size: .72rem; color: #888; }
    </style>
</head>
<body>
    <h1>🎙️ OpenAI Realtime 音声チャットサンプル</h1>
    <p class="hint">設定を選んで「接続」を押し、マイクを許可して話しかけてください。AIが音声で返事します。</p>

    <details class="settings" open>
        <summary>設定(接続時に反映)</summary>
        <div class="grid">
            <div class="field">
                <label for="model">モデル</label>
                <select id="model">
                    @foreach ($models as $m)
                        <option value="{{ $m }}" @selected($m === $defaultModel)>{{ $m }}</option>
                    @endforeach
                </select>
            </div>

            <div class="field">
                <label for="voice">音声 (voice)</label>
                <select id="voice">
                    @foreach ($voices as $v)
                        <option value="{{ $v }}" @selected($v === $defaultVoice)>{{ $v }}</option>
                    @endforeach
                </select>
            </div>

            <div class="field">
                <label for="temperature">
                    temperature <span class="range-val" id="temperature-val">既定</span>
                </label>
                <input type="range" id="temperature" min="0.6" max="1.2" step="0.05" value="0.8">
                <label style="margin-top:2px">
                    <input type="checkbox" id="temperature-enabled"> 送信する(推論系モデルは無視されることあり)
                </label>
            </div>

            <div class="field">
                <label for="turn_detection_type">ターン検出 (VAD)</label>
                <select id="turn_detection_type">
                    <option value="server_vad" selected>server_vad(無音で判定)</option>
                    <option value="semantic_vad">semantic_vad(内容で判定)</option>
                    <option value="none">none(手動)</option>
                </select>
            </div>

            {{-- server_vad 用パラメータ --}}
            <div class="field vad-server">
                <label for="vad_threshold">
                    しきい値 threshold <span class="range-val" id="vad_threshold-val">0.5</span>
                </label>
                <input type="range" id="vad_threshold" min="0" max="1" step="0.05" value="0.5">
            </div>
            <div class="field vad-server">
                <label for="vad_silence_ms">
                    無音判定 silence_ms <span class="range-val" id="vad_silence_ms-val">500</span>
                </label>
                <input type="range" id="vad_silence_ms" min="0" max="2000" step="50" value="500">
            </div>
            <div class="field vad-server">
                <label for="vad_prefix_ms">
                    先読み prefix_ms <span class="range-val" id="vad_prefix_ms-val">300</span>
                </label>
                <input type="range" id="vad_prefix_ms" min="0" max="1000" step="50" value="300">
            </div>

            {{-- semantic_vad 用パラメータ --}}
            <div class="field vad-semantic" style="display:none">
                <label for="vad_eagerness">積極性 eagerness</label>
                <select id="vad_eagerness">
                    <option value="auto" selected>auto</option>
                    <option value="low">low(待つ)</option>
                    <option value="medium">medium</option>
                    <option value="high">high(すぐ応答)</option>
                </select>
            </div>

            <div class="field">
                <label for="transcription_model">入力の文字起こし(あなたの発話用)</label>
                <select id="transcription_model">
                    <option value="gpt-4o-mini-transcribe" selected>gpt-4o-mini-transcribe</option>
                    <option value="gpt-4o-transcribe">gpt-4o-transcribe</option>
                    <option value="whisper-1">whisper-1</option>
                    <option value="">なし(字幕にあなたの発話は出ません)</option>
                </select>
            </div>

            <div class="field full">
                <label for="instructions">指示 (instructions)</label>
                <textarea id="instructions" placeholder="空欄ならサーバー既定を使います">関西弁で、短く自然な話し言葉で答えてください。</textarea>
            </div>
        </div>
    </details>

    <div id="status" class="status">未接続</div>
    <div>
        <button id="connect">接続して話す</button>
        <button id="disconnect" disabled>切断</button>
    </div>

    {{-- 心電図モニタ。吹き出しと揃えて 左=AI / 右=あなた。自分の音声だけで振れる --}}
    <div class="monitors">
        <div class="monitor" id="mon-ai">
            <div class="avatar">🤖</div>
            <canvas id="viz-ai"></canvas>
            <div class="mon-label">AI</div>
        </div>
        <div class="monitor" id="mon-you">
            <div class="avatar">🧑</div>
            <canvas id="viz-you"></canvas>
            <div class="mon-label">あなた</div>
        </div>
    </div>

    {{-- 会話の字幕(あなた/AI の発話をテキスト表示) --}}
    {{-- ※ AI音声は Web Audio API で再生するため <audio> 要素は使わない --}}
    <div id="transcript"></div>

    {{-- デバッグ用の生イベントログ(折りたたみ) --}}
    <details id="raw">
        <summary>生イベントログ(デバッグ用)</summary>
        <div id="log"></div>
    </details>

    <script src="{{ asset('js/realtime.js') }}"></script>
</body>
</html>
