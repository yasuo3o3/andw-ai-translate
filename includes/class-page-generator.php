<?php
/**
 * 言語別ページ生成・hreflang対応クラス
 */

// 直接アクセスを防ぐ
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 翻訳承認後の言語別ページ生成とhreflangタグ出力を管理するクラス
 */
class ANDW_AI_Translate_Page_Generator {

	/**
	 * 画像メタ管理インスタンス
	 */
	private $image_meta;

	/**
	 * コンストラクタ
	 */
	public function __construct() {
		$this->image_meta = new ANDW_AI_Translate_Image_Meta();

		// フック設定
		add_action( 'save_post', array( $this, 'on_post_save' ), 10, 2 );
		add_action( 'wp_head', array( $this, 'output_hreflang' ) );
		add_filter( 'wp_get_attachment_image_attributes', array( $this, 'filter_translated_image_attributes' ), 20, 3 );
		add_action( 'template_redirect', array( $this, 'handle_language_redirect' ) );
		add_filter( 'post_link', array( $this, 'add_language_to_permalink' ), 10, 2 );
		add_filter( 'page_link', array( $this, 'add_language_to_permalink' ), 10, 2 );
	}

	/**
	 * 翻訳承認済みデータから言語別ページを生成
	 *
	 * @param int $original_post_id 元投稿ID
	 * @param string $language 言語コード
	 * @param array $translation_data 翻訳データ
	 * @return int|WP_Error 生成された投稿IDまたはエラー
	 */
	public function create_translated_page( $original_post_id, $language, $translation_data ) {
		$original_post = get_post( $original_post_id );
		if ( ! $original_post ) {
			return new WP_Error( 'post_not_found', __( '元の投稿が見つかりません', 'andw-ai-translate' ) );
		}

		// 既存の翻訳ページをチェック
		$existing_translation = $this->get_translated_post_id( $original_post_id, $language );
		if ( $existing_translation ) {
			// 既存ページを更新
			return $this->update_translated_page( $existing_translation, $translation_data );
		}

		// 新しい言語別ページのデータを準備
		$translated_post_data = array(
			'post_title'    => $this->get_translated_title( $original_post, $language, $translation_data ),
			'post_content'  => $this->get_translated_content( $translation_data ),
			'post_status'   => 'publish',
			'post_type'     => $original_post->post_type,
			'post_author'   => $original_post->post_author,
			'post_parent'   => 0,
			'menu_order'    => $original_post->menu_order,
		);

		// 投稿を作成
		$translated_post_id = wp_insert_post( $translated_post_data );

		if ( is_wp_error( $translated_post_id ) ) {
			return $translated_post_id;
		}

		// メタデータの設定
		$this->set_translation_metadata( $translated_post_id, $original_post_id, $language, $translation_data );

		// タクソノミー（カテゴリ・タグ）の複製
		$this->copy_taxonomies( $original_post_id, $translated_post_id );

		// カスタムフィールドの複製（翻訳不要なもの）
		$this->copy_custom_fields( $original_post_id, $translated_post_id );

		// 元投稿に翻訳ページの関連付けを記録
		$this->update_translation_relationships( $original_post_id, $language, $translated_post_id );

		return $translated_post_id;
	}

	/**
	 * 既存翻訳ページの更新
	 *
	 * @param int $translated_post_id 翻訳ページID
	 * @param array $translation_data 翻訳データ
	 * @return int|WP_Error 更新された投稿IDまたはエラー
	 */
	private function update_translated_page( $translated_post_id, $translation_data ) {
		$update_data = array(
			'ID'           => $translated_post_id,
			'post_content' => $this->get_translated_content( $translation_data ),
		);

		// Debug logging removed

		$result = wp_update_post( $update_data );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// 翻訳メタデータの更新
		update_post_meta( $translated_post_id, '_andw_ai_translate_data', $translation_data );
		update_post_meta( $translated_post_id, '_andw_ai_translate_updated', current_time( 'timestamp' ) );

		return $translated_post_id;
	}

	/**
	 * 翻訳されたコンテンツを安全に取得
	 */
	private function get_translated_content( $translation_data ) {
		// Debug logging removed

		// 承認データ構造からの取得 (最優先)
		if ( isset( $translation_data['translation_result']['translated_content'] ) ) {
			$content = $translation_data['translation_result']['translated_content'];
			return $content;
		}

		// 直接データ構造からの取得 (フォールバック1)
		if ( isset( $translation_data['translated_content'] ) ) {
			$content = $translation_data['translated_content'];
			return $content;
		}

		// A/B比較データ構造からの取得 (フォールバック2)
		if ( isset( $translation_data['translation']['translated_content'] ) ) {
			$content = $translation_data['translation']['translated_content'];
			return $content;
		}

		// Debug logging removed

		// 最終フォールバック: 空文字
		return '';
	}

