<?php
/**
 * 期限管理・自動削除クラス
 */

// 直接アクセスを防ぐ
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * APIキー期限管理と自動削除を行うクラス
 */
class ANDW_AI_Translate_Expiry_Manager {

	/**
	 * コンストラクタ
	 */
	public function __construct() {
		// 期限チェック用のフック
		add_action( 'wp_loaded', array( $this, 'check_expiry' ) );
		add_action( 'andw_ai_translate_daily_check', array( $this, 'daily_expiry_check' ) );

		// 日次チェックのスケジュール設定
		if ( ! wp_next_scheduled( 'andw_ai_translate_daily_check' ) ) {
			wp_schedule_event( time(), 'daily', 'andw_ai_translate_daily_check' );
		}
	}

	/**
	 * 期限チェック（毎回実行）
	 */
	public function check_expiry() {
		$expiry_date = get_option( 'andw_ai_translate_expiry_date' );
		if ( ! $expiry_date ) {
			return;
		}

		$current_time = current_time( 'timestamp' );

		// 期限切れの場合は自動削除
		if ( $current_time > $expiry_date ) {
			$this->auto_delete_expired();
			return;
		}

		// 7日前通知
		$warning_time = $expiry_date - ( 7 * DAY_IN_SECONDS );
		if ( $current_time > $warning_time && ! get_user_meta( 1, 'andw_ai_translate_warning_shown_' . $expiry_date, true ) ) {
			$this->show_expiry_warning();
			update_user_meta( 1, 'andw_ai_translate_warning_shown_' . $expiry_date, true );
		}
	}

	/**
	 * 日次期限チェック
	 */
	public function daily_expiry_check() {
		$this->check_expiry();
	}

	/**
	 * 期限切れ時の自動削除
	 */
	public function auto_delete_expired() {
		// APIキーの削除
		delete_option( 'andw_ai_translate_openai_key' );
		delete_option( 'andw_ai_translate_claude_key' );

		// 期限関連情報の削除
		delete_option( 'andw_ai_translate_delivery_date' );
		delete_option( 'andw_ai_translate_expiry_date' );
		delete_option( 'andw_ai_translate_extension_used' );

		// 実行中のキューの削除
		delete_transient( 'andw_ai_translate_queue' );
		delete_transient( 'andw_ai_translate_processing' );

		// 通知の設定
		set_transient( 'andw_ai_translate_expired_notice', true, HOUR_IN_SECONDS );
	}

	/**
	 * 期限警告の表示
	 */
	public function show_expiry_warning() {
		add_action( 'admin_notices', array( $this, 'display_expiry_warning' ) );
	}

	/**
	 * 期限警告通知の表示
	 */
	public function display_expiry_warning() {
		$expiry_date = get_option( 'andw_ai_translate_expiry_date' );
		if ( ! $expiry_date ) {
			return;
		}

		$remaining_days = ceil( ( $expiry_date - current_time( 'timestamp' ) ) / DAY_IN_SECONDS );

		if ( $remaining_days <= 7 && $remaining_days > 0 ) {
			?>
			<div class="notice notice-warning is-dismissible">
				<p>
					<strong><?php esc_html_e( 'andW AI Translate', 'andw-ai-translate' ); ?>:</strong>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: remaining days */
							_n(
								'APIキーの有効期限まで残り %d 日です。',
								'APIキーの有効期限まで残り %d 日です。',
								$remaining_days,
								'andw-ai-translate'
							),
							$remaining_days
						)
					);
					?>
					<a href="<?php echo esc_url( admin_url( 'options-general.php?page=andw-ai-translate' ) ); ?>">
						<?php esc_html_e( '設定画面で期限を確認してください。', 'andw-ai-translate' ); ?>
					</a>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * 期限切れ通知の表示
	 */
	public function display_expired_notice() {
		if ( ! get_transient( 'andw_ai_translate_expired_notice' ) ) {
			return;
		}
		?>
		<div class="notice notice-error is-dismissible">
			<p>
				<strong><?php esc_html_e( 'andW AI Translate', 'andw-ai-translate' ); ?>:</strong>
				<?php esc_html_e( 'APIキーの有効期限が切れたため、自動削除されました。翻訳機能は無効になっています。', 'andw-ai-translate' ); ?>
			</p>
		</div>
		<?php
		delete_transient( 'andw_ai_translate_expired_notice' );
	}

