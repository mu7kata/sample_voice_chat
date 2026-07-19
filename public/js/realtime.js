// OpenAI Realtime API に「中継サーバー経由」で WebSocket 接続するクライアント。
//
//   ブラウザ ⇄ Laravel中継(realtime:relay) ⇄ OpenAI
//
// 本物のAPIキーは中継サーバー側だけ。ブラウザは中継サーバーにしか繋がない。
//
// WebSocket ではメディアストリームを直接送れないので、音声は手動で扱う:
//   送信: マイク音声 → PCM16(24kHz mono) → base64 → input_audio_buffer.append
//   受信: response.output_audio.delta(base64 PCM16) → 復号 → Web Audio で再生

const connectBtn = document.getElementById('connect');
const disconnectBtn = document.getElementById('disconnect');
const statusEl = document.getElementById('status');
const logEl = document.getElementById('log');
const transcriptEl = document.getElementById('transcript');
const RELAY_PORT = document.querySelector('meta[name="relay-port"]').content;

let ws = null;             // 中継サーバーへの WebSocket
let micStream = null;      // マイク
let inputCtx = null;       // マイク取り込み用 AudioContext(24kHz)
let processor = null;      // 音声を PCM16 に切り出す ScriptProcessor
let outputCtx = null;      // 再生用 AudioContext(24kHz)
let playHead = 0;          // 次に再生を始める時刻(連続再生のスケジュール用)
let scheduledSources = []; // 再生予約中の音源(割り込み時に止める)
const bubbles = new Map(); // item_id -> { el, textEl, fullText } 字幕の吹き出し

// 再生中のAIアイテムの追跡(バージイン時のtruncate・字幕の再生同期に使う)
let curItemId = null;      // 再生中のAIアイテムの item_id
let curItemStart = null;   // そのアイテムの音声が鳴り始める時刻(outputCtx 時間・秒)
let curItemMs = 0;         // そのアイテムの受信済み音声の合計(ミリ秒)
let captionRAF = null;     // 字幕を再生に同期させる requestAnimationFrame ハンドル

const SAMPLE_RATE = 24000;

function setStatus(text, kind = '') {
    statusEl.textContent = text;
    statusEl.className = 'status' + (kind ? ' ' + kind : '');
}

function log(...args) {
    const line = args.map(a => typeof a === 'string' ? a : JSON.stringify(a)).join(' ');
    logEl.textContent += line + '\n';
    logEl.scrollTop = logEl.scrollHeight;
    console.log(...args);
}

// ===== 音声の変換ユーティリティ =====
function base64FromBytes(bytes) {
    let binary = '';
    const chunk = 0x8000;
    for (let i = 0; i < bytes.length; i += chunk) {
        binary += String.fromCharCode.apply(null, bytes.subarray(i, i + chunk));
    }
    return btoa(binary);
}
function bytesFromBase64(b64) {
    const binary = atob(b64);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
    return bytes;
}
function floatToPCM16(float32) {
    const out = new Int16Array(float32.length);
    for (let i = 0; i < float32.length; i++) {
        const s = Math.max(-1, Math.min(1, float32[i]));
        out[i] = s < 0 ? s * 0x8000 : s * 0x7fff;
    }
    return out;
}

// ===== AI音声の再生(チャンクを隙間なくスケジュール)=====
function playPCM16(b64, itemId) {
    if (!outputCtx) return;

    // 新しいAIアイテムの音声が始まったら追跡をリセット
    if (itemId && itemId !== curItemId) {
        curItemId = itemId;
        curItemStart = null;
        curItemMs = 0;
    }

    const bytes = bytesFromBase64(b64);
    const int16 = new Int16Array(bytes.buffer, bytes.byteOffset, Math.floor(bytes.byteLength / 2));
    const float32 = new Float32Array(int16.length);
    for (let i = 0; i < int16.length; i++) float32[i] = int16[i] / 32768;

    const buf = outputCtx.createBuffer(1, float32.length, SAMPLE_RATE);
    buf.getChannelData(0).set(float32);
    const src = outputCtx.createBufferSource();
    src.buffer = buf;
    src.connect(outputCtx.destination);

    const now = outputCtx.currentTime;
    if (playHead < now) playHead = now;
    if (curItemStart === null) curItemStart = playHead; // 最初のチャンクが鳴り始める時刻
    src.start(playHead);
    playHead += buf.duration;
    curItemMs += buf.duration * 1000;

    scheduledSources.push(src);
    src.onended = () => { scheduledSources = scheduledSources.filter(s => s !== src); };
}
function stopPlayback() {
    scheduledSources.forEach(s => { try { s.stop(); } catch (e) {} });
    scheduledSources = [];
    playHead = 0;
}

