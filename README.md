# AITracer PHP SDK

AITracer PHP SDK - AI/LLM APIコールのモニタリングとトレーシング

## インストール

```bash
composer require haro/aitracer
```

## クイックスタート

### 基本的な使い方

```php
<?php

use AITracer\AITracer;

// 初期化
$tracer = new AITracer([
    'api_key' => 'at-xxxxx',  // または環境変数 AITRACER_API_KEY
    'project' => 'my-project', // または環境変数 AITRACER_PROJECT
]);

// 手動ログ記録
$tracer->log([
    'model' => 'gpt-4',
    'input' => ['messages' => [['role' => 'user', 'content' => 'Hello']]],
    'output' => ['content' => 'Hi there!'],
    'input_tokens' => 10,
    'output_tokens' => 5,
    'latency_ms' => 500,
]);
```

### OpenAI クライアントのラップ

```php
<?php

use AITracer\AITracer;
use OpenAI;

$tracer = new AITracer([
    'api_key' => 'at-xxxxx',
    'project' => 'my-project',
]);

// OpenAIクライアントを作成
$openai = OpenAI::client('sk-...');

// AITracerでラップ（自動ログ記録が有効になる）
$traced = $tracer->wrapOpenAI($openai);

// 通常通りAPI呼び出し（自動的にログ記録される）
$response = $traced->chat()->completions()->create([
    'model' => 'gpt-4',
    'messages' => [
        ['role' => 'user', 'content' => 'Hello, how are you?'],
    ],
]);
```

### Anthropic クライアントのラップ

```php
<?php

use AITracer\AITracer;

$tracer = new AITracer([
    'api_key' => 'at-xxxxx',
    'project' => 'my-project',
]);

// Anthropicクライアントをラップ
$traced = $tracer->wrapAnthropic($anthropic);

$response = $traced->messages()->create([
    'model' => 'claude-3-opus-20240229',
    'max_tokens' => 1024,
    'messages' => [
        ['role' => 'user', 'content' => 'Hello, Claude!'],
    ],
]);
```

### トレースによるグループ化

関連するAPIコールをトレースでグループ化できます：

```php
<?php

// トレースを開始
$trace = $tracer->trace('conversation-123', 'Customer Support Chat')
    ->setMetadata(['customer_id' => 'cust_001'])
    ->addTags(['support', 'premium']);

// このトレース内のすべてのAPI呼び出しは同じtrace_idでグループ化される
$response1 = $traced->chat()->completions()->create([...]);
$response2 = $traced->chat()->completions()->create([...]);

// トレースを終了
$tracer->endTrace();
```

### PII検出とマスキング

個人情報を自動検出してマスキングできます：

```php
<?php

$tracer = new AITracer([
    'api_key' => 'at-xxxxx',
    'project' => 'my-project',
    'pii_detection' => true,
    'pii_action' => 'mask',  // mask, redact, hash, none
    'pii_types' => ['email', 'phone', 'credit_card', 'ssn'],
]);

// ログ内のPIIは自動的にマスクされる
// "Contact test@example.com" → "Contact [email]"
```

### 本文を送らない（`capture_content => false`）

議事録など機密性の高い本文を扱うアプリでは、`capture_content => false` を指定すると、LLM の入出力の本文を AITracer に送らず、メタ情報だけを送ります。氏名や住所は正規表現によるマスキング（`pii_detection`）では確実に検出できないため、本文そのものを送らないのが最も確実な対策です。

```php
<?php

$tracer = new AITracer([
    'api_key' => 'at-xxxxx',
    'project' => 'minutes-app',
    'capture_content' => false,
]);
```

| 項目 | 送信内容 |
|------|---------|
| モデル・プロバイダー、トークン数（キャッシュ分を含む）、レイテンシ、ステータス | そのまま送信 |
| トレース ID・スパン ID・セッション ID・ユーザー ID | そのまま送信 |
| ログの `metadata`（トレースのメタデータを含む） | そのまま送信 |
| セッションの `name` / `metadata`、イベントの `event_type` / `name`、フィードバックの種類・スコア | そのまま送信 |
| ログの `input` / `output` | `{"redacted": true}` に置き換え |
| ログの `error_message` | 例外クラス名（例: `OpenAI\Exceptions\ErrorException`）に置き換え。手動 `log()` で `error_type` を渡さない場合は送らない |
| イベントの `data` | `{"redacted": true}` に置き換え |
| フィードバックの `comment` | 送らない（`null`） |

