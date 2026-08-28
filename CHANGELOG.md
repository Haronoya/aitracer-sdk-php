# Changelog — haro/aitracer (PHP SDK)

All notable changes to the PHP SDK are documented here.
This project adheres to [Semantic Versioning](https://semver.org/).

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

[1.7.0]: https://packagist.org/packages/haro/aitracer