// バージイン:実際に再生できた ms だけをモデルの記憶に残す(超えた分は消える)。
// これを送らないと、モデルは「ユーザーが聞いていない発話」を前提に会話を続けてしまう。
function truncateCurrentItem() {
    if (!curItemId || curItemStart === null || !outputCtx) return;
    const elapsedMs = (outputCtx.currentTime - curItemStart) * 1000;
    const playedMs = Math.max(0, Math.min(elapsedMs, curItemMs));
    if (ws && ws.readyState === WebSocket.OPEN) {
        ws.send(JSON.stringify({
            type: 'conversation.item.truncate',
            item_id: curItemId,
            content_index: 0,
            audio_end_ms: Math.floor(playedMs),
        }));
        log('truncate:', curItemId, Math.floor(playedMs) + 'ms');
    }
    // 字幕は「聞こえたところ」で確定(それ以上は表示しない)
    const b = bubbles.get(curItemId);
    if (b) b.el.classList.remove('pending');
    curItemId = null;
}

// 字幕をAIの発話(再生)タイミングに合わせて少しずつ表示する。
// 文字起こしは音声より早く全部届くので、再生位置に応じて出す文字数を増やす。
function tickCaptions() {
    captionRAF = requestAnimationFrame(tickCaptions);
    if (!outputCtx || !curItemId || curItemStart === null || curItemMs <= 0) return;
    const b = bubbles.get(curItemId);
    if (!b || b.fullText == null) return;

    const playedMs = Math.max(0, Math.min((outputCtx.currentTime - curItemStart) * 1000, curItemMs));
    const ratio = playedMs / curItemMs;
    // 受信音声が増えると比率が下がり文字が戻ることがあるので、表示文字数は単調増加にする
    const n = Math.floor(b.fullText.length * ratio);
    b._shownLen = Math.max(b._shownLen || 0, n);
    b.textEl.textContent = b.fullText.slice(0, b._shownLen);
    transcriptEl.scrollTop = transcriptEl.scrollHeight;

    // 音声を全部受信し終え、再生も追いついたら全文を確定表示
    if (b._audioDone && playedMs >= curItemMs - 1) {
        b.textEl.textContent = b.fullText;
        b._shownLen = b.fullText.length;
        b.el.classList.remove('pending');
    }
}

// ===== 字幕(会話の文字起こし)=====
function getBubble(itemId, role) {
    let b = bubbles.get(itemId);
    if (!b) {
        const el = document.createElement('div');
        el.className = 'bubble pending ' + role;
        const who = document.createElement('span');
        who.className = 'who';
        who.textContent = role === 'user' ? 'あなた' : 'AI';
        const text = document.createElement('span');
        el.append(who, text);
        transcriptEl.appendChild(el);
        b = { el, textEl: text };
        bubbles.set(itemId, b);
    }
    transcriptEl.scrollTop = transcriptEl.scrollHeight;
    return b;
}
function handleRealtimeEvent(evt) {
    const t = evt.type || '';

    // 会話アイテムが作られたら、空の吹き出しを「その順番で」先に確保しておく。
    // これで文字起こし(特にあなたの発話)が遅れて届いても、表示順が入れ替わらない。
    if (t === 'conversation.item.added' || t === 'conversation.item.created') {
        const item = evt.item || {};
        if (item.id && (item.role === 'user' || item.role === 'assistant')) {
            getBubble(item.id, item.role === 'user' ? 'user' : 'ai');
        }
        return;
    }

    // あなたの発話(入力音声の文字起こし)。要:入力文字起こしを有効化。
    if (t.includes('input_audio_transcription')) {
        const b = getBubble(evt.item_id, 'user');
        if (t.endsWith('.delta')) {
            b.textEl.textContent += evt.delta || '';
        } else { // .completed / .done
            if (evt.transcript != null) b.textEl.textContent = evt.transcript;
            b.el.classList.remove('pending');
        }
        return;
    }

    // AI の発話(出力音声の文字起こし)。音声より早く全文が届くので、
    // ここでは fullText に貯めるだけ。実際の表示は tickCaptions が再生に同期して行う。
    if (t.includes('audio_transcript')) {
        const b = getBubble(evt.item_id, 'ai');
        if (b.fullText == null) b.fullText = '';
        if (t.endsWith('.delta')) {
            b.fullText += evt.delta || '';
        } else if (t.endsWith('.done')) {
            if (evt.transcript != null) b.fullText = evt.transcript;
        }
        return;
    }

    // AIアイテムの音声を全部受信し終えた合図(字幕の全文確定に使う)
    if (t === 'response.output_audio.done') {
        const b = bubbles.get(evt.item_id);
        if (b) b._audioDone = true;
        return;
    }
}

