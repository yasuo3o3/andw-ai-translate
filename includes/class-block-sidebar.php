<?php
/**
 * Gutenbergブロックサイドバー統合クラス
 */

// 直接アクセスを防ぐ
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gutenbergエディタのブロックサイドバーに翻訳機能を統合するクラス
 */
class ANDW_AI_Translate_Block_Sidebar {

	/**
	 * ブロックパーサーインスタンス
	 */
	private $block_parser;

	/**
	 * 期限管理インスタンス
	 */
	private $expiry_manager;

	/**
	 * コンストラクタ
	 */
	public function __construct() {
		$this->block_parser = new ANDW_AI_Translate_Block_Parser();
		$this->expiry_manager = new ANDW_AI_Translate_Expiry_Manager();

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'andW AI Translate - ブロックサイドバークラス初期化' );
		}

		add_action( 'init', array( $this, 'register_block_sidebar' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'andW AI Translate - ブロックサイドバーフック登録完了' );
		}
	}

	/**
	 * ブロックサイドバーの登録
	 */
	public function register_block_sidebar() {
		// 利用可能性チェック
		if ( ! $this->expiry_manager->is_feature_available() ) {
			return;
		}

		// Gutenbergエディタが有効かチェック
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
	}

	/**
	 * ブロックエディタ用アセットの読み込み
	 */
	public function enqueue_block_editor_assets() {
		// デバッグログ: メソッド呼び出し
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'andW AI Translate - enqueue_block_editor_assets 呼び出し' );
		}

		// 利用可能性チェック
		if ( ! $this->expiry_manager->is_feature_available() ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'andW AI Translate - 機能が利用不可のためブロックサイドバーを無効化' );
			}
			return;
		}

		// Gutenbergエディタが有効な画面でのみ読み込み
		$current_screen = get_current_screen();
		if ( ! $current_screen || ! $current_screen->is_block_editor ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				$screen_id = $current_screen ? $current_screen->id : 'null';
				$is_block_editor = $current_screen ? $current_screen->is_block_editor : false;
				error_log( 'andW AI Translate - Gutenbergエディタではないためスキップ: ' . $screen_id . ', is_block_editor: ' . ( $is_block_editor ? 'true' : 'false' ) );
			}
			return;
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'andW AI Translate - ブロックサイドバー用スクリプトを読み込み' );
		}

		$script_path = ANDW_AI_TRANSLATE_PLUGIN_URL . 'assets/block-sidebar.js';
		$script_version = ANDW_AI_TRANSLATE_VERSION;

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'andW AI Translate - スクリプトURL: ' . $script_path );
			error_log( 'andW AI Translate - スクリプトバージョン: ' . $script_version );
		}

		wp_enqueue_script(
			'andw-ai-translate-block-sidebar',
			$script_path,
			array(
				'wp-plugins',
				'wp-components',
				'wp-element',
				'wp-data',
				'wp-i18n',
				'wp-api-fetch',
				'wp-editor',
				'wp-edit-post'
			),
			$script_version,
			true
		);

		// スクリプトがenqueueされたかチェック
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$enqueued = wp_script_is( 'andw-ai-translate-block-sidebar', 'enqueued' );
			$registered = wp_script_is( 'andw-ai-translate-block-sidebar', 'registered' );
			error_log( 'andW AI Translate - スクリプトenqueue状況 registered: ' . ( $registered ? 'true' : 'false' ) . ', enqueued: ' . ( $enqueued ? 'true' : 'false' ) );
		}

		// 翻訳エンジンから利用可能なプロバイダを取得
		$translation_engine = new ANDW_AI_Translate_Translation_Engine();
		$available_providers = $translation_engine->get_available_providers();

		// ローカライゼーション
		wp_localize_script(
			'andw-ai-translate-block-sidebar',
			'andwBlockTranslate',
			array(
				'nonce' => wp_create_nonce( 'andw_ai_translate_meta_box' ),
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'restUrl' => rest_url( 'andw-ai-translate/v1/' ),
				'availableProviders' => $available_providers,
			)
		);

		// 翻訳ファイルの読み込み
		wp_set_script_translations(
			'andw-ai-translate-block-sidebar',
			'andw-ai-translate',
			ANDW_AI_TRANSLATE_PLUGIN_DIR . 'languages'
		);
	}

	/**
	 * REST APIルートの登録
	 */
	public function register_rest_routes() {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'andW AI Translate - REST APIルートを登録中' );
		}

		$result = register_rest_route(
			'andw-ai-translate/v1',
			'/block',
			array(
				'methods' => 'POST',
				'callback' => array( $this, 'rest_translate_block' ),
				'permission_callback' => array( $this, 'rest_permission_check' ),
				'args' => array(
					'block_data' => array(
						'required' => true,
						'type' => 'object',
					),
					'target_language' => array(
						'required' => true,
						'type' => 'string',
					),
					'provider' => array(
						'required' => true,
						'type' => 'string',
					),
				),
			)
		);

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			if ( $result ) {
				error_log( 'andW AI Translate - REST APIルート登録成功: /andw-ai-translate/v1/block' );
			} else {
				error_log( 'andW AI Translate - REST APIルート登録失敗' );
			}
		}
	}

	/**
	 * REST API: ブロック翻訳
	 *
	 * @param WP_REST_Request $request リクエストオブジェクト
	 * @return WP_REST_Response レスポンス
	 */
	public function rest_translate_block( $request ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'andW AI Translate - REST APIエンドポイント呼び出し: ' . $request->get_route() );
			error_log( 'andW AI Translate - リクエストメソッド: ' . $request->get_method() );
		}

		// nonce チェック
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'andW AI Translate - REST API nonce検証失敗' );
			}
			return new WP_Error( 'invalid_nonce', __( '無効なnonceです', 'andw-ai-translate' ), array( 'status' => 403 ) );
		}

		// 利用可能性チェック
		if ( ! $this->expiry_manager->is_feature_available() ) {
			return new WP_Error( 'feature_unavailable', __( '翻訳機能は現在利用できません', 'andw-ai-translate' ), array( 'status' => 403 ) );
		}

		$block_data = $request->get_param( 'block_data' );
		$target_language = sanitize_text_field( $request->get_param( 'target_language' ) );
		$provider = sanitize_text_field( $request->get_param( 'provider' ) );

		// ブロック翻訳の実行
		$result = $this->block_parser->translate_block( $block_data, $target_language, $provider );

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'translation_failed',
				$result->get_error_message(),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data' => $result,
			)
		);
	}

	/**
	 * REST API権限チェック
	 *
	 * @return bool 権限の有無
	 */
	public function rest_permission_check() {
		return current_user_can( 'edit_posts' );
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
}