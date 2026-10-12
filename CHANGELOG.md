# Changelog — haro/aitracer (PHP SDK)

All notable changes to the PHP SDK are documented here.
This project adheres to [Semantic Versioning](https://semver.org/).

## [1.8.0] - 2026-10-12

### Added
- **本文を送らないオプション `capture_content` を追加**（既定は `true` で従来どおり。
  Python SDK の `capture_content`、TypeScript SDK の `captureContent` と同じ挙動）。
  `'capture_content' => false` にすると、LLM の入出力の本文を送らず、モデル・トークン数・
  レイテンシ・ステータス・トレース／セッションの ID・`metadata` などのメタ情報だけを
  送信する。議事録など、氏名・住所を正規表現では確実に検出できない本文を扱うアプリ向け。
  - ログの `input` / `output` は `{"redacted": true}` に置き換える。
  - `error_message` は例外クラス名（例: `OpenAI\Exceptions\ErrorException`）に置き換える。
  - セッションイベントの `data` は `{"redacted": true}`、フィードバックの `comment` は送らない。
  - トークン数はプロバイダーの応答から取得するため、コスト計算は従来どおり。
- `AITracer::log()` のデータに `error_type`（例外クラス名）を追加。各ラッパーと LLPhant 連携は
  エラー時にこれを渡す。`capture_content` が `false` のときだけ `error_message` として送られる。

### Changed
- **`base_url`（環境変数 `AITRACER_BASE_URL` を含む）は https 必須に**。`http://` は
  `localhost` / `127.0.0.1` / `::1` のときだけ許可し、それ以外や URL として不正な値は
  初期化時に `InvalidArgumentException` にする。これまで `http://` の URL に API キーと
  ログを平文で送れていた。

## [1.7.1] - 2026-10-12

### Fixed
- **電話番号・カード番号が一部だけマスクされ、残りが送信される不具合を修正**
  （`pii_detection => true` 時）。「090-1234-5678」が `[phone]-5678` に、区切りのない
  カード番号「4111111111111111」が `[phone]111111` になっていた。パターンを順番に
  置換していたため、先に適用した電話番号のパターンが値の一部を取っていた。
  すべてのパターンで検出してから重なりを解決する方式に変更し、パターンを
  Python SDK と同じものに揃えた。
- **マスク対象を送信する内容に限定し、セッション・イベント・フィードバックにも適用**。
  これまではログ全体（`trace_id` などの ID を含む）を置換しており、数字を含む ID が
  書き換わることがあった。ログの `input` / `output` / `error_message` / `metadata`、
  セッション名・`metadata`、イベントの `name` / `data`、フィードバックの `comment` を
  マスクする。

## [1.7.0] - 2026-08-28

### Fixed
- **ストリーミング時のトークン数・コストが常に 0 になる不具合を修正**:
  `createStreamed` がトークン数を 0 固定にしていたため、ストリーミング
  利用時のコストが常に $0 として記録されていた。OpenAI に
  `stream_options: { include_usage: true }` を自動付与し、最終チャンクの
  `usage` からトークン数を取得するよう修正。
- **セッション終了が反映されない不具合を修正**: セッション終了時に
  汎用の `PATCH /api/v1/sessions/{id}/` を呼んでいたため、サーバー側で
  ステータスが `COMPLETED` にならず集計値も更新されていなかった。
  専用の `PATCH /api/v1/sessions/{id}/end/` を呼ぶように修正。

### Added
- **プロンプトキャッシュのコスト計上に対応**。OpenAI / Anthropic の
  各ラッパーが、キャッシュされた入力トークンを抽出してログに含めるように
  なりました。`input_tokens` は非キャッシュ入力のみに正規化されます
  （OpenAI はキャッシュ分を控除、Anthropic は従来どおり）。
  ログエントリに `cache_read_input_tokens` /
  `cache_creation_input_tokens` を追加。

### Notes
- キャッシュトークンのコスト集計を有効にするには、AITracer バックエンドが
  マイグレーション `logs/0010` 適用済みである必要があります（未適用でも
  新フィールドは無視されるだけで、既存動作には影響しません）。

[1.8.0]: https://packagist.org/packages/haro/aitracer
[1.7.1]: https://packagist.org/packages/haro/aitracer
[1.7.0]: https://packagist.org/packages/haro/aitracer
