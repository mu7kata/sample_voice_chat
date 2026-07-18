<?php

use App\Http\Controllers\RealtimeController;
use Illuminate\Support\Facades\Route;

// 音声チャットの画面。
// 実際の音声/イベントは、ブラウザ ↔ 中継サーバー(php artisan realtime:relay) ↔ OpenAI が
// WebSocket でやり取りするため、ここは画面表示のルートのみ。
Route::get('/', [RealtimeController::class, 'index']);
