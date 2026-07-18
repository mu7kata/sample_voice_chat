# OpenAI Realtime 音声チャット(Laravel + Docker デモ)

ブラウザで話しかけると、OpenAI の Realtime API が音声で返事する最小デモです。
性能比較のため、モデルや各種パラメータを画面から切り替えられます。

## 仕組み(WebSocket 中継方式)

```
[ブラウザ] ⇄ WebSocket ⇄ [Laravel 中継サーバー] ⇄ WebSocket ⇄ [OpenAI Realtime]
   :8000(画面)              realtime:relay :8081        本物のAPIキーで接続
```

- **本物の APIキー(`sk-...`)は中継サーバー側だけ**で使う。ブラウザは中継サーバーにしか
  繋がず、OpenAI のキーを一切知らない。
- 中継サーバーは**素通しのパイプ**。ブラウザが送る `session.update` や音声、OpenAI が返す
  音声・字幕イベントを、そのまま双方向に流すだけ。
- WebSocket ではメディアストリームを直接送れないため、**音声はブラウザ側で手動処理**:
  - 送信: マイク音声 → PCM16(24kHz mono)→ base64 →`input_audio_buffer.append`
  - 受信: `response.output_audio.delta`(base64 PCM16)→ 復号 → Web Audio API で再生

### 主なファイル

| ファイル | 役割 |
|---|---|
| `app/Console/Commands/RealtimeRelay.php` | 中継 WebSocket サーバーの起動コマンド(`realtime:relay`) |
| `app/Realtime/RelayHandler.php` | ブラウザ↔OpenAI を双方向に中継する本体(本物キーで OpenAI に接続) |
| `app/Http/Controllers/RealtimeController.php` | 画面表示(モデル/音声の候補を渡す) |
| `resources/views/voice.blade.php` | 画面(設定パネル + 字幕) |
| `public/js/realtime.js` | WebSocket クライアント + 音声の PCM16 変換/再生 |

中継サーバーは **amphp**(`amphp/websocket-server` + `amphp/websocket-client`)で実装。
PHP 拡張の追加は `pcntl`(終了シグナル処理)のみで、Docker に同梱済み。

## セットアップ

### 1. APIキーを設定

`platform.openai.com` で発行したキーを `.env` に入れる(Realtime 利用には課金設定が必要):

```
OPENAI_API_KEY=sk-xxxxxxxx...
```

> `.env` は Git 管理外なので、キーが誤ってコミットされる心配はありません。

### 2. 起動(2つのサービスが立ち上がる)

```bash
docker compose up -d --build
```

- `app`   … 画面(http://localhost:8000)
- `relay` … 中継 WebSocket サーバー(ws://localhost:8081)

> `relay` は `OPENAI_API_KEY` が未設定だと起動時に終了します。必ずキーを入れてから起動してください。

ブラウザで **http://localhost:8000** を開く。
(マイクは `localhost` なら HTTPS 不要で使えます)

### 3. 使い方

1. 設定(モデル・音声・VAD など)を選ぶ
2. 「接続して話す」を押してマイクを許可
3. 話しかけると AI が音声で返事し、会話が字幕にも出る

> `.env` を編集したら反映のため `docker compose restart` を実行。

## 画面から変えられるパラメータ(性能検証用)

| 項目 | 説明 |
|---|---|
| **モデル** | `gpt-realtime-2.1`(最新)〜 `gpt-4o-realtime-preview`(旧世代)まで比較可能 |
| **voice** | AI の声(marin / cedar / alloy …) |
| **temperature** | 応答のばらつき(0.6〜1.2)。※チェックを入れた時だけ送信。推論系モデルは無視することあり |
| **ターン検出(VAD)** | 「話し終わった」の判定方法。応答の速さ・割り込みに直結 |
| ┗ `server_vad` | 無音で判定。`threshold` / `silence_ms` / `prefix_ms` を調整 |
| ┗ `semantic_vad` | 発話内容で判定。`eagerness`(low〜high)を調整 |
| ┗ `none` | 自動検出オフ(手動制御・上級者向け) |
| **文字起こし** | 入力音声の文字起こしモデル(あなたの発話を字幕に出すのに必要) |
| **instructions** | AI への指示(人格・口調・言語など) |

設定は接続時に `session.update` としてまとめて OpenAI に送られます。

## 中継サーバーだけを動かす / ログを見る

```bash
# 中継サーバーのログを追う
docker compose logs -f relay

# ローカル(Docker外)で直接動かす場合
php artisan realtime:relay --port=8081
```

## 停止

```bash
docker compose down
```

## トラブルシューティング

- **「中継サーバーに接続できません」** → `relay` が起動しているか確認
  (`docker compose ps`)。キー未設定だと `relay` は即終了します。
- **「OpenAIエラー: invalid_api_key」** → `.env` のキーが不正。正しいキーにして
  `docker compose restart relay`。
- **マイクが使えない** → ブラウザのマイク許可を確認。`http://localhost:8000` でアクセスすること。
- **音が途切れる/返らない** → 折りたたみの「生イベントログ」に `event:` が流れていれば
  接続は成功。`response.output_audio.delta` が来ていれば音声は届いています(ブラウザの音量を確認)。