// ===== 中継サーバーから届くイベントの処理 =====
function onServerEvent(e) {
    let evt;
    try { evt = JSON.parse(e.data); } catch (err) { return; }
    const t = evt.type || '';

    // 音声deltaは大量に来るのでログには出さない
    if (!t.endsWith('audio.delta')) log('event:', t);

    // 中継サーバーからのエラー(OpenAI接続失敗など)
    if (t === 'relay.error') {
        setStatus('エラー: ' + evt.error, 'error');
        log('ERROR:', evt.error);
        return;
    }

    // OpenAI から返るエラーイベント(APIキー不正・パラメータ不正など)
    if (t === 'error') {
        const msg = evt.error?.message || JSON.stringify(evt.error);
        setStatus('OpenAIエラー: ' + msg, 'error');
        log('OpenAI ERROR:', msg);
        return;
    }

    // AIの音声チャンク(GA: response.output_audio.delta / 旧: response.audio.delta)
    if (t.endsWith('audio.delta') && evt.delta) {
        playPCM16(evt.delta, evt.item_id);
        return;
    }

    // 割り込み:こちらが話し始めたら、聞こえた分をtruncateで確定してから再生を止める
    if (t === 'input_audio_buffer.speech_started') {
        truncateCurrentItem();
        stopPlayback();
    }

    // 字幕
    handleRealtimeEvent(evt);
}

// ===== 画面の設定 → session.update を組み立てる =====
function buildSessionUpdate(s) {
    const session = {
        type: 'realtime',
        instructions: s.instructions,
        audio: {
            input: {},
            output: { voice: s.voice },
        },
    };
    if (s.temperature != null) session.temperature = s.temperature;

    if (s.turn_detection_type === 'server_vad') {
        session.audio.input.turn_detection = {
            type: 'server_vad',
            threshold: s.vad_threshold,
            prefix_padding_ms: s.vad_prefix_ms,
            silence_duration_ms: s.vad_silence_ms,
        };
    } else if (s.turn_detection_type === 'semantic_vad') {
        session.audio.input.turn_detection = { type: 'semantic_vad', eagerness: s.vad_eagerness };
    } else {
        session.audio.input.turn_detection = null;
    }

    if (s.transcription_model) {
        session.audio.input.transcription = { model: s.transcription_model };
    }

    return { type: 'session.update', session };
}

// --- スライダーの現在値を隣に表示する ---
for (const id of ['temperature', 'vad_threshold', 'vad_silence_ms', 'vad_prefix_ms']) {
    const input = document.getElementById(id);
    const out = document.getElementById(id + '-val');
    if (input && out) {
        const sync = () => { out.textContent = input.value; };
        input.addEventListener('input', sync);
        if (id !== 'temperature') sync();
    }
}

// --- VAD の種類に応じて表示するパラメータを切り替える ---
const vadTypeEl = document.getElementById('turn_detection_type');
function updateVadFields() {
    const t = vadTypeEl.value;
    document.querySelectorAll('.vad-server').forEach(el => {
        el.style.display = (t === 'server_vad') ? '' : 'none';
    });
    document.querySelectorAll('.vad-semantic').forEach(el => {
        el.style.display = (t === 'semantic_vad') ? '' : 'none';
    });
}
vadTypeEl.addEventListener('change', updateVadFields);
updateVadFields();

// --- 画面の設定を集める ---
function collectSettings() {
    const body = {
        model: document.getElementById('model').value,
        voice: document.getElementById('voice').value,
        instructions: document.getElementById('instructions').value,
        turn_detection_type: vadTypeEl.value,
    };
    if (document.getElementById('temperature-enabled').checked) {
        body.temperature = parseFloat(document.getElementById('temperature').value);
    }
    if (vadTypeEl.value === 'server_vad') {
        body.vad_threshold = parseFloat(document.getElementById('vad_threshold').value);
        body.vad_silence_ms = parseInt(document.getElementById('vad_silence_ms').value, 10);
        body.vad_prefix_ms = parseInt(document.getElementById('vad_prefix_ms').value, 10);
    } else if (vadTypeEl.value === 'semantic_vad') {
        body.vad_eagerness = document.getElementById('vad_eagerness').value;
    }
    const trans = document.getElementById('transcription_model').value;
    if (trans) body.transcription_model = trans;
    return body;
}

