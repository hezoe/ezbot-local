# ezbot-local — 学習用スタンドアロン RAG チャットボット
# PHP 内蔵サーバー1本で動く。外部DBサービス不要（SQLite）。
FROM php:8.2-cli

# 必要な PHP 拡張：
#   pdo_sqlite … DB（SQLite）        zip … docx/pptx(OOXML)展開
#   mbstring   … 日本語処理          （curl / dom は公式イメージに標準同梱）
RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev libonig-dev libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite zip mbstring \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# アプリ本体をコピー（data/ は .dockerignore で除外＝実行時ボリュームで持つ）
COPY . /app

# SQLite とアップロードの置き場（compose でボリュームにする）
RUN mkdir -p /app/data/uploads && chmod -R 0777 /app/data

EXPOSE 8000

# コンテナ内は 0.0.0.0 で待受け、公開はホスト側の 127.0.0.1 に限定する（compose 参照）。
CMD ["php", "-S", "0.0.0.0:8000", "public/index.php"]
