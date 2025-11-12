<?php
/**
 * Plugin Name: andW AI Translate
 * Description: 日本語から多言語への翻訳プラグイン。再翻訳による確認、ブロック構造維持、A/B比較機能を提供。
 * Version: 0.0.1
 * Author: yasuo3o3
 * Author URI: https://yasuo-o.xyz/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: andw-ai-translate
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Network: false
 */

// 直接アクセスを防ぐ
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// プラグイン定数の定義
define( 'ANDW_AI_TRANSLATE_VERSION', '0.0.1' );
define( 'ANDW_AI_TRANSLATE_PLUGIN_FILE', __FILE__ );
define( 'ANDW_AI_TRANSLATE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ANDW_AI_TRANSLATE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ANDW_AI_TRANSLATE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * メインプラグインクラス
 */
class ANDW_AI_Translate {

	/**
	 * Admin settings manager instance.
	 *
	 * @var ANDW_AI_Translate_Admin_Settings|null
	 */
	private $admin_settings = null;

	/**
	 * コンストラクタ
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'init' ) );
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
	}

	/**
	 * プラグイン初期化
	 */
	public function init() {
		// 環境チェック（ステージング/開発では無効化）
		// 開発作業中のため一時的にコメントアウト
		/*
		if ( $this->is_staging_or_development() ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'andW AI Translate: 開発/ステージング環境のため機能を無効化' );
			}
			return;
		}
		*/

		// 翻訳ファイルの読み込み（WP 4.6+では自動だが明示的に記載）
		load_plugin_textdomain( 'andw-ai-translate', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		// 機能ファイルの読み込み
		$this->load_includes();

		// フック設定
		$this->setup_hooks();
	}

	/**
	 * 機能ファイルを読み込み
	 */
	private function load_includes() {
		$includes = array(
			'class-api-manager.php',      // API管理・暗号化
			'class-translation-engine.php', // 翻訳・再翻訳エンジン
			'class-admin-settings.php',   // 設定画面
			'class-meta-box.php',        // 編集画面メタボックス
			'class-block-parser.php',    // ブロック構造解析
			'class-block-sidebar.php',   // Gutenbergブロックサイドバー
			'class-page-generator.php',  // 言語別ページ生成
			'class-image-meta.php',      // 画像言語別メタ
			'class-expiry-manager.php',  // 期限管理
			'class-ab-compare.php',      // A/B比較機能
		);

		foreach ( $includes as $file ) {
			$file_path = ANDW_AI_TRANSLATE_PLUGIN_DIR . 'includes/' . $file;
			if ( file_exists( $file_path ) ) {
				require_once $file_path;
			}
		}
	}

	/**
	 * フックの設定
	 */
	private function setup_hooks() {
		// 管理画面関連
		if ( is_admin() ) {
			if ( class_exists( 'ANDW_AI_Translate_Admin_Settings' ) ) {
				$this->admin_settings = new ANDW_AI_Translate_Admin_Settings();
			}

			// メタボックスの初期化
			if ( class_exists( 'ANDW_AI_Translate_Meta_Box' ) ) {
				new ANDW_AI_Translate_Meta_Box();
			}

			// 画像メタボックスの初期化
			if ( class_exists( 'ANDW_AI_Translate_Image_Meta' ) ) {
				new ANDW_AI_Translate_Image_Meta();
			}

			// ブロックサイドバーの初期化
			if ( class_exists( 'ANDW_AI_Translate_Block_Sidebar' ) ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'andW AI Translate - ブロックサイドバークラス存在確認成功' );
				}
				new ANDW_AI_Translate_Block_Sidebar();
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'andW AI Translate - ブロックサイドバーインスタンス作成完了' );
				}
			} else {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'andW AI Translate - ブロックサイドバークラスが見つかりません' );
				}
			}

			add_action( 'admin_init', array( $this, 'admin_init' ) );
			add_action( 'admin_menu', array( $this, 'admin_menu' ) );
			add_action( 'admin_bar_menu', array( $this, 'admin_bar_menu' ), 100 );
		}

		// フロントエンド関連
		add_action( 'wp_head', array( $this, 'add_hreflang' ) );
		add_filter( 'wp_get_attachment_metadata', array( $this, 'filter_image_metadata' ), 10, 2 );
	}

	/**
	 * ステージング/開発環境の判定
	 */
	private function is_staging_or_development() {
		// デバッグ情報の出力
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$env_type = defined( 'WP_ENVIRONMENT_TYPE' ) ? WP_ENVIRONMENT_TYPE : 'unknown';

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'andW AI Translate: 環境チェック - ホスト: ' . $host . ', 環境タイプ: ' . $env_type );
		}

		// 環境変数チェック
		if ( defined( 'WP_ENVIRONMENT_TYPE' ) ) {
			$is_dev_env = in_array( WP_ENVIRONMENT_TYPE, array( 'development', 'staging' ), true );
			if ( $is_dev_env && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'andW AI Translate: WP_ENVIRONMENT_TYPE により開発環境と判定' );
			}
			return $is_dev_env;
		}

		// ドメイン判定
		$staging_patterns = array( 'localhost', '.local', 'staging', 'dev.', 'test.' );

		foreach ( $staging_patterns as $pattern ) {
			if ( strpos( $host, $pattern ) !== false ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'andW AI Translate: ホスト名パターン "' . $pattern . '" により開発環境と判定' );
				}
				return true;
			}
		}

		return false;
	}

	/**
	 * プラグインの有効化
	 */
	public function activate() {
		// 基本オプションの初期化
		$default_options = array(
			'andw_ai_translate_provider' => 'openai',
			'andw_ai_translate_languages' => array( 'en', 'zh', 'ko' ),
			'andw_ai_translate_limit_daily' => 100,
			'andw_ai_translate_limit_monthly' => 3000,
			'andw_ai_translate_expiry_preset' => 30,
		);

		foreach ( $default_options as $key => $value ) {
			if ( ! get_option( $key ) ) {
				update_option( $key, $value );
			}
		}
	}

	/**
	 * プラグインの無効化
	 */
	public function deactivate() {
		// 無効化時の処理（即時停止と同じ処理）
		$this->emergency_stop();
	}

	/**
	 * 即時停止処理
	 */
	public function emergency_stop() {
		// APIキーの削除
		delete_option( 'andw_ai_translate_openai_key' );
		delete_option( 'andw_ai_translate_claude_key' );

		// 期限関連の削除
		delete_option( 'andw_ai_translate_delivery_date' );
		delete_option( 'andw_ai_translate_expiry_date' );

		// 実行中のキューの削除
		delete_transient( 'andw_ai_translate_queue' );
	}

	/**
	 * 管理画面初期化
	 */
	public function admin_init() {
		// 期限切れチェック
		if ( class_exists( 'ANDW_AI_Translate_Expiry_Manager' ) ) {
			$expiry_manager = new ANDW_AI_Translate_Expiry_Manager();
			$expiry_manager->check_expiry();
		}
	}

	/**
	 * 管理画面メニュー追加
	 */
	public function admin_menu() {
		if ( $this->admin_settings instanceof ANDW_AI_Translate_Admin_Settings ) {
			$this->admin_settings->add_menu();
		} elseif ( class_exists( 'ANDW_AI_Translate_Admin_Settings' ) ) {
			$admin_settings = new ANDW_AI_Translate_Admin_Settings();
			$admin_settings->add_menu();
		}
	}

	/**
	 * 管理バー追加
	 */
	public function admin_bar_menu( $wp_admin_bar ) {
		if ( class_exists( 'ANDW_AI_Translate_Expiry_Manager' ) ) {
			$expiry_manager = new ANDW_AI_Translate_Expiry_Manager();
			$expiry_manager->add_admin_bar_menu( $wp_admin_bar );
		}
	}

	/**
	 * hreflang の追加
	 */
	public function add_hreflang() {
		if ( class_exists( 'ANDW_AI_Translate_Page_Generator' ) ) {
			$page_generator = new ANDW_AI_Translate_Page_Generator();
			$page_generator->output_hreflang();
		}
	}

	/**
	 * 画像メタデータのフィルタ
	 */
	public function filter_image_metadata( $metadata, $attachment_id ) {
		if ( class_exists( 'ANDW_AI_Translate_Image_Meta' ) ) {
			$image_meta = new ANDW_AI_Translate_Image_Meta();

			// メソッド存在チェックを追加してエラーを回避
			if ( method_exists( $image_meta, 'filter_metadata' ) ) {
				return $image_meta->filter_metadata( $metadata, $attachment_id );
			}
		}

		// 安全回避: オリジナルメタデータをそのまま返す
		return $metadata;
	}
}

// プラグインの初期化
new ANDW_AI_Translate();