async function connect() {
    connectBtn.disabled = true;
    setStatus('中継サーバーに接続中…');

    // 前回の字幕をクリア
    transcriptEl.innerHTML = '';
    bubbles.clear();

    try {
        const settings = collectSettings();

        // 1. 中継サーバーへ WebSocket 接続(本物キーはサーバー側)
        const relayUrl = `ws://${location.hostname}:${RELAY_PORT}/?model=` + encodeURIComponent(settings.model);
        ws = new WebSocket(relayUrl);
        ws.addEventListener('message', onServerEvent);
        ws.addEventListener('close', () => log('中継サーバーとの接続が切れました'));

        await new Promise((resolve, reject) => {
            ws.addEventListener('open', resolve, { once: true });
            ws.addEventListener('error', () => reject(new Error('中継サーバーに接続できません(realtime:relay は起動していますか?)')), { once: true });
        });
        log('中継サーバーに接続 / model =', settings.model);

        // 2. セッション設定を送る(voice/VAD/文字起こし/instructions)
        ws.send(JSON.stringify(buildSessionUpdate(settings)));

        // 3. 再生用コンテキストを用意(ボタン操作直後なので自動再生ポリシーOK)
        outputCtx = new AudioContext({ sampleRate: SAMPLE_RATE });
        await outputCtx.resume();
        playHead = 0;
        curItemId = null;
        curItemStart = null;
        curItemMs = 0;
        // 字幕を再生に同期させるループを開始
        if (captionRAF) cancelAnimationFrame(captionRAF);
        captionRAF = requestAnimationFrame(tickCaptions);

        // 4. マイクを取得して PCM16 で送信
        setStatus('マイク取得中…');
        micStream = await navigator.mediaDevices.getUserMedia({ audio: true });
        inputCtx = new AudioContext({ sampleRate: SAMPLE_RATE });
        await inputCtx.resume();

        const source = inputCtx.createMediaStreamSource(micStream);
        processor = inputCtx.createScriptProcessor(4096, 1, 1);
        source.connect(processor);
        // ScriptProcessor は destination に繋がないと発火しないため、
        // 音量0の gain を経由(自分の声が返らないように)
        const mute = inputCtx.createGain();
        mute.gain.value = 0;
        processor.connect(mute);
        mute.connect(inputCtx.destination);

        processor.onaudioprocess = (ev) => {
            if (!ws || ws.readyState !== WebSocket.OPEN) return;
            const input = ev.inputBuffer.getChannelData(0);
            const pcm = floatToPCM16(input);
            const b64 = base64FromBytes(new Uint8Array(pcm.buffer));
            ws.send(JSON.stringify({ type: 'input_audio_buffer.append', audio: b64 }));
        };

        setStatus('接続中 — 話しかけてください', 'live');
        disconnectBtn.disabled = false;
        log('接続完了。マイクに話しかけてみてください。');
    } catch (err) {
        console.error(err);
        setStatus('エラー: ' + err.message, 'error');
        log('ERROR:', err.message);
        cleanup();
        connectBtn.disabled = false;
    }
}

function cleanup() {
    if (captionRAF) { cancelAnimationFrame(captionRAF); captionRAF = null; }
    if (processor) { processor.onaudioprocess = null; try { processor.disconnect(); } catch (e) {} processor = null; }
    if (micStream) { micStream.getTracks().forEach(t => t.stop()); micStream = null; }
    if (inputCtx) { inputCtx.close(); inputCtx = null; }
    stopPlayback();
    curItemId = null;
    curItemStart = null;
    curItemMs = 0;
    if (outputCtx) { outputCtx.close(); outputCtx = null; }
    if (ws) { try { ws.close(); } catch (e) {} ws = null; }
}

function disconnect() {
    cleanup();
    setStatus('未接続');
    connectBtn.disabled = false;
    disconnectBtn.disabled = true;
    log('切断しました。');
}

connectBtn.addEventListener('click', connect);
disconnectBtn.addEventListener('click', disconnect);
