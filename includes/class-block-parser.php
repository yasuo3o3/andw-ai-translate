<?php
/**
 * ブロック構造解析・維持クラス
 */

// 直接アクセスを防ぐ
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gutenbergブロックの構造を解析し、翻訳時に維持するクラス
 */
class ANDW_AI_Translate_Block_Parser {

	/**
	 * 翻訳エンジンインスタンス
	 */
	private $translation_engine;

	/**
	 * コンストラクタ
	 */
	public function __construct() {
		$this->translation_engine = new ANDW_AI_Translate_Translation_Engine();
	}

	/**
	 * 投稿のブロック構造を解析し翻訳
	 *
	 * @param int $post_id 投稿ID
	 * @param string $target_language 対象言語
	 * @param string $provider 翻訳プロバイダ
	 * @return array|WP_Error 翻訳結果またはエラー
	 */
	public function translate_post_blocks( $post_id, $target_language, $provider = null ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'post_not_found', __( '投稿が見つかりません', 'andw-ai-translate' ) );
		}

		// ブロックの解析
		$blocks = parse_blocks( $post->post_content );
		if ( empty( $blocks ) ) {
			return new WP_Error( 'no_blocks', __( 'ブロックが見つかりません', 'andw-ai-translate' ) );
		}

		// タイトルの翻訳
		$title_translation = null;
		if ( ! empty( $post->post_title ) ) {
			$title_result = $this->translation_engine->translate( $post->post_title, $target_language, $provider );
			if ( ! is_wp_error( $title_result ) && isset( $title_result['translated_text'] ) ) {
				$title_translation = $title_result['translated_text'];

				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'andW AI Translate - ブロックパーサー タイトル翻訳成功: ' . $title_translation );
				}
			} else {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					$error_msg = is_wp_error( $title_result ) ? $title_result->get_error_message() : '不明なエラー';
					error_log( 'andW AI Translate - ブロックパーサー タイトル翻訳失敗: ' . $error_msg );
				}
			}
		}

		// ブロック単位での翻訳
		$translated_blocks = array();
		$translation_results = array();

		foreach ( $blocks as $index => $block ) {
			$result = $this->translate_block( $block, $target_language, $provider );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$translated_blocks[ $index ] = $result['translated_block'];
			$translation_results[ $index ] = $result['translation_data'];
		}

		// 翻訳済みコンテンツの再構築
		$translated_content = $this->reconstruct_content( $translated_blocks );

		return array(
			'original_content' => $post->post_content,
			'translated_content' => $translated_content,
			'translated_title' => $title_translation,
			'blocks' => $translated_blocks,
			'translation_data' => $translation_results,
			'target_language' => $target_language,
			'provider' => $provider,
		);
	}

	/**
	 * 単一ブロックの翻訳
	 *
	 * @param array $block ブロックデータ
	 * @param string $target_language 対象言語
	 * @param string $provider 翻訳プロバイダ
	 * @return array|WP_Error 翻訳結果またはエラー
	 */
	public function translate_block( $block, $target_language, $provider = null ) {
		$original_block = $block;
		$translation_data = array();

		// 再帰的にブロック内の翻訳可能なテキストを処理
		$translated_block = $this->process_block_content( $block, $target_language, $provider, $translation_data );

		if ( is_wp_error( $translated_block ) ) {
			return $translated_block;
		}

		return array(
			'original_block' => $original_block,
			'translated_block' => $translated_block,
			'translation_data' => $translation_data,
		);
	}

	/**
	 * ブロック内容の再帰的処理
	 *
	 * @param array $block ブロックデータ
	 * @param string $target_language 対象言語
	 * @param string $provider 翻訳プロバイダ
	 * @param array &$translation_data 翻訳データ格納用配列
	 * @return array|WP_Error 処理済みブロックまたはエラー
	 */
	private function process_block_content( $block, $target_language, $provider, &$translation_data ) {
		// innerHTML（ブロック内のHTMLコンテンツ）の翻訳
		if ( ! empty( $block['innerHTML'] ) ) {
			$translatable_content = $this->extract_translatable_content( $block['innerHTML'] );

			if ( ! empty( $translatable_content ) ) {
				$translation_result = $this->translation_engine->translate( $translatable_content, $target_language, $provider );

				if ( is_wp_error( $translation_result ) ) {
					return $translation_result;
				}

				// 翻訳結果でHTMLを更新
				$block['innerHTML'] = $this->replace_translatable_content( $block['innerHTML'], $translation_result['translated_text'] );
				$this->sync_inner_content( $block );

				// 翻訳データの記録
				$translation_data[] = array(
					'original' => $translatable_content,
					'translated' => $translation_result['translated_text'],
					'block_type' => $block['blockName'],
				);
			}
		}

		// ブロック属性の翻訳（特定の属性のみ）
		if ( ! empty( $block['attrs'] ) ) {
			$block['attrs'] = $this->translate_block_attributes( $block['attrs'], $target_language, $provider, $translation_data );
		}

		// 内部ブロック（入れ子ブロック）の処理
		if ( ! empty( $block['innerBlocks'] ) ) {
			foreach ( $block['innerBlocks'] as $index => $inner_block ) {
				$result = $this->process_block_content( $inner_block, $target_language, $provider, $translation_data );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$block['innerBlocks'][ $index ] = $result;
			}
		} else {
			$this->sync_inner_content( $block );
		}

		return $block;
	}

	/**
	 * HTMLから翻訳可能なテキストコンテンツを抽出
	 *
	 * @param string $html HTML文字列
	 * @return string 翻訳可能なテキスト
	 */
	private function extract_translatable_content( $html ) {
		// HTMLタグを除去してテキストのみを抽出
		$text = wp_strip_all_tags( $html );
		$text = trim( $text );

		// 空白文字のみの場合は翻訳しない
		if ( empty( $text ) || ctype_space( $text ) ) {
			return '';
		}

		// 数字のみの場合は翻訳しない
		if ( is_numeric( $text ) ) {
			return '';
		}

		// URL、メールアドレス等は翻訳しない
		if ( filter_var( $text, FILTER_VALIDATE_URL ) || filter_var( $text, FILTER_VALIDATE_EMAIL ) ) {
			return '';
		}

		return $text;
	}

	/**
	 * HTMLの翻訳可能部分を翻訳結果で置換
	 *
	 * @param string $html 元のHTML
	 * @param string $translated_text 翻訳されたテキスト
	 * @return string 更新されたHTML
	 */
	private function replace_translatable_content( $html, $translated_text ) {
		// シンプルな置換ロジック（より複雑な場合はDOMパーサーを使用）
		$original_text = wp_strip_all_tags( $html );
		$original_text = trim( $original_text );

		if ( ! empty( $original_text ) ) {
			return str_replace( $original_text, $translated_text, $html );
		}

		return $html;
	}

	/**
	 * ブロック属性の翻訳
	 *
	 * @param array $attrs ブロック属性
	 * @param string $target_language 対象言語
	 * @param string $provider 翻訳プロバイダ
	 * @param array &$translation_data 翻訳データ
	 * @return array 翻訳済み属性
	 */
	private function translate_block_attributes( $attrs, $target_language, $provider, &$translation_data ) {
		// 翻訳対象の属性名
		$translatable_attributes = array(
			'content',      // 段落ブロックのコンテンツ
			'citation',     // 引用ブロックの引用元
			'value',        // リストブロックの値
			'placeholder',  // プレースホルダー
			'title',        // タイトル
			'caption',      // キャプション
			'alt',          // 画像のalt属性
		);

		foreach ( $attrs as $attr_name => $attr_value ) {
			if ( in_array( $attr_name, $translatable_attributes, true ) && ! empty( $attr_value ) && is_string( $attr_value ) ) {
				$translatable_content = $this->extract_translatable_content( $attr_value );

				if ( ! empty( $translatable_content ) ) {
					$translation_result = $this->translation_engine->translate( $translatable_content, $target_language, $provider );

					if ( ! is_wp_error( $translation_result ) ) {
						$attrs[ $attr_name ] = $translation_result['translated_text'];

						$translation_data[] = array(
							'original' => $translatable_content,
							'translated' => $translation_result['translated_text'],
							'attribute' => $attr_name,
						);
					}
				}
			}
		}

		return $attrs;
	}

	/**
	 * innerHTML と innerContent の内容を同期
	 *
	 * @param array &$block ブロックデータ
	 */
	private function sync_inner_content( &$block ) {
		if ( isset( $block['innerBlocks'] ) && ! empty( $block['innerBlocks'] ) ) {
			return;
		}

		if ( isset( $block['innerHTML'] ) && $block['innerHTML'] !== '' ) {
			$block['innerContent'] = array( $block['innerHTML'] );
		}
	}

	/**
	 * 翻訳済みブロックからコンテンツを再構築
	 *
	 * @param array $blocks 翻訳済みブロック配列
	 * @return string 再構築されたコンテンツ
	 */
	private function reconstruct_content( $blocks ) {
		$content = '';

		foreach ( $blocks as $block ) {
			$content .= serialize_block( $block );
		}

		return $content;
	}

	/**
	 * 特定ブロックの再翻訳
	 *
	 * @param array $block ブロックデータ
	 * @param string $target_language 対象言語
	 * @param string $provider 翻訳プロバイダ
	 * @return array|WP_Error 再翻訳結果またはエラー
	 */
	public function retranslate_block( $block, $target_language, $provider = null ) {
		return $this->translate_block( $block, $target_language, $provider );
	}

	/**
	 * ブロック差分の比較
	 *
	 * @param array $original_block 元のブロック
	 * @param array $new_block 新しいブロック
	 * @return array 差分情報
	 */
	public function compare_blocks( $original_block, $new_block ) {
		$differences = array();

		// innerHTML の比較
		if ( $original_block['innerHTML'] !== $new_block['innerHTML'] ) {
			$differences['innerHTML'] = array(
				'original' => $original_block['innerHTML'],
				'new' => $new_block['innerHTML'],
			);
		}

		// 属性の比較
		$original_attrs = isset( $original_block['attrs'] ) ? $original_block['attrs'] : array();
		$new_attrs = isset( $new_block['attrs'] ) ? $new_block['attrs'] : array();

		$attr_diff = array_diff_assoc( $new_attrs, $original_attrs );
		if ( ! empty( $attr_diff ) ) {
			$differences['attributes'] = $attr_diff;
		}

		return $differences;
	}

	/**
	 * 翻訳可能なブロックタイプかチェック
	 *
	 * @param string $block_name ブロック名
	 * @return bool 翻訳可能かどうか
	 */
	public function is_translatable_block( $block_name ) {
		$translatable_blocks = array(
			'core/paragraph',
			'core/heading',
			'core/list',
			'core/quote',
			'core/pullquote',
			'core/verse',
			'core/preformatted',
			'core/button',
			'core/cover',
			'core/media-text',
			'core/group',
			'core/columns',
			'core/column',
		);

		return in_array( $block_name, $translatable_blocks, true );
	}

	/**
	 * ブロック構造の検証
	 *
	 * @param array $blocks ブロック配列
	 * @return bool 有効な構造かどうか
	 */
	public function validate_block_structure( $blocks ) {
		if ( ! is_array( $blocks ) ) {
			return false;
		}

		foreach ( $blocks as $block ) {
			if ( ! isset( $block['blockName'] ) || ! isset( $block['innerHTML'] ) ) {
				return false;
			}

			// 内部ブロックがある場合は再帰的に検証
			if ( ! empty( $block['innerBlocks'] ) ) {
				if ( ! $this->validate_block_structure( $block['innerBlocks'] ) ) {
					return false;
				}
			}
		}

		return true;
	}
}
