# ezbot-local 🐱 — 学習用ローカル・チャットボット

**WordPress 不要**で動く、RAG チャットボットの学習用スタンドアロン版です。
社員が「AI ボットの仕組み」を各自の PC で触りながら学ぶための教材リポジトリです。

- 知識(URL / ファイル / テキスト)を取り込み → **チャンク分割 → 埋め込み(Voyage) → コサイン類似検索 → Claude が回答**（＝RAG）
- 知識に無い質問は **メールを取得して「問い合わせ」に保存** → 担当者が回答すると **その Q&A を学習**（＝ヒューマン・イン・ザ・ループ）
- 設定・知識管理・問い合わせ対応はすべて **ブラウザの管理GUI** から

> このリポジトリのコア(`engine/`)は、本番の WordPress プラグイン **novisign-chatbot** と **同一のロジック**です。
> ここで学んだ `ChatEngine` / `Knowledge` / `LLM` / `Embeddings` は、そのまま本番プラグインへ移せます。
> 違いは「WordPress の代わりに薄い互換シム＋SQLite で動かしている」点だけ（→ `compat/wp-shim.php`）。

---

## なぜ WordPress が要らないのか

このボットの「頭脳」は WordPress に依存していません。重い処理はすべてクラウド API に逃がしています。

| 処理 | 実行場所 |
|---|---|
| 回答生成 | Claude（Anthropic API）= クラウド |
| 埋め込み生成 | Voyage AI = クラウド |
| ベクトル検索 | PHP のコサイン類似度（ローカル・軽量） |
| データ保存 | SQLite（1ファイル） |

WordPress が担っていた「管理画面・DB・REST・cron」を、この学習版では
**軽いシム層(`compat/wp-shim.php`)＋SQLite＋PHP 内蔵サーバー**で置き換えています。
だから **大メモリの VPS は不要**。普通の PC で動きます。

---

## 動かし方（3ステップ）

### 1. PHP を入れる（Docker より遥かに軽い）

必要拡張：`pdo_sqlite` `zip` `mbstring` `curl` `dom`

```bash
# Debian / Ubuntu
sudo apt-get install -y php-cli php-sqlite3 php-zip php-mbstring php-curl php-xml
# macOS (Homebrew)
brew install php
# Windows は https://windows.php.net/ の zip を展開し PATH を通す
```

確認：
```bash
php -v
php -m | grep -E 'pdo_sqlite|zip|mbstring|curl'
```

### 2. 起動

```bash
git clone https://github.com/hezoe/ezbot-local.git
cd ezbot-local
php -S 127.0.0.1:8000 public/index.php
```

- チャット画面： http://127.0.0.1:8000/
- 管理画面　　： http://127.0.0.1:8000/admin

（`data/ezbot.sqlite` は初回アクセス時に自動生成されます）

#### Docker で動かす場合（PHP を入れたくない人向け）

PHP を各自の PC に入れず、**コンテナだけで**動かせます（必要なのは Docker のみ）。

```bash
git clone https://github.com/hezoe/ezbot-local.git
cd ezbot-local
docker compose up --build
```

- チャット画面： http://127.0.0.1:8000/ ／ 管理画面： http://127.0.0.1:8000/admin
- API キー・知識・SQLite は **ホストの `./data` に永続化**（コンテナを消しても残る）
- ホスト側は `127.0.0.1` のみに公開（そのまま外部公開しない設定）

> 必要な PHP 拡張（pdo_sqlite / zip / mbstring / curl / dom）は `Dockerfile` 側で導入済みです。

### 3. API キーを入れて知識を追加

1. `/admin` →「⚙ 設定」で **Anthropic** と **Voyage** の API キーを入力して保存
   （2つは別サービス・別キーです）
2. 「📚 知識ソース」で **テキスト貼り付け / ファイル(pdf・docx・pptx・txt・md・csv・html) / URL** を取り込み
3. チャット画面(`/`)で質問。知識に無い質問はメールアドレスを尋ね「📥 問い合わせ」に保存されます
4. 「📥 問い合わせ」で担当者が回答を書くと、その Q&A が **学習** され次回から自動回答

> **API キーはコミットされません。**設定は各自のローカルの `data/ezbot.sqlite` に保存されます。

---

## ディレクトリ構成

```
ezbot-local/
├─ public/index.php     ← フロントコントローラ（ルーター＋API）。ここが入口
├─ app/
│  ├─ bootstrap.php     ← 初期化（定数・シム読込・SQLite接続・スキーマ・フック）
│  └─ views/
│     ├─ chat.php       ← チャットUI（自己完結・CSS/JSインライン）
│     └─ admin.php      ← 管理GUI（設定/知識/問い合わせ/ログ/ツール）
├─ compat/
│  └─ wp-shim.php       ← ★WordPress互換シム。WP関数の代替＋SQLite版 $wpdb
├─ engine/              ← ★本番プラグインと同一のコアロジック（無改変）
│  ├─ class-chat-engine.php  回答の司令塔（RAG＋マルチターン＋引き継ぎ判定）
│  ├─ class-knowledge.php    取り込み・チャンク・検索・ファイル抽出
│  ├─ class-embeddings.php   Voyage 埋め込みクライアント
│  ├─ class-llm.php          Claude 生成クライアント
│  ├─ class-settings.php / class-session.php / class-inquiries.php / class-chatlog.php
│  ├─ class-database.php     テーブル名解決
│  └─ lib/pdfparser/         同梱PDFパーサ（smalot/pdfparser, LGPL-3.0）
└─ data/                ← SQLite とアップロード（gitignore。各自ローカルに閉じる）
```

## 学ぶときの読む順番（おすすめ）

1. `app/bootstrap.php` — 「WordPress が裏で何をしているか」が一望できる
2. `compat/wp-shim.php` — WP 関数・`$wpdb` の正体（HTTP・オプション・DB の実装）
3. `engine/class-chat-engine.php` — RAG の中心。検索 → プロンプト → 3モード判定
4. `engine/class-knowledge.php` — 取り込み〜チャンク〜コサイン検索
5. `public/index.php` — 画面と API がどう繋がっているか

---

## 注意

- **ローカル学習用**です。`127.0.0.1`（自分のPC）に bind して使ってください。
  認証を持たないので、そのまま外部公開しないこと。
- 取り込み・回答には Anthropic / Voyage の **API 従量課金**が発生します（少額）。
- URL 取り込みは同一ホスト配下を巡回します。取り込み対象サイトの利用規約に従ってください。

## ライセンス

- 本体：社内学習用。
- `engine/lib/pdfparser/`：smalot/pdfparser（**LGPL-3.0**）。同ライブラリのライセンスに従います。
