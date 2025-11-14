=== andW AI Translate ===
Contributors: yasuo3o3
Tags: translation, ai, multilingual, openai, claude
Requires at least: 5.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

日本語から多言語への翻訳プラグイン。再翻訳による確認、ブロック構造維持、A/B比較機能を提供。

== Description ==

andW AI Translate は、日本語コンテンツを複数の言語に翻訳するWordPressプラグインです。OpenAIのGPTとAnthropicのClaudeの両方のAIエンジンに対応し、高品質な翻訳を提供します。

= 主な機能 =

* **複数AIエンジン対応**: OpenAI GPTとClaude AIの両方に対応
* **ブロック構造維持**: Gutenbergブロックの構造を完全に保持した翻訳
* **再翻訳機能**: 翻訳結果を別のAIエンジンで再翻訳し、品質を確認
* **A/B比較**: 複数の翻訳結果を並べて比較し、最適な翻訳を選択
* **言語別ページ生成**: 翻訳されたコンテンツから自動的に言語別ページを作成
* **hreflangタグ自動生成**: SEO対応のhreflangタグを自動生成
* **画像メタデータ翻訳**: 画像のaltテキストやキャプションを言語別に管理
* **暗号化APIキー管理**: セキュリティを重視したAPIキーの暗号化保存
* **期限管理**: プラグインの使用期限と自動削除機能

= 対応言語 =

* 英語 (English)
* 中国語 (中文)
* 韓国語 (한국어)
* その他多数の言語に対応予定

= セキュリティ機能 =

* APIキーの暗号化保存
* nonce認証による安全なフォーム処理
* 権限チェック機能
* XSS対策

== Installation ==

1. プラグインファイルを `/wp-content/plugins/andw-ai-translate` ディレクトリにアップロード
2. WordPress管理画面の「プラグイン」メニューからプラグインを有効化
3. 「設定」 > 「andW AI Translate」から設定画面にアクセス
4. OpenAIまたはClaude AIのAPIキーを設定
5. 翻訳対象言語を選択して設定を保存

== Frequently Asked Questions ==

= どのAIエンジンが利用できますか？ =

現在、OpenAI GPTとAnthropic Claudeの両方に対応しています。どちらか一方、または両方のAPIキーを設定できます。

= Gutenbergブロックの構造は保持されますか？ =

はい、完全に保持されます。翻訳後もオリジナルと同じブロック構造が維持されます。

= 翻訳の品質を確認する方法はありますか？ =

再翻訳機能とA/B比較機能により、複数の翻訳結果を比較して最適な翻訳を選択できます。

= APIキーはどのように保存されますか？ =

セキュリティを重視し、すべてのAPIキーは暗号化して保存されます。

== Screenshots ==

1. メイン設定画面 - APIキーの設定と基本設定
2. 投稿編集画面 - 翻訳メタボックス
3. A/B比較画面 - 複数の翻訳結果の比較
4. 言語別ページ管理

== Changelog ==

= 0.1.1 =
* パーマリンク処理のバグ修正
* プロパティアクセスエラーの解決

= 0.1.0 =
* 初回リリース
* OpenAI GPT & Claude AI対応
* ブロック構造完全維持
* 暗号化APIキー管理
* 期限管理・自動削除
* 画像言語別メタデータ管理
* 言語別ページ生成・hreflang対応
* A/B比較・品質評価機能

== Upgrade Notice ==

= 0.1.1 =
重要なバグ修正が含まれています。アップデートを推奨します。

== Additional Information ==

このプラグインは、高品質な多言語コンテンツの作成を支援し、グローバルなWebサイト展開をサポートします。