	/**
	 * 翻訳された投稿のタイトルを取得
	 */
	private function get_translated_title( $original_post, $language, $translation_data = null ) {
		// 翻訳データにタイトルが含まれている場合はそれを使用
		if ( $translation_data ) {
			// ブロックパーサーからの直接データ
			if ( isset( $translation_data['translated_title'] ) && ! empty( $translation_data['translated_title'] ) ) {
				return $translation_data['translated_title'];
			}
			// 承認データ構造からの取得
			if ( isset( $translation_data['translation_result']['translated_title'] ) ) {
				return $translation_data['translation_result']['translated_title'];
			}
		}

		// タイトルの自動翻訳を実行
		$translation_engine = new ANDW_AI_Translate_Translation_Engine();
		$title_translation = $translation_engine->translate( $original_post->post_title, $language );

		// Debug logging removed

		if ( ! is_wp_error( $title_translation ) && isset( $title_translation['translated_text'] ) ) {
			$translated_title = $title_translation['translated_text'];
			return $translated_title;
		}

		// 翻訳失敗時のフォールバック: 元タイトル + 言語サフィックス
		// Debug logging removed

		$title = $original_post->post_title;
		$language_suffix = $this->get_language_suffix( $language );
		return $title . $language_suffix;
	}

	/**
	 * 言語サフィックスの取得
	 */
	private function get_language_suffix( $language ) {
		$suffixes = array(
			'en'    => ' (English)',
			'zh'    => ' (简体中文)',
			'zh-TW' => ' (繁體中文)',
			'ko'    => ' (한국어)',
			'fr'    => ' (Français)',
			'de'    => ' (Deutsch)',
			'es'    => ' (Español)',
			'mn'    => ' (монгол хэл)',
		);

		return isset( $suffixes[ $language ] ) ? $suffixes[ $language ] : ' (' . strtoupper( $language ) . ')';
	}

	/**
	 * 翻訳メタデータの設定
	 */
	private function set_translation_metadata( $translated_post_id, $original_post_id, $language, $translation_data ) {
		// 翻訳関連メタデータ
		update_post_meta( $translated_post_id, '_andw_ai_translate_original_id', $original_post_id );
		update_post_meta( $translated_post_id, '_andw_ai_translate_language', $language );
		update_post_meta( $translated_post_id, '_andw_ai_translate_data', $translation_data );
		update_post_meta( $translated_post_id, '_andw_ai_translate_created', current_time( 'timestamp' ) );

		// SEOメタデータ（基本的なもの）
		update_post_meta( $translated_post_id, '_andw_ai_translate_hreflang', $language );
	}

