<?php
/**
 * 翻訳・再翻訳エンジンクラス
 */

// 直接アクセスを防ぐ
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI翻訳エンジン（OpenAI/Claude）を管理するクラス
 */
class ANDW_AI_Translate_Translation_Engine {

	/**
	 * API管理インスタンス
	 */
	private $api_manager;

	/**
	 * 期限管理インスタンス
	 */
	private $expiry_manager;

	/**
	 * コンストラクタ
	 */
	public function __construct() {
		$this->api_manager = new ANDW_AI_Translate_API_Manager();
		$this->expiry_manager = new ANDW_AI_Translate_Expiry_Manager();
	}

	/**
	 * 翻訳の実行
	 *
	 * @param string $text 翻訳するテキスト
	 * @param string $target_language 対象言語コード
	 * @param string $provider 使用するプロバイダ（openai/claude）
	 * @return array|WP_Error 翻訳結果または エラー
	 */
	public function translate( $text, $target_language, $provider = null ) {
		// 機能の利用可能性チェック
		if ( ! $this->expiry_manager->is_feature_available() ) {
			return new WP_Error( 'feature_unavailable', __( '翻訳機能は現在利用できません', 'andw-ai-translate' ) );
		}

		// 使用制限チェック
		$limit_check = $this->check_usage_limits();
		if ( is_wp_error( $limit_check ) ) {
			return $limit_check;
		}

		// プロバイダの決定
		if ( ! $provider ) {
			$provider = get_option( 'andw_ai_translate_provider', 'openai' );
		}

		// APIキーの存在確認
		if ( ! $this->api_manager->has_api_key( $provider ) ) {
			return new WP_Error( 'no_api_key', __( 'APIキーが設定されていません', 'andw-ai-translate' ) );
		}

		// テキストの前処理
		$text = $this->preprocess_text( $text );

		if ( empty( $text ) ) {
			return new WP_Error( 'empty_text', __( '翻訳するテキストがありません', 'andw-ai-translate' ) );
		}

		// 翻訳実行
		$translation_result = $this->execute_translation( $text, $target_language, $provider );

		if ( is_wp_error( $translation_result ) ) {
			return $translation_result;
		}

		// 使用量の記録
		$this->record_usage();

		return array(
			'original_text' => $text,
			'translated_text' => $translation_result,
			'target_language' => $target_language,
			'provider' => $provider,
			'timestamp' => current_time( 'timestamp' ),
		);
	}

	/**
	 * 再翻訳の実行（品質確認用）
	 *
	 * @param string $translated_text 翻訳済みテキスト
	 * @param string $source_language 元の言語コード
	 * @param string $provider 使用するプロバイダ
	 * @return array|WP_Error 再翻訳結果またはエラー
	 */
	public function back_translate( $translated_text, $source_language, $provider = null ) {
		// 機能の利用可能性チェック
		if ( ! $this->expiry_manager->is_feature_available() ) {
			return new WP_Error( 'feature_unavailable', __( '翻訳機能は現在利用できません', 'andw-ai-translate' ) );
		}

		// プロバイダの決定
		if ( ! $provider ) {
			$provider = get_option( 'andw_ai_translate_provider', 'openai' );
		}

		// APIキーの存在確認
		if ( ! $this->api_manager->has_api_key( $provider ) ) {
			return new WP_Error( 'no_api_key', __( 'APIキーが設定されていません', 'andw-ai-translate' ) );
		}

		// 再翻訳実行
		$back_translation_result = $this->execute_translation( $translated_text, $source_language, $provider );

		if ( is_wp_error( $back_translation_result ) ) {
			return $back_translation_result;
		}

		return array(
			'translated_text' => $translated_text,
			'back_translated_text' => $back_translation_result,
			'source_language' => $source_language,
			'provider' => $provider,
			'timestamp' => current_time( 'timestamp' ),
		);
	}

	/**
	 * 実際の翻訳処理
	 *
	 * @param string $text 翻訳するテキスト
	 * @param string $target_language 対象言語
	 * @param string $provider プロバイダ
	 * @return string|WP_Error 翻訳結果またはエラー
	 */
	private function execute_translation( $text, $target_language, $provider ) {
		switch ( $provider ) {
			case 'openai':
				return $this->translate_with_openai( $text, $target_language );
			case 'claude':
				return $this->translate_with_claude( $text, $target_language );
			default:
				return new WP_Error( 'invalid_provider', __( '無効なプロバイダです', 'andw-ai-translate' ) );
		}
	}