	/**
	 * 管理バーに残日数バッジを追加
	 */
	public function add_admin_bar_menu( $wp_admin_bar ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$expiry_date = get_option( 'andw_ai_translate_expiry_date' );
		if ( ! $expiry_date ) {
			return;
		}

		$current_time = current_time( 'timestamp' );
		$remaining_days = ceil( ( $expiry_date - $current_time ) / DAY_IN_SECONDS );

		// 7日前からのみ表示
		if ( $remaining_days > 7 || $remaining_days < 0 ) {
			return;
		}

		$badge_color = $remaining_days <= 3 ? '#dc3232' : '#ffb900';
		/* translators: %d = 投稿の期限までの日数 */
		$badge_text = $remaining_days <= 0 ? __( '期限切れ', 'andw-ai-translate' ) : sprintf( __( '残り%d日', 'andw-ai-translate' ), $remaining_days );

		$wp_admin_bar->add_node(
			array(
				'id'    => 'andw-ai-translate-expiry',
				'title' => sprintf(
					'<span style="background-color: %s; color: white; padding: 2px 6px; border-radius: 3px; font-size: 11px;">%s</span>',
					esc_attr( $badge_color ),
					esc_html( $badge_text )
				),
				'href'  => admin_url( 'options-general.php?page=andw-ai-translate' ),
			)
		);
	}

	/**
	 * 残り日数の取得
	 */
	public function get_remaining_days() {
		$expiry_date = get_option( 'andw_ai_translate_expiry_date' );
		if ( ! $expiry_date ) {
			return false;
		}

		$current_time = current_time( 'timestamp' );
		return ceil( ( $expiry_date - $current_time ) / DAY_IN_SECONDS );
	}

	/**
	 * 期限情報の取得
	 */
	public function get_expiry_info() {
		$delivery_date = get_option( 'andw_ai_translate_delivery_date' );
		$expiry_date = get_option( 'andw_ai_translate_expiry_date' );
		$extension_used = get_option( 'andw_ai_translate_extension_used' );

		$info = array(
			'delivery_date' => $delivery_date,
			'expiry_date' => $expiry_date,
			'extension_used' => $extension_used,
			'remaining_days' => $this->get_remaining_days(),
			'is_expired' => false,
		);

		if ( $expiry_date && current_time( 'timestamp' ) > $expiry_date ) {
			$info['is_expired'] = true;
		}

		return $info;
	}

	/**
	 * プラグイン無効化時の処理
	 */
	public function on_plugin_deactivation() {
		// スケジュールイベントの削除
		wp_clear_scheduled_hook( 'andw_ai_translate_daily_check' );

		// 即時停止処理を実行
		$api_manager = new ANDW_AI_Translate_API_Manager();
		$api_manager->emergency_stop();
	}

	/**
	 * 期限切れかどうかの判定
	 */
	public function is_expired() {
		$expiry_date = get_option( 'andw_ai_translate_expiry_date' );
		if ( ! $expiry_date ) {
			return false;
		}

		return current_time( 'timestamp' ) > $expiry_date;
	}

	/**
	 * 機能が利用可能かどうかの判定
	 */
	public function is_feature_available() {
		// 期限切れチェック
		if ( $this->is_expired() ) {
			return false;
		}

		// APIキー存在チェック
		$api_manager = new ANDW_AI_Translate_API_Manager();
		return $api_manager->has_api_key();
	}
}