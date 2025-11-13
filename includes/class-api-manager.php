<?php
/**
 * API管理・暗号化クラス
 */

// 直接アクセスを防ぐ
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * API キーの管理と暗号化を行うクラス
 */
class ANDW_AI_Translate_API_Manager {

	/**
	 * 暗号化キー名
	 */
	const ENCRYPTION_KEY_OPTION = 'andw_ai_translate_encryption_key';

	/**
	 * コンストラクタ
	 */
	public function __construct() {
		// 暗号化キーの初期化
		$this->ensure_encryption_key();
	}

	/**
	 * 暗号化キーが存在しない場合は生成
	 */
	private function ensure_encryption_key() {
		if ( ! get_option( self::ENCRYPTION_KEY_OPTION ) ) {
			$key = base64_encode( random_bytes( 32 ) );
			update_option( self::ENCRYPTION_KEY_OPTION, $key );
		}
	}

	/**
	 * 暗号化キーの取得
	 */
	private function get_encryption_key() {
		return base64_decode( get_option( self::ENCRYPTION_KEY_OPTION ) );
	}

	/**
	 * データの暗号化
	 */
	private function encrypt( $data ) {
		$key = $this->get_encryption_key();
		$iv = random_bytes( 16 );
		$encrypted = openssl_encrypt( $data, 'AES-256-CBC', $key, 0, $iv );
		return base64_encode( $iv . $encrypted );
	}