	/**
	 * OpenAI GPTでの翻訳
	 *
	 * @param string $text 翻訳するテキスト
	 * @param string $target_language 対象言語
	 * @return string|WP_Error 翻訳結果またはエラー
	 */
	private function translate_with_openai( $text, $target_language ) {
		$api_key = $this->api_manager->get_api_key( 'openai' );
		if ( ! $api_key ) {
			return new WP_Error( 'no_api_key', __( 'OpenAI APIキーが設定されていません', 'andw-ai-translate' ) );
		}

		$language_name = $this->get_language_name( $target_language );
		$prompt = $this->get_translation_prompt( $text, $language_name );

		$body = array(
			'model' => 'gpt-3.5-turbo',
			'messages' => array(
				array(
					'role' => 'user',
					'content' => $prompt,
				),
			),
			'temperature' => 0,
			'max_tokens' => 2000,
		);

		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type' => 'application/json',
				),
				'body' => wp_json_encode( $body ),
				'timeout' => 60,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'api_error', __( 'OpenAI APIへの接続に失敗しました', 'andw-ai-translate' ) );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$data = json_decode( $response_body, true );

		if ( $status_code !== 200 ) {
			$error_message = isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'OpenAI API エラーが発生しました', 'andw-ai-translate' );
			return new WP_Error( 'api_error', $error_message );
		}

		if ( ! isset( $data['choices'][0]['message']['content'] ) ) {
			return new WP_Error( 'invalid_response', __( 'OpenAI APIから無効な応答を受信しました', 'andw-ai-translate' ) );
		}

		return trim( $data['choices'][0]['message']['content'] );
	}

	/**
	 * Claude APIでの翻訳
	 *
	 * @param string $text 翻訳するテキスト
	 * @param string $target_language 対象言語
	 * @return string|WP_Error 翻訳結果またはエラー
	 */
	private function translate_with_claude( $text, $target_language ) {
		$api_key = $this->api_manager->get_api_key( 'claude' );
		if ( ! $api_key ) {
			return new WP_Error( 'no_api_key', __( 'Claude APIキーが設定されていません', 'andw-ai-translate' ) );
		}

		$language_name = $this->get_language_name( $target_language );
		$prompt = $this->get_translation_prompt( $text, $language_name );

		$body = array(
			'model' => 'claude-3-haiku-20240307',
			'max_tokens' => 2000,
			'temperature' => 0,
			'messages' => array(
				array(
					'role' => 'user',
					'content' => $prompt,
				),
			),
		);

		$response = wp_remote_post(
			'https://api.anthropic.com/v1/messages',
			array(
				'headers' => array(
					'x-api-key' => $api_key,
					'Content-Type' => 'application/json',
					'anthropic-version' => '2023-06-01',
				),
				'body' => wp_json_encode( $body ),
				'timeout' => 60,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'api_error', __( 'Claude APIへの接続に失敗しました', 'andw-ai-translate' ) );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );
		$data = json_decode( $response_body, true );

		if ( $status_code !== 200 ) {
			$error_message = isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'Claude API エラーが発生しました', 'andw-ai-translate' );
			return new WP_Error( 'api_error', $error_message );
		}

		if ( ! isset( $data['content'][0]['text'] ) ) {
			return new WP_Error( 'invalid_response', __( 'Claude APIから無効な応答を受信しました', 'andw-ai-translate' ) );
		}

		return trim( $data['content'][0]['text'] );
	}

	/**
	 * 翻訳プロンプトの生成
	 *
	 * @param string $text 翻訳するテキスト
	 * @param string $language_name 対象言語名
	 * @return string プロンプト
	 */
	private function get_translation_prompt( $text, $language_name ) {
		return sprintf(
			"以下のテキストを%sに翻訳してください。原文の意味とトーンを正確に保ちながら、自然で読みやすい翻訳を提供してください。HTMLタグがある場合は、それらを保持してください。翻訳結果のみを出力し、説明や注釈は不要です。\n\n%s",
			$language_name,
			$text
		);
	}

	/**
	 * 言語コードから言語名を取得
	 *
	 * @param string $language_code 言語コード
	 * @return string 言語名
	 */
	private function get_language_name( $language_code ) {
		$languages = array(
			'en' => '英語',
			'zh' => '中国語（簡体字）',
			'zh-TW' => '中国語（繁体字）',
			'ko' => '韓国語',
			'fr' => 'フランス語',
			'de' => 'ドイツ語',
			'es' => 'スペイン語',
			'it' => 'イタリア語',
			'pt' => 'ポルトガル語',
			'ru' => 'ロシア語',
			'ja' => '日本語',
		);

		return isset( $languages[ $language_code ] ) ? $languages[ $language_code ] : $language_code;
	}

	/**
	 * テキストの前処理
	 *
	 * @param string $text 処理するテキスト
	 * @return string 処理済みテキスト
	 */
	private function preprocess_text( $text ) {
		// HTMLエンティティのデコード
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		// 余分な空白の削除
		$text = preg_replace( '/\s+/', ' ', $text );
		$text = trim( $text );

		return $text;
	}

	/**
	 * 使用制限のチェック
	 *
	 * @return bool|WP_Error 制限内ならtrue、制限超過ならエラー
	 */
	private function check_usage_limits() {
		$daily_limit = get_option( 'andw_ai_translate_limit_daily', 100 );
		$monthly_limit = get_option( 'andw_ai_translate_limit_monthly', 3000 );

		$daily_usage = $this->get_daily_usage();
		$monthly_usage = $this->get_monthly_usage();

		if ( $daily_usage >= $daily_limit ) {
			return new WP_Error( 'daily_limit_exceeded', __( '本日の翻訳回数制限に達しました', 'andw-ai-translate' ) );
		}

		if ( $monthly_usage >= $monthly_limit ) {
			return new WP_Error( 'monthly_limit_exceeded', __( '今月の翻訳回数制限に達しました', 'andw-ai-translate' ) );
		}

		return true;
	}

	/**
	 * 日次使用量の取得
	 */
	private function get_daily_usage() {
		$today = current_time( 'Y-m-d' );
		$last_reset = get_option( 'andw_ai_translate_last_reset_daily', '' );

		if ( $last_reset !== $today ) {
			update_option( 'andw_ai_translate_usage_daily', 0 );
			update_option( 'andw_ai_translate_last_reset_daily', $today );
			return 0;
		}

		return (int) get_option( 'andw_ai_translate_usage_daily', 0 );
	}

	/**
	 * 月次使用量の取得
	 */
	private function get_monthly_usage() {
		$this_month = current_time( 'Y-m' );
		$last_reset = get_option( 'andw_ai_translate_last_reset_monthly', '' );

		if ( $last_reset !== $this_month ) {
			update_option( 'andw_ai_translate_usage_monthly', 0 );
			update_option( 'andw_ai_translate_last_reset_monthly', $this_month );
			return 0;
		}

		return (int) get_option( 'andw_ai_translate_usage_monthly', 0 );
	}

	/**
	 * 使用量の記録
	 */
	private function record_usage() {
		$daily_usage = $this->get_daily_usage();
		$monthly_usage = $this->get_monthly_usage();

		update_option( 'andw_ai_translate_usage_daily', $daily_usage + 1 );
		update_option( 'andw_ai_translate_usage_monthly', $monthly_usage + 1 );
	}

	/**
	 * 利用可能なプロバイダの取得
	 */
	public function get_available_providers() {
		$providers = array();

		if ( $this->api_manager->has_api_key( 'openai' ) ) {
			$providers['openai'] = 'OpenAI GPT';
		}

		if ( $this->api_manager->has_api_key( 'claude' ) ) {
			$providers['claude'] = 'Claude';
		}

		return $providers;
	}

	/**
	 * 使用量統計の取得
	 */
	public function get_usage_stats() {
		return array(
			'daily_usage' => $this->get_daily_usage(),
			'daily_limit' => get_option( 'andw_ai_translate_limit_daily', 100 ),
			'monthly_usage' => $this->get_monthly_usage(),
			'monthly_limit' => get_option( 'andw_ai_translate_limit_monthly', 3000 ),
		);
	}
}