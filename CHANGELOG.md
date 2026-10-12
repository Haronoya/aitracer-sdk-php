# Changelog — haro/aitracer (PHP SDK)

All notable changes to the PHP SDK are documented here.
This project adheres to [Semantic Versioning](https://semver.org/).

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

[1.7.1]: https://packagist.org/packages/haro/aitracer
[1.7.0]: https://packagist.org/packages/haro/aitracer