	/**
	 * データの復号化
	 */
	private function decrypt( $encrypted_data ) {
		try {
			$key = $this->get_encryption_key();
			if ( empty( $key ) ) {
				return false;
			}

			$data = base64_decode( $encrypted_data );
			if ( $data === false ) {
				return false;
			}

			if ( strlen( $data ) < 16 ) {
				return false;
			}

			$iv = substr( $data, 0, 16 );
			$encrypted = substr( $data, 16 );

			$decrypted = openssl_decrypt( $encrypted, 'AES-256-CBC', $key, 0, $iv );

			if ( $decrypted === false ) {
				return false;
			}

			return $decrypted;

		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * APIキーの保存（暗号化）
	 */
	public function save_api_key( $provider, $api_key ) {
		// 権限チェック（所有者のみ）
		if ( ! $this->is_owner() ) {
			return new WP_Error( 'permission_denied', __( 'この操作を実行する権限がありません', 'andw-ai-translate' ) );
		}

		if ( ! in_array( $provider, array( 'openai', 'claude' ), true ) ) {
			return new WP_Error( 'invalid_provider', __( '無効なプロバイダです', 'andw-ai-translate' ) );
		}

		// APIキーの検証
		$validation = $this->validate_api_key( $provider, $api_key );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		// 暗号化して保存
		$encrypted_key = $this->encrypt( $api_key );
		$option_name = sprintf( 'andw_ai_translate_%s_key', $provider );
		update_option( $option_name, $encrypted_key );

		return true;
	}

	/**
	 * APIキーの取得（復号化）
	 */
	public function get_api_key( $provider ) {
		if ( ! in_array( $provider, array( 'openai', 'claude' ), true ) ) {
			return false;
		}

		$option_name = sprintf( 'andw_ai_translate_%s_key', $provider );
		$encrypted_key = get_option( $option_name );

		if ( ! $encrypted_key ) {
			return false;
		}

		$decrypted_key = $this->decrypt( $encrypted_key );

		if ( $decrypted_key === false ) {
			return false;
		}

		if ( empty( $decrypted_key ) ) {
			return false;
		}

		return $decrypted_key;
	}

	/**
	 * APIキーの削除
	 */
	public function delete_api_key( $provider ) {
		if ( ! $this->is_owner() ) {
			return new WP_Error( 'permission_denied', __( 'この操作を実行する権限がありません', 'andw-ai-translate' ) );
		}

		if ( ! in_array( $provider, array( 'openai', 'claude' ), true ) ) {
			return new WP_Error( 'invalid_provider', __( '無効なプロバイダです', 'andw-ai-translate' ) );
		}

		$option_name = sprintf( 'andw_ai_translate_%s_key', $provider );
		delete_option( $option_name );

		return true;
	}

	/**
	 * 全APIキーの削除（即時停止用）
	 */
	public function delete_all_api_keys() {
		if ( ! $this->is_owner() ) {
			return new WP_Error( 'permission_denied', __( 'この操作を実行する権限がありません', 'andw-ai-translate' ) );
		}

		delete_option( 'andw_ai_translate_openai_key' );
		delete_option( 'andw_ai_translate_claude_key' );

		return true;
	}

	/**
	 * APIキーの検証
	 */
	public function validate_api_key( $provider, $api_key ) {
		if ( empty( $api_key ) ) {
			return new WP_Error( 'empty_key', __( 'APIキーが空です', 'andw-ai-translate' ) );
		}

		switch ( $provider ) {
			case 'openai':
				return $this->validate_openai_key( $api_key );
			case 'claude':
				return $this->validate_claude_key( $api_key );
			default:
				return new WP_Error( 'invalid_provider', __( '無効なプロバイダです', 'andw-ai-translate' ) );
		}
	}

	/**
	 * OpenAI APIキーの検証
	 */
	private function validate_openai_key( $api_key ) {
		// フォーマットチェック（従来形式 sk- と新形式 sk-proj- の両方に対応）
		if ( ! preg_match( '/^sk-(?:proj-)?[A-Za-z0-9_-]{20,}$/', $api_key ) ) {
			return new WP_Error( 'invalid_format', __( 'OpenAI APIキーのフォーマットが正しくありません', 'andw-ai-translate' ) );
		}

		// 実際のAPI呼び出しテスト
		$response = wp_remote_get(
			'https://api.openai.com/v1/models',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type' => 'application/json',
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'connection_error', __( 'OpenAI APIへの接続に失敗しました', 'andw-ai-translate' ) );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code !== 200 ) {
			return new WP_Error( 'invalid_key', __( 'OpenAI APIキーが無効です', 'andw-ai-translate' ) );
		}

		return true;
	}

	/**
	 * Claude APIキーの検証
	 */
	private function validate_claude_key( $api_key ) {
		// フォーマットチェック
		if ( ! preg_match( '/^sk-ant-[A-Za-z0-9_-]+$/', $api_key ) ) {
			return new WP_Error( 'invalid_format', __( 'Claude APIキーのフォーマットが正しくありません', 'andw-ai-translate' ) );
		}

		// 実際のAPI呼び出しテスト
		$response = wp_remote_post(
			'https://api.anthropic.com/v1/messages',
			array(
				'headers' => array(
					'x-api-key' => $api_key,
					'Content-Type' => 'application/json',
					'anthropic-version' => '2023-06-01',
				),
				'body' => wp_json_encode( array(
					'model' => 'claude-3-haiku-20240307',
					'max_tokens' => 10,
					'messages' => array(
						array(
							'role' => 'user',
							'content' => 'Test',
						),
					),
				) ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'connection_error', __( 'Claude APIへの接続に失敗しました', 'andw-ai-translate' ) );
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( ! in_array( $status_code, array( 200, 400 ), true ) ) {
			return new WP_Error( 'invalid_key', __( 'Claude APIキーが無効です', 'andw-ai-translate' ) );
		}

		return true;
	}

	/**
	 * 所有者かどうかの判定
	 */
	private function is_owner() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * 納品完了の設定
	 */
	public function mark_delivery_completed() {
		if ( ! $this->is_owner() ) {
			return new WP_Error( 'permission_denied', __( 'この操作を実行する権限がありません', 'andw-ai-translate' ) );
		}

		$delivery_date = current_time( 'timestamp' );
		update_option( 'andw_ai_translate_delivery_date', $delivery_date );

		// 期限の設定
		$expiry_preset = (int) get_option( 'andw_ai_translate_expiry_preset', 30 );
		$expiry_date = $delivery_date + ( $expiry_preset * DAY_IN_SECONDS );
		update_option( 'andw_ai_translate_expiry_date', $expiry_date );

		return true;
	}

	/**
	 * 期限延長（1回のみ）
	 */
	public function extend_expiry() {
		if ( ! $this->is_owner() ) {
			return new WP_Error( 'permission_denied', __( 'この操作を実行する権限がありません', 'andw-ai-translate' ) );
		}

		// 延長使用済みチェック
		if ( get_option( 'andw_ai_translate_extension_used' ) ) {
			return new WP_Error( 'extension_used', __( '期限延長は1回のみ利用可能です', 'andw-ai-translate' ) );
		}

		$current_expiry = get_option( 'andw_ai_translate_expiry_date' );
		if ( ! $current_expiry ) {
			return new WP_Error( 'no_expiry', __( '期限が設定されていません', 'andw-ai-translate' ) );
		}

		// 30日延長
		$new_expiry = $current_expiry + ( 30 * DAY_IN_SECONDS );
		update_option( 'andw_ai_translate_expiry_date', $new_expiry );
		update_option( 'andw_ai_translate_extension_used', true );

		return true;
	}

	/**
	 * 即時停止
	 */
	public function emergency_stop() {
		if ( ! $this->is_owner() ) {
			return new WP_Error( 'permission_denied', __( 'この操作を実行する権限がありません', 'andw-ai-translate' ) );
		}

		// APIキーの削除
		$this->delete_all_api_keys();

		// 期限関連の削除
		delete_option( 'andw_ai_translate_delivery_date' );
		delete_option( 'andw_ai_translate_expiry_date' );

		// 実行中のキューの削除
		delete_transient( 'andw_ai_translate_queue' );

		return true;
	}

	/**
	 * APIキーの存在確認
	 */
	public function has_api_key( $provider = null ) {
		try {
			if ( $provider ) {
				$api_key = $this->get_api_key( $provider );
				$has_key = ! empty( $api_key );

				return $has_key;
			}

			// いずれかのAPIキーが存在するか
			$has_openai = $this->has_api_key( 'openai' );
			$has_claude = $this->has_api_key( 'claude' );

			$has_any = $has_openai || $has_claude;

			return $has_any;

		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * マスクされたAPIキーを取得
	 */
	public function get_masked_api_key( $provider ) {
		$api_key = $this->get_api_key( $provider );
		if ( empty( $api_key ) ) {
			return false;
		}

		$key_length = strlen( $api_key );

		// 短いキーの場合は安全な表示にする
		if ( $key_length <= 15 ) {
			return substr( $api_key, 0, 5 ) . '*****';
		}

		// 先頭10文字 + ***** + 末尾5文字
		$start = substr( $api_key, 0, 10 );
		$end = substr( $api_key, -5 );
		return $start . '*****' . $end;
	}
}