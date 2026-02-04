# AITracer PHP SDK

AITracer PHP SDK - AI/LLM APIコールのモニタリングとトレーシング

## インストール

```bash
composer require aitracer/aitracer-php
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

## 設定オプション

| オプション | デフォルト | 説明 |
|-----------|-----------|------|
| `api_key` | - | AITracer APIキー（必須） |
| `project` | - | プロジェクト名/ID（必須） |
| `base_url` | `https://api.aitracer.co` | APIエンドポイント |
| `sync` | `false` | 同期モード（サーバーレス環境用） |
| `enabled` | `true` | ログ記録の有効/無効 |
| `flush_on_exit` | `true` | 終了時に自動フラッシュ |
| `batch_size` | `10` | バッチ送信のログ数 |
| `flush_interval` | `5.0` | 自動フラッシュ間隔（秒） |
| `timeout` | `30` | HTTPタイムアウト（秒） |
| `pii_detection` | `false` | PII検出の有効化 |
| `pii_action` | `mask` | PII処理方法 |
| `pii_types` | `[email, phone, ...]` | 検出するPIIタイプ |

## 環境変数

```bash
AITRACER_API_KEY=at-xxxxx
AITRACER_PROJECT=my-project
AITRACER_BASE_URL=https://api.aitracer.co  # オプション
```

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
    'input_tokens' => 100,                 // 入力トークン数
    'output_tokens' => 50,                 // 出力トークン数
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

## 要件

- PHP 8.1以上
- Guzzle HTTP 7.0以上

## ライセンス

MIT License