	/**
	 * タクソノミーの複製（言語別カテゴリー対応）
	 */
	private function copy_taxonomies( $original_post_id, $translated_post_id ) {
		$taxonomies = get_object_taxonomies( get_post_type( $original_post_id ) );
		$language = get_post_meta( $translated_post_id, '_andw_ai_translate_language', true );

		foreach ( $taxonomies as $taxonomy ) {
			// カテゴリーの場合は言語別配置を試行
			if ( $taxonomy === 'category' && $language ) {
				$language_category_id = $this->get_language_category_id( $language );
				if ( $language_category_id ) {
					wp_set_post_terms( $translated_post_id, array( $language_category_id ), $taxonomy );
					continue; // 言語カテゴリーに配置したので、元のカテゴリーはスキップ
				}
			}

			// その他のタクソノミーまたは言語カテゴリーが見つからない場合は元の処理
			$terms = wp_get_post_terms( $original_post_id, $taxonomy, array( 'fields' => 'ids' ) );
			if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
				wp_set_post_terms( $translated_post_id, $terms, $taxonomy );
			}
		}
	}

	/**
	 * カスタムフィールドの複製（翻訳不要なもの）
	 */
	private function copy_custom_fields( $original_post_id, $translated_post_id ) {
		$meta_fields = get_post_meta( $original_post_id );

		// 複製しないメタキー
		$exclude_meta = array(
			'_andw_ai_translate_',  // プラグイン関連メタ（プレフィックス）
			'_edit_lock',
			'_edit_last',
			'_wp_page_template',
		);

		foreach ( $meta_fields as $meta_key => $meta_values ) {
			// 除外するメタキーをスキップ
			$should_skip = false;
			foreach ( $exclude_meta as $exclude_prefix ) {
				if ( strpos( $meta_key, $exclude_prefix ) === 0 ) {
					$should_skip = true;
					break;
				}
			}

			if ( $should_skip ) {
				continue;
			}

			// メタデータをコピー
			foreach ( $meta_values as $meta_value ) {
				add_post_meta( $translated_post_id, $meta_key, maybe_unserialize( $meta_value ) );
			}
		}
	}

	/**
	 * 翻訳関連付けの更新
	 */
	private function update_translation_relationships( $original_post_id, $language, $translated_post_id ) {
		$translations = get_post_meta( $original_post_id, '_andw_ai_translate_translations', true );
		if ( ! is_array( $translations ) ) {
			$translations = array();
		}

		$translations[ $language ] = $translated_post_id;
		update_post_meta( $original_post_id, '_andw_ai_translate_translations', $translations );
	}

	/**
	 * 翻訳投稿IDの取得
	 */
	public function get_translated_post_id( $original_post_id, $language ) {
		$translations = get_post_meta( $original_post_id, '_andw_ai_translate_translations', true );

		if ( is_array( $translations ) && isset( $translations[ $language ] ) ) {
			// 投稿が存在するかチェック
			$translated_post = get_post( $translations[ $language ] );
			if ( $translated_post ) {
				return $translations[ $language ];
			}
		}

		return false;
	}

	/**
	 * hreflangタグの出力
	 */
	public function output_hreflang() {
		if ( ! is_singular() ) {
			return;
		}

		global $post;
		$original_post_id = $this->get_original_post_id( $post->ID );
		$current_language = $this->get_post_language( $post->ID );

		// 元投稿のURL（日本語）
		echo '<link rel="alternate" hreflang="ja" href="' . esc_url( get_permalink( $original_post_id ) ) . '" />' . "\n";

		// 翻訳ページのURL
		$translations = get_post_meta( $original_post_id, '_andw_ai_translate_translations', true );
		if ( is_array( $translations ) ) {
			foreach ( $translations as $language => $translated_post_id ) {
				if ( get_post_status( $translated_post_id ) === 'publish' ) {
					echo '<link rel="alternate" hreflang="' . esc_attr( $language ) . '" href="' . esc_url( get_permalink( $translated_post_id ) ) . '" />' . "\n";
				}
			}
		}

		// x-default（デフォルト言語）
		echo '<link rel="alternate" hreflang="x-default" href="' . esc_url( get_permalink( $original_post_id ) ) . '" />' . "\n";
	}

	/**
	 * 元投稿IDの取得
	 */
	private function get_original_post_id( $post_id ) {
		$original_id = get_post_meta( $post_id, '_andw_ai_translate_original_id', true );
		return $original_id ? $original_id : $post_id;
	}

	/**
	 * 投稿の言語を取得
	 */
	private function get_post_language( $post_id ) {
		$language = get_post_meta( $post_id, '_andw_ai_translate_language', true );
		return $language ? $language : 'ja';
	}

	/**
	 * 画像属性フィルタ（翻訳ページでの言語別メタデータ適用）
	 */
	public function filter_translated_image_attributes( $attr, $attachment, $size ) {
		// 現在のページの言語を取得
		global $post;
		if ( ! $post ) {
			return $attr;
		}

		$page_language = $this->get_post_language( $post->ID );

		// 日本語の場合は処理しない
		if ( $page_language === 'ja' ) {
			return $attr;
		}

		// 画像の言語別メタデータを適用
		$language_meta = $this->image_meta->get_language_meta( $attachment->ID, $page_language );

		if ( ! empty( $language_meta['alt'] ) ) {
			$attr['alt'] = $language_meta['alt'];
		}

		if ( ! empty( $language_meta['caption'] ) ) {
			$attr['title'] = $language_meta['caption'];
		}

		return $attr;
	}

	/**
	 * 言語リダイレクト処理
	 */
	public function handle_language_redirect() {
		// URLパラメータによる言語切り替え（公開ページの言語切替のためnonce不要）
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 公開ページの言語切替リンクでnonceは不要
		if ( isset( $_GET['lang'] ) && is_singular() ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 公開ページの言語切替リンクでnonceは不要
			$target_language = sanitize_text_field( wp_unslash( $_GET['lang'] ) );
			global $post;

			$original_post_id = $this->get_original_post_id( $post->ID );
			$translated_post_id = $this->get_translated_post_id( $original_post_id, $target_language );

			if ( $translated_post_id ) {
				wp_redirect( get_permalink( $translated_post_id ), 302 );
				exit;
			}
		}
	}

	/**
	 * パーマリンクに言語情報を追加（オプション）
	 */
	public function add_language_to_permalink( $permalink, $post ) {
		$language = get_post_meta( $post->ID, '_andw_ai_translate_language', true );

		if ( $language && $language !== 'ja' ) {
			// クエリパラメータとして言語を追加
			$permalink = add_query_arg( 'lang', $language, $permalink );
		}

		return $permalink;
	}

	/**
	 * 投稿保存時の処理
	 */
	public function on_post_save( $post_id, $post ) {
		// 自動保存やリビジョンをスキップ
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		// 翻訳ページの場合は元投稿との同期チェック
		$original_post_id = get_post_meta( $post_id, '_andw_ai_translate_original_id', true );
		if ( $original_post_id ) {
			update_post_meta( $post_id, '_andw_ai_translate_manual_edited', current_time( 'timestamp' ) );
		}
	}

	/**
	 * 翻訳ページの削除
	 */
	public function delete_translated_page( $original_post_id, $language ) {
		$translated_post_id = $this->get_translated_post_id( $original_post_id, $language );

		if ( $translated_post_id ) {
			// 翻訳ページを削除
			wp_delete_post( $translated_post_id, true );

			// 関連付けを削除
			$translations = get_post_meta( $original_post_id, '_andw_ai_translate_translations', true );
			if ( is_array( $translations ) ) {
				unset( $translations[ $language ] );
				update_post_meta( $original_post_id, '_andw_ai_translate_translations', $translations );
			}

			return true;
		}

		return false;
	}

	/**
	 * 利用可能な翻訳一覧を取得
	 */
	public function get_available_translations( $post_id ) {
		$original_post_id = $this->get_original_post_id( $post_id );
		$translations = get_post_meta( $original_post_id, '_andw_ai_translate_translations', true );

		$available = array();

		if ( is_array( $translations ) ) {
			foreach ( $translations as $language => $translated_post_id ) {
				if ( get_post_status( $translated_post_id ) === 'publish' ) {
					$available[ $language ] = array(
						'post_id' => $translated_post_id,
						'url' => get_permalink( $translated_post_id ),
						'language' => $language,
						'title' => get_the_title( $translated_post_id ),
					);
				}
			}
		}

		return $available;
	}

	/**
	 * 言語切り替えリンクの取得
	 */
	public function get_language_switcher_links( $post_id ) {
		$original_post_id = $this->get_original_post_id( $post_id );
		$current_language = $this->get_post_language( $post_id );

		$links = array();

		// 日本語（元ページ）
		$links['ja'] = array(
			'url' => get_permalink( $original_post_id ),
			'name' => '日本語',
			'current' => $current_language === 'ja',
		);

		// 翻訳ページ
		$translations = $this->get_available_translations( $post_id );
		$language_names = array(
			'en' => 'English',
			'zh' => '中文（簡体字）',
			'zh-TW' => '中文（繁体字）',
			'ko' => '한국어',
			'fr' => 'Français',
			'de' => 'Deutsch',
			'es' => 'Español',
			'mn' => 'монгол',
		);

		foreach ( $translations as $language => $translation_data ) {
			$links[ $language ] = array(
				'url' => $translation_data['url'],
				'name' => isset( $language_names[ $language ] ) ? $language_names[ $language ] : $language,
				'current' => $current_language === $language,
			);
		}

		return $links;
	}

	/**
	 * 言語コードに対応するカテゴリーIDを取得
	 *
	 * @param string $language_code 言語コード (e.g., 'en', 'zh-cn', 'zh-tw', 'ko')
	 * @return int|false カテゴリーIDまたはfalse
	 */
	private function get_language_category_id( $language_code ) {
		if ( empty( $language_code ) ) {
			return false;
		}

		// 言語コードの正規化（zh → zh-cn に変換）
		$normalized_language = $this->normalize_language_code( $language_code );

		// スラッグでカテゴリーを検索
		$category = get_category_by_slug( $normalized_language );

		if ( $category && ! is_wp_error( $category ) ) {
			return $category->term_id;
		}

		return false;
	}

	/**
	 * 言語コードの正規化
	 *
	 * @param string $language_code 言語コード
	 * @return string 正規化された言語コード
	 */
	private function normalize_language_code( $language_code ) {
		// 言語コードのマッピング
		$language_mapping = array(
			'zh'    => 'zh-cn',  // 簡体字中国語
			'zh-TW' => 'zh-tw',  // 繁体字中国語（大文字小文字統一）
		);

		return isset( $language_mapping[ $language_code ] ) ? $language_mapping[ $language_code ] : $language_code;
	}
}