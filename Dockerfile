# 学習用の軽量構成:PHP ビルトインサーバ(php artisan serve)で Laravel を動かすだけ。
# 音声は「ブラウザ↔OpenAI」が WebRTC で直結するため、サーバー側は重い処理をしない。
FROM php:8.4-cli

# Laravel / 中継サーバーが必要とする PHP 拡張をインストール
#   mbstring   … 文字列処理(Laravel 必須)
#   pdo_sqlite … 念のため(このデモはDB未使用だが同梱しておく)
#   bcmath     … 一部ヘルパで使用
#   pcntl      … 中継サーバー(amphp)の終了シグナル処理に必要
RUN apt-get update && apt-get install -y --no-install-recommends \
        libonig-dev libsqlite3-dev \
    && docker-php-ext-install mbstring pdo_sqlite bcmath pcntl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

WORKDIR /app

EXPOSE 8000

# 0.0.0.0 で待ち受けないとコンテナ外(ホスト)からアクセスできない
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
