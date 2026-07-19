# OpenAI Realtime API 解説

音声を「聞いて・考えて・話す」を低遅延で1つのAPIで扱える仕組み。
このリポジトリのデモを題材に、**モデルの違い / セッションパラメータ / VAD(ターン検出) /
イベントの流れ / 認証** をまとめる。

> ⚠️ モデル名・料金・イベント名は更新が速い。数値は 2026年7月時点の目安。
> 最新は [公式ドキュメント](https://platform.openai.com/docs/guides/realtime) と
> [料金ページ](https://platform.openai.com/docs/pricing) で確認すること。

---

## 1. 何ができるか

- **音声↔音声**をリアルタイムに(テキストを介さず、話し言葉のニュアンスも保ったまま)。
- 会話の**割り込み(バージイン)**、**関数呼び出し(function calling)**、
  **文字起こし**、**翻訳**などに対応。
- 従来の「音声認識 → LLM → 音声合成」を1本にまとめ、**遅延と情報欠落を削減**したもの。

## 2. 接続方式は3つ

| 方式 | 誰が OpenAI に繋ぐ | 音声の扱い | 向き | 本デモ |
|---|---|---|---|---|
| **WebRTC** | ブラウザ/端末が直結 | メディアストリーム(自動) | ブラウザ音声アプリの本命。低遅延・音声処理を自動でやってくれる | 最初に実装(後にWSへ移行) |
| **WebSocket** | サーバー or ブラウザ | JSONイベントに base64 PCM を相乗り(手動) | サーバー中継・バックエンド統合向け | **現在の構成** |
| **SIP** | 電話網 | 電話 | 電話(PSTN)連携 | 未使用 |

### WebRTC と WebSocket の使い分け

- **WebRTC**: 音声のエンコード/デコード・ジッタ吸収をブラウザが自動処理。
  ブラウザから直接使うなら第一候補。キーは**短命トークン**でブラウザに渡す。
- **WebSocket**: 音声も自前で PCM16↔base64 変換が必要。代わりに
  **サーバーを経由できる**ので、キーを完全にサーバー側に隠す・会話をログする・
  ツールをサーバーで実行する、といった制御がしやすい。

> 本デモは「ブラウザ ⇄ Laravel中継 ⇄ OpenAI」の WebSocket 中継構成。
> 実装は [`app/Realtime/RelayHandler.php`](../app/Realtime/RelayHandler.php) と
> [`public/js/realtime.js`](../public/js/realtime.js)。

---

## 3. モデルの違い

会話用(音声↔音声)と、用途特化(翻訳・文字起こし)に分かれる。

### 会話モデル

| モデル | 特徴 | 向いている用途 |
|---|---|---|
| `gpt-realtime-2.1` | 最新・高品質。**推論あり**。英数字の聞き取り・無音/雑音処理・割り込みの自然さが向上 | 品質重視の音声エージェント本命 |
| `gpt-realtime-2.1-mini` | 2.1 の**蒸留版**。低遅延・低コスト | 品質とコストのバランス、量産用途 |
| `gpt-realtime-2` | **GPT-5級の推論**。難しい要求も会話で捌く | 高度な対話・複雑なタスク |
| `gpt-realtime` | GA の**エイリアス**(安定版を指す)。名前を固定したいとき | 迷ったらこれ |
| `gpt-realtime-mini` | 軽量会話 | 簡単な応答・コスト最優先 |
| `gpt-4o-realtime-preview` / `-mini` | **旧世代(preview)**。後方互換・比較用 | 既存コードの移行元、性能比較 |

### 用途特化モデル

| モデル | 用途 | 料金の考え方(目安) |
|---|---|---|
| `gpt-realtime-translate` | リアルタイム翻訳(70+入力言語 → 13出力言語) | **分課金**(例: ~$0.034/分)で予算が読みやすい |
| `gpt-realtime-whisper` / `gpt-4o-transcribe` / `gpt-4o-mini-transcribe` | ストリーミング文字起こし(入力の transcription にも使う) | **分課金**(例: whisper ~$0.017/分)。最安の入口 |

### 選び方の指針

1. **まず `gpt-realtime`(または `gpt-realtime-2.1`)** で品質を体感。
2. コスト・遅延が気になれば **`-mini` 系**に落として比較。
3. **料金の最大レバーは「AIの喋る長さ」**。出力音声トークンが高いので、
   `instructions` で「短く答えて」と指示するだけで実コストが大きく変わる
   (35秒/分喋るAIは15秒/分の約2倍という実測もある)。
4. 会話モデルは**トークン課金**(音声入出力トークン)、翻訳/文字起こしは**分課金**。
   予算を分で読みたいなら後者が楽。

> 本デモは画面のプルダウンでモデルを切り替えられる(性能比較用)。
> 候補は [`app/Http/Controllers/RealtimeController.php`](../app/Http/Controllers/RealtimeController.php) の `MODELS`。

---

## 4. セッション設定パラメータ

`session.update` イベント(または短命トークン発行時の `session`)で設定する。
主なもの:

| パラメータ | 位置 | 説明 |
|---|---|---|
| `model` | 接続時(URL の `?model=`) | 使用モデル。**接続後は変えられない**(切り替えは繋ぎ直し) |
| `instructions` | `session` 直下 | AIの人格・口調・言語・長さの指示。**コストと品質の要** |
| `voice` | `session.audio.output.voice` | AIの声。`marin` / `cedar`(新・自然)/ `alloy` / `ash` / `ballad` / `coral` / `echo` / `sage` / `shimmer` / `verse` |
| `temperature` | `session.temperature` | 応答のばらつき。**有効範囲 0.6〜1.2**。※推論系モデルは無視/非対応のことがある |
| `turn_detection` | `session.audio.input.turn_detection` | ターン検出(VAD)。→ 5章 |
| `transcription` | `session.audio.input.transcription` | 入力音声の文字起こしモデル(字幕・ログ用)。指定しないと**あなたの発話テキストは来ない** |
| 音声フォーマット | `session.audio.input/output` | 既定は PCM16・24kHz・モノラル(→ 6章) |
| `max_output_tokens` | `session` 直下 | 1応答の上限トークン。暴走・コスト抑制に |
| `tools` / `tool_choice` | `session` 直下 | function calling の定義 |

### voice と temperature の注意
- `voice` は**最初の音声応答が始まる前**に決める(途中変更は基本不可)。
- `temperature` は**推論モデル(2.x 系)だと効かない**ことがある。本デモでは
  「送信する」チェックを入れたときだけ送る作りにしてある。

---

## 5. VAD(ターン検出)徹底解説

**「ユーザーが話し終わった」をどう判定するか**。応答の速さ・割り込みの自然さに直結する、
体感を最も左右する設定。`session.audio.input.turn_detection` で指定。

### 方式は3つ

#### ① `server_vad`(無音ベース)
音量の無音区間で「話し終わり」を判定するシンプルな方式。

```json
{
  "type": "server_vad",
  "threshold": 0.5,            // 発話とみなす音量しきい値(0〜1)。高いほど鈍感=雑音に強いが小声を拾いにくい
  "prefix_padding_ms": 300,    // 発話開始の何ms前から音声を含めるか(頭切れ防止)
  "silence_duration_ms": 500   // 何msの無音で「話し終わり」とするか。短い=速い応答だが早合点しやすい
}
```

- **速く応答させたい** → `silence_duration_ms` を下げる(例: 200〜400)。
  ただし言い淀みで途中で割り込まれやすくなる。
- **雑音環境** → `threshold` を上げる。
- 仕組みが軽く予測しやすいが、「えーっと…」のような間に弱い。

#### ② `semantic_vad`(内容ベース)
発話**内容**から「言い切ったか」を分類器で推定し、確信度に応じて待つ。

```json
{
  "type": "semantic_vad",
  "eagerness": "auto"   // low / medium / high / auto。high=すぐ応答、low=じっくり待つ
}
```

- 「まだ喋る雰囲気」を汲むので、**自然な間**を扱いやすい。
- `eagerness: low` は相手が考えながら話す場面向け、`high` はテンポ重視。

#### ③ `none`(手動)
自動検出オフ。クライアントが明示的に
`input_audio_buffer.commit` → `response.create` を送って応答を起こす。
プッシュトゥトーク(押している間だけ話す)など、**タイミングを完全制御**したいとき。

### バージイン(割り込み)
ユーザーが話し始めると、サーバーは `input_audio_buffer.speech_started` を送る。
このとき**再生中のAI音声を止める**のが自然。本デモの実装:
- Web: 再生キューの世代番号を進めて古い音声を破棄([`public/js/realtime.js`](../public/js/realtime.js) の `stopPlayback`)。

`semantic_vad` には `create_response` / `interrupt_response` の細かな制御もある。

---

## 6. 音声フォーマット

- 既定は **PCM16 / 24kHz / モノラル / リトルエンディアン**。
- WebSocket ではメディアストリームを送れないので、音声も**イベントに base64 で相乗り**:
  - 送信: マイク → PCM16 → base64 → `input_audio_buffer.append`
  - 受信: `response.output_audio.delta`(base64 PCM16)→ 復号して再生
- 本デモはブラウザで `AudioContext({ sampleRate: 24000 })` を使い、
  取り込み(ScriptProcessor)と再生(スケジュール再生)を手動実装している。

---

## 7. イベントの流れ

WebSocket/データチャネル上を **JSONイベント**が双方向に流れる。

### クライアント → サーバー(主なもの)
| イベント | 用途 |
|---|---|
| `session.update` | セッション設定(voice/VAD/文字起こし/instructions 等) |
| `input_audio_buffer.append` | 入力音声チャンク(base64 PCM16)を追記 |
| `input_audio_buffer.commit` | 入力を確定(手動ターンのとき) |
| `conversation.item.create` | テキスト等でアイテムを追加 |
| `response.create` | 応答生成を起こす(手動/ツール応答時) |

### サーバー → クライアント(実際の順序:本デモでライブ捕捉)
```
session.created
session.updated
conversation.item.added        role=user        ← あなたの発話アイテム(先)
conversation.item.done         role=user
response.created
response.output_item.added     role=assistant   ← AIアイテム(後)
conversation.item.added        role=assistant
response.output_audio_transcript.delta …         ← AIの字幕(逐次)
response.output_audio.delta …                     ← AIの音声チャンク
response.output_audio.done
response.output_audio_transcript.done
response.done
rate_limits.updated
```
- あなたの発話の文字起こしは `conversation.item.input_audio_transcription.delta/completed` で
  **非同期・遅れて**届く。
- OpenAI のエラーは `error` イベントで届く(APIキー不正・パラメータ不正など)。

### ⚠️ 会話順のハマりどころ(本デモで実際に踏んだ)
入力の文字起こしは別モデルで非同期処理されるため、**AIの返答より遅れて届く**ことがある。
「最初に届いたイベントで吹き出しを作る」実装だと、AIが先・ユーザーが後に並んで**順序が逆転**する。

**対策**: 会話順で届く `conversation.item.added` の時点で、
`item.id` をキーに**空の吹き出しを正しい位置に先に確保**しておく。
文字起こしは後から同じ `item.id` に流し込むだけにすると、表示順が固定される。

---

## 8. 会話履歴(コンテキスト)の扱い

**Chat Completions API と真逆で、履歴は「渡さない」。OpenAI がサーバー側で保持する。**

### ステートフルなセッション
- **1セッション = 1つの `conversation`** = 順序付きの **item リスト**
  (user発話 / assistant応答 / function呼び出し / その結果)。
- ユーザーが話して**コミットされると、その発話が item として自動追記**される。
- 応答生成時、モデルは**それまでの全 item を自動的に文脈として見る**。
  だから2ターン目は1ターン目を覚えている。**こちらは履歴を送り直していない**。
- 本デモの中継は素通しなので履歴管理はしていない=**OpenAIのサーバー側状態に任せている**。
  そのため「ブラウザ再読み込み=新セッション=履歴リセット」になる。

### 履歴を操作するイベント
| イベント | 用途 |
|---|---|
| `conversation.item.create` | item を手動追加。`previous_item_id` で**挿入位置**指定可(省略で末尾)。※**assistantの音声メッセージは入れられない**(テキストなら可) |
| `conversation.item.truncate` | **バージイン時の同期**(下記) |
| `conversation.item.delete` | item を削除(`conversation.item.deleted` が返る) |
| `conversation.item.retrieve` | item の中身を取得 |

### ⚠️ バージインとの関係(本デモの未対応ポイント)
ユーザーが割り込むと、**AIは実際に聞こえた分より多く音声を生成済み**のことがある。
`conversation.item.truncate` で「実際に再生できたのは◯◯msまで」と伝えると、
サーバーの記憶が**実際に聞こえた内容に切り詰められる**(超えた分のtranscriptは消える)。
これをしないと**モデルは「言ったつもり」でズレる**。本デモは再生を止めるだけで
truncate を送っていないので、ここは改善余地。

### コストと上限
- 履歴が伸びるほど**毎ターン全履歴を再処理** → 入力トークンが増える。
- **prompt caching** で繰り返し部分のコストは緩和される。
- **context window 上限**あり(入力+出力+推論トークンの合計)。
  長い会話は**要約(summarization)して圧縮**するのが定石。

### 跨セッションで続けたい場合
conversation はセッション内メモリなので **WS切断で消える**。続きをやりたいなら:
1. 自分で transcript を保存しておき、
2. 新セッション開始時に `conversation.item.create` で**過去のやりとりを再投入(seed)** する
   (過去の assistant 音声は入れられないのでテキストで入れる)。

---

## 9. 認証(キーの扱い)

| 構成 | キーの置き場 | 方法 |
|---|---|---|
| WebRTC / ブラウザ直結 | サーバーで**短命トークン**を発行しブラウザへ | `POST /v1/realtime/client_secrets` で `ek_...` を発行。数分で失効 |
| WebSocket サーバー中継 | **サーバーだけ**が本物キーを持つ | 中継サーバーが `Authorization: Bearer sk-...` で OpenAI に接続 |

### ⚠️ GA の罠(本デモでライブ検証して発見)
GA(正式版)では **`OpenAI-Beta: realtime=v1` ヘッダを付けてはいけない**。
付けると次のエラーで拒否される:

```
The Realtime Beta API is no longer supported. Please use /v1/realtime for the GA API.
```

- 旧 Beta 時代の名残。GA では **Authorization ヘッダだけ**でよい。
- **ダミーキーだと認証エラーが先に出て気づけない**。実キーで初めて表面化するタイプの罠。

---

## 10. ハマりどころ集

- **Betaヘッダ**: GAでは `OpenAI-Beta` を付けない(→ 9章)。
- **temperature**: 推論系モデルは無視することがある。範囲は 0.6〜1.2。
- **文字起こしの順序**: 入力文字起こしは遅れて来る。`conversation.item.added` で順序固定(→ 7章)。
- **あなたの字幕が出ない**: `transcription` を設定していないと入力側のテキストは来ない。
- **ハウリング**: スピーカーの音をマイクが拾って無限ループになりがち。**ヘッドホン推奨**。
- **マイク許可**: ブラウザは `localhost` を安全な文脈とみなすので **HTTPS不要**。
  ただし IP直打ちや LAN 越えは HTTPS が必要。
- **モデル切り替え**: 接続後は変えられない。繋ぎ直しが必要。

---

## 11. このリポジトリとの対応

| 概念 | ファイル |
|---|---|
| 中継サーバー(本物キーで OpenAI に接続) | `app/Realtime/RelayHandler.php` |
| 中継起動コマンド | `app/Console/Commands/RealtimeRelay.php` |
| モデル/voice の候補 | `app/Http/Controllers/RealtimeController.php` |
| ブラウザ側(WS接続・PCM16変換・再生・字幕・VAD設定) | `public/js/realtime.js` |
| 画面(パラメータUI) | `resources/views/voice.blade.php` |

---

## 12. 参考リンク

- [Realtime API ガイド(公式)](https://platform.openai.com/docs/guides/realtime)
- [WebRTC で接続](https://platform.openai.com/docs/guides/realtime-webrtc)
- [WebSocket で接続](https://platform.openai.com/docs/guides/realtime-websocket)
- [会話(conversations)](https://platform.openai.com/docs/guides/realtime-conversations)
- [会話状態(conversation state)](https://platform.openai.com/docs/guides/conversation-state)
- [VAD の詳細](https://platform.openai.com/docs/guides/realtime-vad)
- [文字起こし(transcription)](https://platform.openai.com/docs/guides/realtime-transcription)
- [モデル一覧](https://platform.openai.com/docs/models)
- [料金](https://platform.openai.com/docs/pricing)