- トークン数はプロバイダーの応答（usage）から取得するため、本文を送らなくてもコストは従来どおり計算されます。Whisper のコスト計算に使う `audio_duration_seconds` も `metadata` で送られます（ファイル名は `input` に含まれるため送られません）。
- `metadata`・セッション名・イベント名はそのまま送られるので、本文や個人情報を入れないでください。`pii_detection => true` と併用すると、送られる項目にはマスキングが適用されます。
- LLM への入力や、呼び出し元に返る応答は変更されません。ダッシュボードには本文が表示されず、本文を使う機能（リプレイなど）は使えません。
- 手動でエラーを記録するときは `'error_message' => $e->getMessage()` に加えて `'error_type' => $e::class` を渡すと、`capture_content => false` のときは例外クラス名が送られます。

## 設定オプション

| オプション | デフォルト | 説明 |
|-----------|-----------|------|
| `api_key` | - | AITracer APIキー（必須） |
| `project` | - | プロジェクト名/ID（必須） |
| `base_url` | `https://api.aitracer.co` | APIエンドポイント（https 必須。http は localhost / 127.0.0.1 / ::1 のみ可） |
| `sync` | `false` | 同期モード（サーバーレス環境用） |
| `enabled` | `true` | ログ記録の有効/無効 |
| `flush_on_exit` | `true` | 終了時に自動フラッシュ |
| `batch_size` | `10` | バッチ送信のログ数 |
| `flush_interval` | `5.0` | 自動フラッシュ間隔（秒） |
| `timeout` | `30` | HTTPタイムアウト（秒） |
| `pii_detection` | `false` | PII検出の有効化 |
| `pii_action` | `mask` | PII処理方法 |
| `pii_types` | `[email, phone, ...]` | 検出するPIIタイプ |
| `capture_content` | `true` | `false` で LLM の入出力の本文を送らない（メタ情報のみ） |

## 環境変数

```bash
AITRACER_API_KEY=at-xxxxx
AITRACER_PROJECT=my-project
AITRACER_BASE_URL=https://api.aitracer.co  # オプション
```

`base_url` / `AITRACER_BASE_URL` は `https://` が必須です。`http://` は開発用に `localhost` / `127.0.0.1` / `::1` のときだけ使えます。それ以外の URL は初期化時に `InvalidArgumentException` になります。

## サーバーレス環境（AWS Lambda等）

サーバーレス環境では同期モードを使用してください：

```php
<?php

$tracer = new AITracer([
    'api_key' => 'at-xxxxx',
    'project' => 'my-project',
    'sync' => true,  // 即座に送信
]);
```

## 手動フラッシュ

```php
<?php

// 未送信のログをすべて送信
$tracer->flush();

// シャットダウン（フラッシュを含む）
$tracer->shutdown();
```

## ログデータ構造

```php
$tracer->log([
    'model' => 'gpt-4',                    // モデル名
    'provider' => 'openai',                // プロバイダー（自動検出可）
    'input' => [...],                      // 入力データ
    'output' => [...],                     // 出力データ
    'input_tokens' => 100,                 // 非キャッシュ入力トークン数
    'output_tokens' => 50,                 // 出力トークン数
    'cache_read_input_tokens' => 0,        // プロンプトキャッシュから読み出した入力トークン数
    'cache_creation_input_tokens' => 0,    // プロンプトキャッシュに書き込んだ入力トークン数
    'latency_ms' => 1234,                  // レイテンシ（ミリ秒）
    'status' => 'success',                 // success または error
    'error_message' => null,               // エラーメッセージ
    'metadata' => ['key' => 'value'],      // カスタムメタデータ
    'tags' => ['production', 'chat'],      // タグ
    'trace_id' => 'xxx',                   // トレースID
    'session_id' => 'session-123',         // セッションID
    'user_id' => 'user-456',               // ユーザーID
]);
```

> **Note**: `input_tokens` は非キャッシュ分の入力トークンに正規化されます。ラッパー（`wrapOpenAI` / `wrapAnthropic` / `wrapGemini`）使用時は、プロンプトキャッシュのトークンが `cache_read_input_tokens` / `cache_creation_input_tokens` に自動計上されます。

## 要件

- PHP 8.1以上
- Guzzle HTTP 7.0以上

## ライセンス

MIT License
