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

			// 画像属性の個別翻訳

			$block['innerHTML'] = $this->translate_image_attributes_in_html( $block['innerHTML'], $target_language, $provider, $translation_data );



			$translated_html = $this->translate_html_text_nodes(

				$block['innerHTML'],

				$target_language,

				$provider,

				$translation_data,

				isset( $block['blockName'] ) ? $block['blockName'] : 'unknown'

			);



			if ( is_wp_error( $translated_html ) ) {

				return $translated_html;

			}



			$block['innerHTML'] = $translated_html;

			$this->sync_inner_content( $block );

		}



		// ブロック属性の翻訳（必要な属性のみ）

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
		/**
	 * HTML内テキストノードの翻訳（既存API互換のため残置）
	 */
	private function extract_translatable_content( $html ) {
		return '';
	}

	/**
	 * HTML内テキストノードを逐次翻訳
	 *
	 * @param string $html HTMLコンテンツ
	 * @param string $target_language 目標言語
	 * @param string $provider プロバイダ
	 * @param array  $translation_data 翻訳メタ情報
	 * @param string $block_name ブロック名
	 * @return string|WP_Error 翻訳済みHTMLまたはエラー
	 */
	private function translate_html_text_nodes( $html, $target_language, $provider, &$translation_data, $block_name ) {
		if ( '' === trim( $html ) ) {
			return $html;
		}

		if ( ! class_exists( 'DOMDocument' ) ) {
			return new WP_Error( 'missing_dom', __( 'DOM extension is not available on this server.', 'andw-ai-translate' ) );
		}

		$previous_state = libxml_use_internal_errors( true );
		$dom            = new DOMDocument();
		$loaded         = $dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );

		if ( ! $loaded ) {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous_state );
			return new WP_Error( 'html_parse_error', __( 'HTMLの解析に失敗しました', 'andw-ai-translate' ) );
		}

		$xpath = new DOMXPath( $dom );
		$nodes = $xpath->query( '//text()' );

		foreach ( $nodes as $node ) {
			$text_content = $node->nodeValue;

			if ( '' === trim( html_entity_decode( $text_content, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) {
				continue;
			}

			$translation_result = $this->translation_engine->translate( $text_content, $target_language, $provider );

			if ( is_wp_error( $translation_result ) ) {
				libxml_clear_errors();
				libxml_use_internal_errors( $previous_state );
				return $translation_result;
			}

			$node->nodeValue = $translation_result['translated_text'];

			$translation_data[] = array(
				'original'   => $text_content,
				'translated' => $translation_result['translated_text'],
				'block_type' => $block_name,
			);
		}

		$translated_html = $dom->saveHTML();
		libxml_clear_errors();
		libxml_use_internal_errors( $previous_state );

		return $translated_html;
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
	 * 翻訳済みコンテンツの再翻訳（画像要素含む）
	 *
	 * @param string $translated_content 翻訳済みHTML
	 * @param string $target_language 対象言語（通常は'ja'）
	 * @param string $provider 翻訳プロバイダ
	 * @return array|WP_Error 再翻訳結果またはエラー
	 */
	public function retranslate_translated_content( $translated_content, $target_language = 'ja', $provider = null ) {
		// 翻訳済みコンテンツをブロック構造に解析
		$blocks = parse_blocks( $translated_content );

		if ( empty( $blocks ) ) {
			return new WP_Error( 'parse_error', __( 'コンテンツの解析に失敗しました', 'andw-ai-translate' ) );
		}

		$retranslated_blocks = array();
		$translation_data = array();

		// 各ブロックを再翻訳
		foreach ( $blocks as $block ) {
			if ( $this->is_translatable_block( $block['blockName'] ) ) {
				$retranslated_block = $this->translate_block( $block, $target_language, $provider );

				if ( is_wp_error( $retranslated_block ) ) {
					return $retranslated_block;
				}

				$retranslated_blocks[] = $retranslated_block['translated_block'];

				// 翻訳データを記録
				if ( isset( $retranslated_block['translation_data'] ) ) {
					$translation_data = array_merge( $translation_data, $retranslated_block['translation_data'] );
				}
			} else {
				$retranslated_blocks[] = $block;
			}
		}

		// ブロック構造をHTMLに再構築
		$retranslated_content = serialize_blocks( $retranslated_blocks );

		return array(
			'translated_content' => $retranslated_content,
			'back_translated_text' => $retranslated_content,
			'translation_data' => $translation_data,
			'blocks' => $retranslated_blocks,
			'provider' => $provider,
			'target_language' => $target_language,
		);
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
