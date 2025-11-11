<?php
/**
 * 管理画面設定クラス
 */

// 直接アクセスを防ぐ
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 管理画面の設定ページを管理するクラス
 */
class ANDW_AI_Translate_Admin_Settings {

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
		// 依存クラスの安全な初期化
		if ( class_exists( 'ANDW_AI_Translate_API_Manager' ) ) {
			$this->api_manager = new ANDW_AI_Translate_API_Manager();
		}
		if ( class_exists( 'ANDW_AI_Translate_Expiry_Manager' ) ) {
			$this->expiry_manager = new ANDW_AI_Translate_Expiry_Manager();
		}

		// フック登録（優先度を高く設定してフォーム処理を確実に実行）
		add_action( 'admin_init', array( $this, 'handle_form_submission' ), 5 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'admin_notices', array( $this, 'display_admin_notices' ) );
	}

	/**
	 * 管理メニューの追加
	 */
	public function add_menu() {
		add_options_page(
			__( 'andW AI Translate 設定', 'andw-ai-translate' ),
			__( 'andW AI Translate', 'andw-ai-translate' ),
			'manage_options',
			'andw-ai-translate',
			array( $this, 'settings_page' )
		);
	}

	/**
	 * スクリプトとスタイルの読み込み
	 */
	public function enqueue_scripts( $hook ) {
		if ( 'settings_page_andw-ai-translate' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'andw-ai-translate-admin',
			ANDW_AI_TRANSLATE_PLUGIN_URL . 'assets/admin-style.css',
			array(),
			ANDW_AI_TRANSLATE_VERSION
		);

		wp_enqueue_script(
			'andw-ai-translate-admin',
			ANDW_AI_TRANSLATE_PLUGIN_URL . 'assets/admin-script.js',
			array( 'jquery' ),
			ANDW_AI_TRANSLATE_VERSION,
			true
		);

		wp_localize_script(
			'andw-ai-translate-admin',
			'andwAITranslate',
			array(
				'nonce' => wp_create_nonce( 'andw_ai_translate_admin' ),
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'strings' => array(
					'confirmStop' => __( '本当に即時停止しますか？この操作は取り消せません。', 'andw-ai-translate' ),
					'confirmDelivery' => __( '納品完了を実行しますか？期限のカウントダウンが開始されます。', 'andw-ai-translate' ),
					'confirmExtend' => __( '期限を30日延長しますか？延長は1回のみ利用可能です。', 'andw-ai-translate' ),
				),
			)
		);
	}

	/**
	 * フォーム送信の処理
	 */
	public function handle_form_submission() {
		// POSTリクエストでない場合は処理しない
		if ( ! isset( $_POST ) || empty( $_POST ) ) {
			return;
		}

		// デバッグ: フォーム送信の確認
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'andW AI Translate: フォーム送信を検出 - ' . print_r( array_keys( $_POST ), true ) );
		}

		// nonce と権限チェック（複数のnonceフィールドに対応）
		$nonce_fields = array(
			'andw_ai_translate_nonce_general',
			'andw_ai_translate_nonce_api',
			'andw_ai_translate_nonce_delivery',
			'andw_ai_translate_nonce_extend',
			'andw_ai_translate_nonce_stop'
		);

		$nonce_verified = false;
		foreach ( $nonce_fields as $nonce_field ) {
			if ( isset( $_POST[ $nonce_field ] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $nonce_field ] ) ), 'andw_ai_translate_save_settings' ) ) {
				$nonce_verified = true;
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'andW AI Translate: nonce検証成功 - ' . $nonce_field );
				}
				break;
			}
		}

		if ( ! $nonce_verified ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'andW AI Translate: nonce検証失敗 - ' . print_r( array_keys( $_POST ), true ) );
			}
			add_settings_error( 'andw_ai_translate', 'nonce_failed', __( 'セキュリティチェックに失敗しました。再度お試しください。', 'andw-ai-translate' ), 'error' );
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'この操作を実行する権限がありません', 'andw-ai-translate' ) );
		}

		// 設定保存の処理
		if ( isset( $_POST['save_settings'] ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'andW AI Translate: 一般設定保存を実行' );
			}
			$this->save_general_settings();
		}

		// APIキー保存の処理
		if ( isset( $_POST['save_api_keys'] ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'andW AI Translate: APIキー保存を実行' );
			}
			$this->save_api_keys();
		}

		// 納品完了の処理
		if ( isset( $_POST['mark_delivery_completed'] ) ) {
			$this->handle_delivery_completion();
		}

		// 期限延長の処理
		if ( isset( $_POST['extend_expiry'] ) ) {
			$this->handle_expiry_extension();
		}

		// 即時停止の処理
		if ( isset( $_POST['emergency_stop'] ) ) {
			$this->handle_emergency_stop();
		}
	}

	/**
	 * 一般設定の保存
	 */
	private function save_general_settings() {
		$saved_count = 0;

		// 既定プロバイダの保存
		if ( isset( $_POST['default_provider'] ) ) {
			$provider = sanitize_text_field( wp_unslash( $_POST['default_provider'] ) );
			if ( in_array( $provider, array( 'openai', 'claude' ), true ) ) {
				update_option( 'andw_ai_translate_provider', $provider );
				$saved_count++;
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'andW AI Translate: 既定プロバイダを保存 - ' . $provider );
				}
			}
		}

		// 対象言語の保存
		if ( isset( $_POST['target_languages'] ) && is_array( $_POST['target_languages'] ) ) {
			$languages = array_map( 'sanitize_text_field', wp_unslash( $_POST['target_languages'] ) );
			update_option( 'andw_ai_translate_languages', $languages );
			$saved_count++;
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'andW AI Translate: 対象言語を保存 - ' . implode( ', ', $languages ) );
			}
		} else {
			// 対象言語が未選択の場合は空配列で保存
			update_option( 'andw_ai_translate_languages', array() );
			$saved_count++;
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'andW AI Translate: 対象言語を空で保存' );
			}
		}

		// 期限プリセットの保存
		if ( isset( $_POST['expiry_preset'] ) ) {
			$preset = (int) $_POST['expiry_preset'];
			if ( in_array( $preset, array( 30, 60 ), true ) ) {
				update_option( 'andw_ai_translate_expiry_preset', $preset );
				$saved_count++;
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'andW AI Translate: 期限プリセットを保存 - ' . $preset );
				}
			}
		}

		// 使用制限の保存
		if ( isset( $_POST['limit_daily'] ) ) {
			$limit = (int) $_POST['limit_daily'];
			update_option( 'andw_ai_translate_limit_daily', max( 0, $limit ) );
			$saved_count++;
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'andW AI Translate: 日次制限を保存 - ' . $limit );
			}
		}

		if ( isset( $_POST['limit_monthly'] ) ) {
			$limit = (int) $_POST['limit_monthly'];
			update_option( 'andw_ai_translate_limit_monthly', max( 0, $limit ) );
			$saved_count++;
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'andW AI Translate: 月次制限を保存 - ' . $limit );
			}
		}

		if ( $saved_count > 0 ) {
			add_settings_error( 'andw_ai_translate', 'settings_saved', __( '設定を保存しました', 'andw-ai-translate' ), 'updated' );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'andW AI Translate: 設定保存完了 - ' . $saved_count . '項目' );
			}
		} else {
			add_settings_error( 'andw_ai_translate', 'no_changes', __( '保存する変更がありませんでした', 'andw-ai-translate' ), 'notice-warning' );
		}
	}

	/**
	 * APIキーの保存
	 */
	private function save_api_keys() {
		$saved = false;

		// OpenAI APIキー
		if ( ! empty( $_POST['openai_api_key'] ) ) {
			$api_key = sanitize_text_field( wp_unslash( $_POST['openai_api_key'] ) );
			$result = $this->api_manager->save_api_key( 'openai', $api_key );
			if ( is_wp_error( $result ) ) {
				add_settings_error( 'andw_ai_translate', 'openai_key_error', $result->get_error_message(), 'error' );
			} else {
				$saved = true;
			}
		}

		// Claude APIキー
		if ( ! empty( $_POST['claude_api_key'] ) ) {
			$api_key = sanitize_text_field( wp_unslash( $_POST['claude_api_key'] ) );
			$result = $this->api_manager->save_api_key( 'claude', $api_key );
			if ( is_wp_error( $result ) ) {
				add_settings_error( 'andw_ai_translate', 'claude_key_error', $result->get_error_message(), 'error' );
			} else {
				$saved = true;
			}
		}

		if ( $saved ) {
			add_settings_error( 'andw_ai_translate', 'api_keys_saved', __( 'APIキーを保存しました', 'andw-ai-translate' ), 'updated' );
		}
	}

	/**
	 * 納品完了の処理
	 */
	private function handle_delivery_completion() {
		$result = $this->api_manager->mark_delivery_completed();
		if ( is_wp_error( $result ) ) {
			add_settings_error( 'andw_ai_translate', 'delivery_error', $result->get_error_message(), 'error' );
		} else {
			add_settings_error( 'andw_ai_translate', 'delivery_completed', __( '納品完了を設定しました。期限のカウントダウンが開始されます。', 'andw-ai-translate' ), 'updated' );
		}
	}

	/**
	 * 期限延長の処理
	 */
	private function handle_expiry_extension() {
		$result = $this->api_manager->extend_expiry();
		if ( is_wp_error( $result ) ) {
			add_settings_error( 'andw_ai_translate', 'extension_error', $result->get_error_message(), 'error' );
		} else {
			add_settings_error( 'andw_ai_translate', 'extension_success', __( '期限を30日延長しました', 'andw-ai-translate' ), 'updated' );
		}
	}

	/**
	 * 即時停止の処理
	 */
	private function handle_emergency_stop() {
		$result = $this->api_manager->emergency_stop();
		if ( is_wp_error( $result ) ) {
			add_settings_error( 'andw_ai_translate', 'stop_error', $result->get_error_message(), 'error' );
		} else {
			add_settings_error( 'andw_ai_translate', 'stop_success', __( 'プラグインを即時停止しました。すべての機能が無効になっています。', 'andw-ai-translate' ), 'updated' );
		}
	}

	/**
	 * 管理通知の表示
	 */
	public function display_admin_notices() {
		if ( ! $this->expiry_manager->is_feature_available() ) {
			$this->expiry_manager->display_expired_notice();
		}

		settings_errors( 'andw_ai_translate' );
	}

	/**
	 * 設定ページの表示
	 */
	public function settings_page() {
		// 権限チェック
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'この操作を実行する権限がありません', 'andw-ai-translate' ) );
		}

		$expiry_info = $this->expiry_manager->get_expiry_info();
		$default_provider = get_option( 'andw_ai_translate_provider', 'openai' );
		$target_languages = get_option( 'andw_ai_translate_languages', array( 'en', 'zh', 'ko' ) );
		$expiry_preset = get_option( 'andw_ai_translate_expiry_preset', 30 );
		$limit_daily = get_option( 'andw_ai_translate_limit_daily', 100 );
		$limit_monthly = get_option( 'andw_ai_translate_limit_monthly', 3000 );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'andW AI Translate 設定', 'andw-ai-translate' ); ?></h1>

			<!-- 期限情報 -->
			<?php if ( $expiry_info['expiry_date'] ) : ?>
			<div class="notice notice-info">
				<p>
					<strong><?php esc_html_e( '期限情報:', 'andw-ai-translate' ); ?></strong>
					<?php if ( $expiry_info['is_expired'] ) : ?>
						<span style="color: red;"><?php esc_html_e( '期限切れ', 'andw-ai-translate' ); ?></span>
					<?php else : ?>
						<?php
						echo esc_html(
							sprintf(
								/* translators: %d: remaining days */
								__( '残り %d 日', 'andw-ai-translate' ),
								$expiry_info['remaining_days']
							)
						);
						?>
					<?php endif; ?>
					<?php if ( $expiry_info['delivery_date'] ) : ?>
						（<?php echo esc_html( date_i18n( 'Y年m月d日', $expiry_info['delivery_date'] ) ); ?><?php esc_html_e( ' に納品完了', 'andw-ai-translate' ); ?>）
					<?php endif; ?>
				</p>
			</div>
			<?php endif; ?>

			<form method="post" action="">
				<?php wp_nonce_field( 'andw_ai_translate_save_settings', 'andw_ai_translate_nonce_general' ); ?>

				<!-- 一般設定 -->
				<h2><?php esc_html_e( '一般設定', 'andw-ai-translate' ); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( '既定プロバイダ', 'andw-ai-translate' ); ?></th>
						<td>
							<select name="default_provider">
								<option value="openai" <?php selected( $default_provider, 'openai' ); ?>><?php esc_html_e( 'OpenAI GPT', 'andw-ai-translate' ); ?></option>
								<option value="claude" <?php selected( $default_provider, 'claude' ); ?>><?php esc_html_e( 'Claude', 'andw-ai-translate' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( '対象言語', 'andw-ai-translate' ); ?></th>
						<td>
							<?php
							$available_languages = array(
								'en' => __( '英語 (English)', 'andw-ai-translate' ),
								'zh' => __( '中国語 (中文)', 'andw-ai-translate' ),
								'ko' => __( '韓国語 (한국어)', 'andw-ai-translate' ),
							);
							foreach ( $available_languages as $code => $label ) :
								$checked = in_array( $code, $target_languages, true ) ? 'checked="checked"' : '';
							?>
								<label style="display: block; margin: 5px 0;">
									<input type="checkbox" name="target_languages[]" value="<?php echo esc_attr( $code ); ?>" <?php echo $checked; ?> />
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( '翻訳対象とする言語を選択してください', 'andw-ai-translate' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( '期限プリセット', 'andw-ai-translate' ); ?></th>
						<td>
							<select name="expiry_preset">
								<option value="30" <?php selected( $expiry_preset, 30 ); ?>><?php esc_html_e( '30日', 'andw-ai-translate' ); ?></option>
								<option value="60" <?php selected( $expiry_preset, 60 ); ?>><?php esc_html_e( '60日', 'andw-ai-translate' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( '日次制限', 'andw-ai-translate' ); ?></th>
						<td>
							<input type="number" name="limit_daily" value="<?php echo esc_attr( $limit_daily ); ?>" min="0" step="1" />
							<p class="description"><?php esc_html_e( '1日あたりの翻訳回数制限', 'andw-ai-translate' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( '月次制限', 'andw-ai-translate' ); ?></th>
						<td>
							<input type="number" name="limit_monthly" value="<?php echo esc_attr( $limit_monthly ); ?>" min="0" step="1" />
							<p class="description"><?php esc_html_e( '1ヶ月あたりの翻訳回数制限', 'andw-ai-translate' ); ?></p>
						</td>
					</tr>
				</table>

				<p class="submit">
					<input type="submit" name="save_settings" class="button-primary" value="<?php esc_attr_e( '設定を保存', 'andw-ai-translate' ); ?>" />
				</p>
			</form>

			<!-- APIキー設定 -->
			<form method="post" action="">
				<?php wp_nonce_field( 'andw_ai_translate_save_settings', 'andw_ai_translate_nonce_api' ); ?>

				<h2><?php esc_html_e( 'APIキー設定', 'andw-ai-translate' ); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'OpenAI APIキー', 'andw-ai-translate' ); ?></th>
						<td>
							<input type="password" name="openai_api_key" class="regular-text" placeholder="<?php esc_attr_e( 'sk-...', 'andw-ai-translate' ); ?>" />
							<?php if ( $this->api_manager->has_api_key( 'openai' ) ) : ?>
								<span style="color: green;">✓ <?php esc_html_e( '設定済み', 'andw-ai-translate' ); ?></span>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( '空欄のままにすると既存の設定を保持します', 'andw-ai-translate' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Claude APIキー', 'andw-ai-translate' ); ?></th>
						<td>
							<input type="password" name="claude_api_key" class="regular-text" placeholder="<?php esc_attr_e( 'sk-ant-...', 'andw-ai-translate' ); ?>" />
							<?php if ( $this->api_manager->has_api_key( 'claude' ) ) : ?>
								<span style="color: green;">✓ <?php esc_html_e( '設定済み', 'andw-ai-translate' ); ?></span>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( '空欄のままにすると既存の設定を保持します', 'andw-ai-translate' ); ?></p>
						</td>
					</tr>
				</table>

				<p class="submit">
					<input type="submit" name="save_api_keys" class="button-primary" value="<?php esc_attr_e( 'APIキーを保存', 'andw-ai-translate' ); ?>" />
				</p>
			</form>

			<!-- 期限管理 -->
			<?php if ( current_user_can( 'manage_options' ) && wp_get_current_user()->ID === 1 ) : ?>
			<h2><?php esc_html_e( '期限管理（所有者のみ）', 'andw-ai-translate' ); ?></h2>

			<form method="post" action="" onsubmit="return confirm('<?php echo esc_js( __( '本当に納品完了を実行しますか？', 'andw-ai-translate' ) ); ?>');">
				<?php wp_nonce_field( 'andw_ai_translate_save_settings', 'andw_ai_translate_nonce_delivery' ); ?>
				<?php if ( ! $expiry_info['delivery_date'] ) : ?>
					<p class="submit">
						<input type="submit" name="mark_delivery_completed" class="button-secondary" value="<?php esc_attr_e( '納品完了', 'andw-ai-translate' ); ?>" />
					</p>
				<?php endif; ?>
			</form>

			<?php if ( $expiry_info['expiry_date'] && ! $expiry_info['extension_used'] && ! $expiry_info['is_expired'] ) : ?>
			<form method="post" action="" onsubmit="return confirm('<?php echo esc_js( __( '本当に期限を延長しますか？', 'andw-ai-translate' ) ); ?>');">
				<?php wp_nonce_field( 'andw_ai_translate_save_settings', 'andw_ai_translate_nonce_extend' ); ?>
				<p class="submit">
					<input type="submit" name="extend_expiry" class="button-secondary" value="<?php esc_attr_e( '期限延長（30日・1回のみ）', 'andw-ai-translate' ); ?>" />
				</p>
			</form>
			<?php endif; ?>

			<form method="post" action="" onsubmit="return confirm('<?php echo esc_js( __( '本当に即時停止しますか？この操作は取り消せません。', 'andw-ai-translate' ) ); ?>');">
				<?php wp_nonce_field( 'andw_ai_translate_save_settings', 'andw_ai_translate_nonce_stop' ); ?>
				<p class="submit">
					<input type="submit" name="emergency_stop" class="button-secondary" style="background-color: #dc3232; border-color: #dc3232; color: white;" value="<?php esc_attr_e( '即時停止', 'andw-ai-translate' ); ?>" />
				</p>
			</form>
			<?php endif; ?>
		</div>
		<?php
	}
}