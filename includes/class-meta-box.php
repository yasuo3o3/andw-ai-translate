<?php
/**
 * 編集画面メタボックスクラス
 */

// 直接アクセスを防ぐ
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 投稿編集画面に翻訳機能のメタボックスを追加するクラス
 */
class ANDW_AI_Translate_Meta_Box {

	/**
	 * 翻訳エンジンインスタンス
	 */
	private $translation_engine;

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
		$this->translation_engine = new ANDW_AI_Translate_Translation_Engine();
		$this->block_parser = new ANDW_AI_Translate_Block_Parser();
		$this->expiry_manager = new ANDW_AI_Translate_Expiry_Manager();

		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_andw_ai_translate_post', array( $this, 'ajax_translate_post' ) );
		add_action( 'wp_ajax_andw_ai_translate_ab_compare', array( $this, 'ajax_ab_compare' ) );
		add_action( 'wp_ajax_andw_ai_translate_approve', array( $this, 'ajax_approve_translation' ) );
	}

	/**
	 * メタボックスの追加
	 */
	public function add_meta_box() {
		// 利用可能性チェック
		if ( ! $this->expiry_manager->is_feature_available() ) {
			return;
		}

		$screens = array( 'post', 'page' );
		foreach ( $screens as $screen ) {
			add_meta_box(
				'andw-ai-translate',
				__( 'AI翻訳', 'andw-ai-translate' ),
				array( $this, 'render_meta_box' ),
				$screen,
				'normal',
				'default'
			);
		}
	}

	/**
	 * スクリプトとスタイルの読み込み
	 */
	public function enqueue_scripts( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		if ( ! $this->expiry_manager->is_feature_available() ) {
			return;
		}

		wp_enqueue_style(
			'andw-ai-translate-meta-box',
			ANDW_AI_TRANSLATE_PLUGIN_URL . 'assets/meta-box-style.css',
			array(),
			ANDW_AI_TRANSLATE_VERSION
		);

		wp_enqueue_script(
			'andw-ai-translate-meta-box',
			ANDW_AI_TRANSLATE_PLUGIN_URL . 'assets/meta-box-script.js',
			array( 'jquery', 'wp-util' ),
			ANDW_AI_TRANSLATE_VERSION,
			true
		);

		// ローカライゼーション
		wp_localize_script(
			'andw-ai-translate-meta-box',
			'andwTranslate',
			array(
				'nonce' => wp_create_nonce( 'andw_ai_translate_meta_box' ),
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'postId' => get_the_ID(),
				'settings' => array(
					'defaultProvider' => get_option( 'andw_ai_translate_provider', 'openai' ),
					'configuredLanguages' => get_option( 'andw_ai_translate_languages', array( 'en', 'zh', 'ko' ) ),
				),
				'strings' => array(
					'translating' => __( '翻訳中...', 'andw-ai-translate' ),
					'translated' => __( '翻訳完了', 'andw-ai-translate' ),
					'error' => __( 'エラーが発生しました', 'andw-ai-translate' ),
					'confirmApprove' => __( 'この翻訳を承認しますか？', 'andw-ai-translate' ),
					'confirmReject' => __( 'この翻訳を却下しますか？', 'andw-ai-translate' ),
					'noProvider' => __( '利用可能なプロバイダがありません', 'andw-ai-translate' ),
					'showOriginal' => __( '原文を表示', 'andw-ai-translate' ),
					'hideOriginal' => __( '原文を非表示', 'andw-ai-translate' ),
				),
			)
		);
	}

	/**
	 * メタボックスの表示
	 */
	public function render_meta_box( $post ) {
		// nonce フィールド
		wp_nonce_field( 'andw_ai_translate_meta_box', 'andw_ai_translate_nonce' );

		// 利用可能性チェック
		if ( ! $this->expiry_manager->is_feature_available() ) {
			echo '<p>' . esc_html__( '翻訳機能は現在利用できません', 'andw-ai-translate' ) . '</p>';
			return;
		}

		// プロバイダの取得
		$available_providers = $this->translation_engine->get_available_providers();
		if ( empty( $available_providers ) ) {
			echo '<p>' . esc_html__( 'APIキーが設定されていません', 'andw-ai-translate' ) . '</p>';
			echo '<p><a href="' . esc_url( admin_url( 'options-general.php?page=andw-ai-translate' ) ) . '">' . esc_html__( '設定画面', 'andw-ai-translate' ) . '</a></p>';
			return;
		}

		// 使用量統計
		$usage_stats = $this->translation_engine->get_usage_stats();

		// 既存の翻訳データの取得
		$translation_data = get_post_meta( $post->ID, '_andw_ai_translate_data', true );
		?>
		<div id="andw-ai-translate-meta-box">

			<!-- 使用量統計 -->
			<div class="andw-usage-stats">
				<div class="andw-usage-item">
					<span class="label"><?php esc_html_e( '本日', 'andw-ai-translate' ); ?></span>
					<span class="value"><?php echo esc_html( $usage_stats['daily_usage'] . '/' . $usage_stats['daily_limit'] ); ?></span>
				</div>
				<div class="andw-usage-item">
					<span class="label"><?php esc_html_e( '今月', 'andw-ai-translate' ); ?></span>
					<span class="value"><?php echo esc_html( $usage_stats['monthly_usage'] . '/' . $usage_stats['monthly_limit'] ); ?></span>
				</div>
			</div>

			<!-- 翻訳設定 -->
			<div class="andw-translate-settings">
				<h4><?php esc_html_e( '翻訳設定', 'andw-ai-translate' ); ?></h4>

				<p>
					<label for="andw-target-language"><?php esc_html_e( '対象言語', 'andw-ai-translate' ); ?></label>
					<select id="andw-target-language" name="target_language">
						<?php
						// 設定画面で選択された対象言語のみを表示
						$configured_languages = get_option( 'andw_ai_translate_languages', array( 'en', 'zh', 'ko' ) );
						$all_available_languages = array(
							'en' => __( '英語 (English)', 'andw-ai-translate' ),
							'zh' => __( '中国語（簡体字/中国）', 'andw-ai-translate' ),
							'zh-TW' => __( '中国語（繁体字/台湾・香港）', 'andw-ai-translate' ),
							'ko' => __( '韓国語 (한국어)', 'andw-ai-translate' ),
							'fr' => __( 'フランス語 (Français)', 'andw-ai-translate' ),
							'de' => __( 'ドイツ語 (Deutsch)', 'andw-ai-translate' ),
							'es' => __( 'スペイン語 (Español)', 'andw-ai-translate' ),
							'mn' => __( 'モンゴル語 (монгол хэл)', 'andw-ai-translate' ),
						);

						if ( empty( $configured_languages ) ) {
							// 設定が空の場合は警告を表示
							echo '<option value="" disabled>' . esc_html__( '対象言語が設定されていません', 'andw-ai-translate' ) . '</option>';
						} else {
							// 設定された言語のみを表示
							foreach ( $configured_languages as $code ) {
								if ( isset( $all_available_languages[ $code ] ) ) {
									echo '<option value="' . esc_attr( $code ) . '">' . esc_html( $all_available_languages[ $code ] ) . '</option>';
								}
							}
						}
						?>
					</select>
					<?php if ( empty( $configured_languages ) ) : ?>
						<p class="description" style="color: #dc3232;">
							<?php esc_html_e( '翻訳対象言語を', 'andw-ai-translate' ); ?>
							<a href="<?php echo esc_url( admin_url( 'options-general.php?page=andw-ai-translate' ) ); ?>">
								<?php esc_html_e( '設定画面', 'andw-ai-translate' ); ?>
							</a>
							<?php esc_html_e( 'で選択してください。', 'andw-ai-translate' ); ?>
						</p>
					<?php endif; ?>
				</p>

				<p>
					<label for="andw-provider"><?php esc_html_e( 'プロバイダ', 'andw-ai-translate' ); ?></label>
					<select id="andw-provider" name="provider">
						<?php
						// 設定画面の既定プロバイダを取得
						$default_provider = get_option( 'andw_ai_translate_provider', 'openai' );

						foreach ( $available_providers as $key => $name ) :
							$is_selected = ( $key === $default_provider );
						?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $is_selected, true ); ?>>
								<?php echo esc_html( $name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description">
						<?php
						/* translators: %s: selected provider name */
						printf(
							esc_html__( '設定画面の既定プロバイダ「%s」が選択されています', 'andw-ai-translate' ),
							esc_html( isset( $available_providers[ $default_provider ] ) ? $available_providers[ $default_provider ] : $default_provider )
						);
						?>
					</p>
				</p>
			</div>

			<!-- 翻訳操作 -->
			<div class="andw-translate-actions">
				<p>
					<button type="button" id="andw-translate-post" class="button button-primary button-large">
						<?php esc_html_e( 'ページ全体を翻訳', 'andw-ai-translate' ); ?>
					</button>
				</p>

				<p>
					<button type="button" id="andw-ab-compare" class="button button-secondary">
						<?php esc_html_e( 'A/B比較モード', 'andw-ai-translate' ); ?>
					</button>
				</p>
			</div>

			<!-- 翻訳結果表示エリア -->
			<div id="andw-translation-results" style="display: none;">
				<h4><?php esc_html_e( '翻訳結果', 'andw-ai-translate' ); ?></h4>

				<!-- 翻訳と再翻訳の表示 -->
				<div class="andw-translation-pair">
					<div class="andw-translation-item">
						<h5><?php esc_html_e( '翻訳結果', 'andw-ai-translate' ); ?></h5>
						<div id="andw-translated-content"></div>
					</div>

					<div class="andw-translation-item">
						<h5><?php esc_html_e( '再翻訳（品質確認）', 'andw-ai-translate' ); ?></h5>
						<div id="andw-back-translated-content"></div>
					</div>
				</div>

				<!-- 承認・却下ボタン -->
				<div class="andw-approval-actions">
					<button type="button" id="andw-approve-translation" class="button button-primary">
						<?php esc_html_e( '承認', 'andw-ai-translate' ); ?>
					</button>
					<button type="button" id="andw-reject-translation" class="button button-secondary">
						<?php esc_html_e( '却下', 'andw-ai-translate' ); ?>
					</button>
				</div>

				<!-- 原文表示セクション -->
				<div class="andw-original-text-section">
					<div class="andw-original-text-toggle">
						<button type="button" id="toggle-original-text" class="button button-secondary">
							<span class="dashicons dashicons-visibility"></span>
							<?php esc_html_e( '原文を表示', 'andw-ai-translate' ); ?>
						</button>
						<small class="description"><?php esc_html_e( '翻訳品質確認のため日本語原文を表示', 'andw-ai-translate' ); ?></small>
					</div>

					<div id="original-text-container" style="display: none;">
						<h5><?php esc_html_e( '参考：日本語原文', 'andw-ai-translate' ); ?></h5>
						<div class="original-content">
							<?php echo wp_kses_post( $this->process_content_for_original_display( $post->post_content ) ); ?>
						</div>
						<div class="original-text-info">
							<small class="description">
								<?php
								$content_length = absint( mb_strlen( wp_strip_all_tags( $post->post_content ), 'UTF-8' ) );
								/* translators: %s = 投稿本文の文字数（国際化対応） */
								printf( esc_html__( '文字数: %s文字', 'andw-ai-translate' ), esc_html( number_format_i18n( $content_length ) ) );
								?>
							</small>
						</div>
					</div>
				</div>
			</div>

			<!-- A/B比較結果表示エリア -->
			<div id="andw-ab-results" style="display: none;">
				<h4><?php esc_html_e( 'A/B比較結果', 'andw-ai-translate' ); ?></h4>

				<div class="andw-ab-comparison">
					<div class="andw-ab-item">
						<h5><?php esc_html_e( 'プロバイダA', 'andw-ai-translate' ); ?> <span id="andw-provider-a-name"></span></h5>
						<div id="andw-translation-a"></div>
						<div id="andw-back-translation-a"></div>
						<button type="button" class="button andw-select-translation" data-provider="a">
							<?php esc_html_e( 'この翻訳を選択', 'andw-ai-translate' ); ?>
						</button>
					</div>

					<div class="andw-ab-item">
						<h5><?php esc_html_e( 'プロバイダB', 'andw-ai-translate' ); ?> <span id="andw-provider-b-name"></span></h5>
						<div id="andw-translation-b"></div>
						<div id="andw-back-translation-b"></div>
						<button type="button" class="button andw-select-translation" data-provider="b">
							<?php esc_html_e( 'この翻訳を選択', 'andw-ai-translate' ); ?>
						</button>
					</div>
				</div>
			</div>


			<!-- 進行状況表示 -->
			<div id="andw-progress" style="display: none;">
				<div class="andw-progress-bar">
					<div class="andw-progress-fill"></div>
				</div>
				<p id="andw-progress-text"></p>
			</div>

		</div>
		<?php
	}

	/**
	 * AJAX: 投稿の翻訳
	 */
	public function ajax_translate_post() {
		// エラーログ: 処理開始
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'andW AI Translate - AJAX翻訳処理開始' );
		}

		// 必要なPOSTデータの検証
		if ( ! isset( $_POST['nonce'], $_POST['post_id'], $_POST['target_language'], $_POST['provider'] ) ) {
			error_log( 'andW AI Translate - 必須パラメータ不足' );
			wp_send_json_error( __( '無効なリクエストです: 必須パラメータが不足しています', 'andw-ai-translate' ) );
		}

		$request = wp_unslash( $_POST );

		// nonce と権限チェック
		if ( ! wp_verify_nonce( sanitize_text_field( $request['nonce'] ), 'andw_ai_translate_meta_box' ) ||
			! current_user_can( 'edit_posts' ) ) {
			error_log( 'andW AI Translate - 権限エラーまたはnonce検証失敗' );
			wp_die( esc_html__( '権限がありません', 'andw-ai-translate' ) );
		}

		$post_id = absint( $request['post_id'] );
		$target_language = sanitize_text_field( $request['target_language'] );
		$provider = sanitize_text_field( $request['provider'] );

		// デバッグログ: リクエスト詳細
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf(
				'andW AI Translate - リクエスト詳細: PostID=%d, Language=%s, Provider=%s',
				$post_id,
				$target_language,
				$provider
			) );
		}

		try {
			// 翻訳エンジンの初期化確認
			if ( ! $this->block_parser ) {
				throw new Exception( 'ブロックパーサーが初期化されていません' );
			}

			if ( ! $this->translation_engine ) {
				throw new Exception( '翻訳エンジンが初期化されていません' );
			}

			// 翻訳の実行
			$result = $this->block_parser->translate_post_blocks( $post_id, $target_language, $provider );

			if ( is_wp_error( $result ) ) {
				error_log( 'andW AI Translate - ブロック翻訳エラー: ' . $result->get_error_message() );
				wp_send_json_error( 'ブロック翻訳エラー: ' . $result->get_error_message() );
			}

			// 再翻訳の実行（品質確認用：翻訳結果を元の言語に戻す）
			$back_translation = $this->translation_engine->back_translate( $result['translated_content'], 'ja', $provider );

			if ( is_wp_error( $back_translation ) ) {
				error_log( 'andW AI Translate - 再翻訳エラー: ' . $back_translation->get_error_message() );
				wp_send_json_error( '再翻訳エラー: ' . $back_translation->get_error_message() );
			}

			// 結果の保存（承認前の一時データ）
			$save_result = update_post_meta( $post_id, '_andw_ai_translate_pending', array(
				'translation_result' => $result,
				'back_translation' => $back_translation,
				'timestamp' => current_time( 'timestamp' ),
			) );

			if ( ! $save_result ) {
				error_log( 'andW AI Translate - 翻訳データの保存に失敗' );
				wp_send_json_error( '翻訳データの保存に失敗しました' );
			}

			// 成功ログ
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'andW AI Translate - 翻訳処理成功' );
			}

			wp_send_json_success( array(
				'translation' => $result,
				'back_translation' => $back_translation,
			) );

		} catch ( Exception $e ) {
			error_log( 'andW AI Translate - 例外エラー: ' . $e->getMessage() );
			wp_send_json_error( 'システムエラー: ' . $e->getMessage() );
		}
	}


	/**
	 * AJAX: A/B比較
	 */
	public function ajax_ab_compare() {
		// エラーログ: 処理開始
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'andW AI Translate - A/B比較処理開始' );
		}

		// 必要なPOSTデータの検証
		if ( ! isset( $_POST['nonce'], $_POST['post_id'], $_POST['target_language'] ) ) {
			error_log( 'andW AI Translate - A/B比較: 必須パラメータ不足' );
			wp_send_json_error( __( '無効なリクエストです: 必須パラメータが不足しています', 'andw-ai-translate' ) );
		}

		$request = wp_unslash( $_POST );

		// nonce と権限チェック
		if ( ! wp_verify_nonce( sanitize_text_field( $request['nonce'] ), 'andw_ai_translate_meta_box' ) ||
			! current_user_can( 'edit_posts' ) ) {
			error_log( 'andW AI Translate - A/B比較: 権限エラー' );
			wp_die( esc_html__( '権限がありません', 'andw-ai-translate' ) );
		}

		$post_id = absint( $request['post_id'] );
		$target_language = sanitize_text_field( $request['target_language'] );

		try {
			// 利用可能なプロバイダの取得
			if ( ! $this->translation_engine ) {
				throw new Exception( '翻訳エンジンが初期化されていません' );
			}

			$providers = $this->translation_engine->get_available_providers();
			$provider_keys = array_keys( $providers );

			if ( count( $provider_keys ) < 2 ) {
				error_log( 'andW AI Translate - A/B比較: 利用可能なプロバイダが不足 (' . count( $provider_keys ) . '個)' );
				wp_send_json_error( __( 'A/B比較には2つ以上のプロバイダが必要です', 'andw-ai-translate' ) );
			}

			$results = array();

			// 各プロバイダで翻訳実行
			foreach ( array_slice( $provider_keys, 0, 2 ) as $provider ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'andW AI Translate - A/B比較: プロバイダ ' . $provider . ' で翻訳実行中' );
				}

				$translation = $this->block_parser->translate_post_blocks( $post_id, $target_language, $provider );
				if ( is_wp_error( $translation ) ) {
					error_log( 'andW AI Translate - A/B比較翻訳エラー (' . $provider . '): ' . $translation->get_error_message() );
					wp_send_json_error( 'プロバイダ ' . $provider . ' の翻訳エラー: ' . $translation->get_error_message() );
				}

				$back_translation = $this->translation_engine->back_translate( $translation['translated_content'], 'ja', $provider );
				if ( is_wp_error( $back_translation ) ) {
					error_log( 'andW AI Translate - A/B比較再翻訳エラー (' . $provider . '): ' . $back_translation->get_error_message() );
					// 再翻訳エラーは警告程度に留める
					$back_translation = array( 'back_translated_text' => '再翻訳に失敗しました: ' . $back_translation->get_error_message() );
				}

				$results[ $provider ] = array(
					'translation' => $translation,
					'back_translation' => $back_translation,
				);
			}

			// 成功ログ
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'andW AI Translate - A/B比較処理成功: ' . count( $results ) . '個のプロバイダで完了' );
			}

			wp_send_json_success( $results );

		} catch ( Exception $e ) {
			error_log( 'andW AI Translate - A/B比較例外エラー: ' . $e->getMessage() );
			wp_send_json_error( 'A/B比較システムエラー: ' . $e->getMessage() );
		}
	}

	/**
	 * AJAX: 翻訳承認
	 */
	public function ajax_approve_translation() {
		// エラーログ: 処理開始
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'andW AI Translate - 翻訳承認処理開始' );
		}

		// 必要なPOSTデータの検証
		if ( ! isset( $_POST['nonce'], $_POST['post_id'], $_POST['target_language'] ) ) {
			error_log( 'andW AI Translate - 翻訳承認: 必須パラメータ不足' );
			wp_send_json_error( __( '無効なリクエストです: 必須パラメータが不足しています', 'andw-ai-translate' ) );
		}

		$request = wp_unslash( $_POST );

		// nonce と権限チェック
		if ( ! wp_verify_nonce( sanitize_text_field( $request['nonce'] ), 'andw_ai_translate_meta_box' ) ||
			! current_user_can( 'edit_posts' ) ) {
			error_log( 'andW AI Translate - 翻訳承認: 権限エラー' );
			wp_die( esc_html__( '権限がありません', 'andw-ai-translate' ) );
		}

		$post_id = absint( $request['post_id'] );
		$target_language = sanitize_text_field( $request['target_language'] );

		try {
			// 承認済みデータとして保存
			$pending_data = get_post_meta( $post_id, '_andw_ai_translate_pending', true );
			if ( ! $pending_data ) {
				error_log( 'andW AI Translate - 翻訳承認: 承認待ちデータが見つからない (PostID: ' . $post_id . ')' );
				wp_send_json_error( __( '承認する翻訳データが見つかりません', 'andw-ai-translate' ) );
			}

			// 言語別ページの生成
			$translated_page_id = null;
			if ( class_exists( 'ANDW_AI_Translate_Page_Generator' ) ) {
				$page_generator = new ANDW_AI_Translate_Page_Generator();
				$result = $page_generator->create_translated_page( $post_id, $target_language, $pending_data );

				if ( is_wp_error( $result ) ) {
					error_log( 'andW AI Translate - ページ生成エラー: ' . $result->get_error_message() );
					wp_send_json_error( __( 'ページ生成に失敗しました: ', 'andw-ai-translate' ) . $result->get_error_message() );
				}
				$translated_page_id = $result;

				// ページ生成結果をログに記録（デバッグ時のみ）
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'andW AI Translate - ページ生成成功: 投稿ID ' . $translated_page_id );
				}
			} else {
				error_log( 'andW AI Translate - 警告: ページジェネレータークラスが見つかりません' );
			}

			// 承認済みデータとして保存
			$approval_result = update_post_meta( $post_id, '_andw_ai_translate_approved_' . $target_language, $pending_data );
			if ( ! $approval_result ) {
				error_log( 'andW AI Translate - 承認データの保存に失敗' );
				wp_send_json_error( '承認データの保存に失敗しました' );
			}

			$deletion_result = delete_post_meta( $post_id, '_andw_ai_translate_pending' );
			if ( ! $deletion_result ) {
				error_log( 'andW AI Translate - 承認待ちデータの削除に失敗' );
				// エラーにはしないが警告ログを出力
			}

			// 成功ログ
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'andW AI Translate - 翻訳承認処理成功 (PostID: ' . $post_id . ', Language: ' . $target_language . ')' );
			}

			wp_send_json_success( array(
				'message' => __( '翻訳を承認しました', 'andw-ai-translate' ),
				'translated_page_id' => $translated_page_id,
			) );

		} catch ( Exception $e ) {
			error_log( 'andW AI Translate - 翻訳承認例外エラー: ' . $e->getMessage() );
			wp_send_json_error( '翻訳承認システムエラー: ' . $e->getMessage() );
		}
	}

	/**
	 * 原文表示用のコンテンツ処理（画像をテキスト情報に置換）
	 *
	 * @param string $content 元のコンテンツ
	 * @return string 処理済みコンテンツ
	 */
	private function process_content_for_original_display( $content ) {
		// Gutenbergブロックの解析
		if ( function_exists( 'parse_blocks' ) ) {
			$blocks = parse_blocks( $content );
			return $this->process_blocks_for_original_display( $blocks );
		}

		// 従来エディタの場合のフォールバック
		return $this->process_classic_content_for_original_display( $content );
	}

	/**
	 * ブロック形式のコンテンツ処理
	 *
	 * @param array $blocks ブロックの配列
	 * @return string 処理済みコンテンツ
	 */
	private function process_blocks_for_original_display( $blocks ) {
		$processed_content = '';

		foreach ( $blocks as $block ) {
			if ( empty( $block['blockName'] ) ) {
				// 通常のテキストブロック
				$processed_content .= $block['innerHTML'] ?? '';
				continue;
			}

			switch ( $block['blockName'] ) {
				case 'core/image':
					$processed_content .= $this->process_image_block( $block );
					break;

				case 'core/gallery':
					$processed_content .= $this->process_gallery_block( $block );
					break;

				case 'core/cover':
					$processed_content .= $this->process_cover_block( $block );
					break;

				case 'core/media-text':
					$processed_content .= $this->process_media_text_block( $block );
					break;

				default:
					// その他のブロックは通常通り表示
					$processed_content .= render_block( $block );
					break;
			}
		}

		return $processed_content;
	}

	/**
	 * 画像ブロックの処理
	 *
	 * @param array $block 画像ブロック
	 * @return string 処理済みHTML
	 */
	private function process_image_block( $block ) {
		$attributes = $block['attrs'] ?? array();
		$image_info = array();

		// 画像ID
		if ( isset( $attributes['id'] ) ) {
			$attachment_id = (int) $attributes['id'];

			// ALT属性
			$alt_text = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
			if ( ! empty( $alt_text ) ) {
				$image_info[] = 'ALT: ' . esc_html( $alt_text );
			}

			// キャプション
			$attachment = get_post( $attachment_id );
			if ( $attachment && ! empty( $attachment->post_excerpt ) ) {
				$image_info[] = 'キャプション: ' . esc_html( $attachment->post_excerpt );
			}

			// ファイル名
			$filename = basename( get_attached_file( $attachment_id ) );
			if ( $filename ) {
				$image_info[] = 'ファイル名: ' . esc_html( $filename );
			}
		}

		// ブロックレベルのキャプション
		$block_caption = '';
		if ( isset( $attributes['caption'] ) && ! empty( $attributes['caption'] ) ) {
			$block_caption = wp_strip_all_tags( $attributes['caption'] );
			$image_info[] = 'キャプション: ' . esc_html( $block_caption );
		}

		// innerHTML からfigcaptionを抽出
		$figcaption_content = '';
		if ( isset( $block['innerHTML'] ) && preg_match('/<figcaption[^>]*>(.*?)<\/figcaption>/s', $block['innerHTML'], $caption_matches ) ) {
			$figcaption_content = wp_strip_all_tags( $caption_matches[1] );
		}

		// 情報がない場合のフォールバック
		if ( empty( $image_info ) ) {
			$image_info[] = '[画像]';
		}

		$result = '<div class="andw-image-placeholder">' .
				  '<span class="andw-image-icon">🖼️</span>' .
				  '<span class="andw-image-info">' . implode( ' | ', $image_info ) . '</span>' .
				  '</div>';

		// figcaption があれば追加（ブロック属性のキャプションと異なる場合）
		if ( ! empty( $figcaption_content ) && $figcaption_content !== $block_caption ) {
			$result .= '<figcaption class="andw-preserved-figcaption">' . esc_html( $figcaption_content ) . '</figcaption>';
		} elseif ( ! empty( $block_caption ) ) {
			$result .= '<figcaption class="andw-preserved-figcaption">' . esc_html( $block_caption ) . '</figcaption>';
		}

		return $result;
	}

	/**
	 * ギャラリーブロックの処理
	 *
	 * @param array $block ギャラリーブロック
	 * @return string 処理済みHTML
	 */
	private function process_gallery_block( $block ) {
		$attributes = $block['attrs'] ?? array();
		$image_count = 0;
		$gallery_info = array();

		if ( isset( $attributes['ids'] ) && is_array( $attributes['ids'] ) ) {
			$image_count = count( $attributes['ids'] );

			foreach ( $attributes['ids'] as $attachment_id ) {
				$alt_text = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
				if ( ! empty( $alt_text ) ) {
					$gallery_info[] = esc_html( $alt_text );
				}
			}
		}

		$info_text = '[ギャラリー: ' . $image_count . '枚の画像]';
		if ( ! empty( $gallery_info ) ) {
			$info_text .= ' - ' . implode( ', ', $gallery_info );
		}

		return '<div class="andw-gallery-placeholder">' .
			   '<span class="andw-gallery-icon">🖼️📁</span>' .
			   '<span class="andw-gallery-info">' . $info_text . '</span>' .
			   '</div>';
	}

	/**
	 * カバーブロックの処理
	 *
	 * @param array $block カバーブロック
	 * @return string 処理済みHTML
	 */
	private function process_cover_block( $block ) {
		$attributes = $block['attrs'] ?? array();
		$inner_html = $block['innerHTML'] ?? '';

		// テキスト部分を抽出
		$text_content = wp_strip_all_tags( $inner_html );

		$info_text = '[カバー画像]';
		if ( ! empty( $text_content ) ) {
			$info_text .= ' - テキスト: ' . esc_html( trim( $text_content ) );
		}

		return '<div class="andw-cover-placeholder">' .
			   '<span class="andw-cover-icon">🖼️📄</span>' .
			   '<span class="andw-cover-info">' . $info_text . '</span>' .
			   '</div>';
	}

	/**
	 * メディア・テキストブロックの処理
	 *
	 * @param array $block メディア・テキストブロック
	 * @return string 処理済みHTML
	 */
	private function process_media_text_block( $block ) {
		$inner_html = $block['innerHTML'] ?? '';

		// メディア部分を画像プレースホルダーに置換
		$processed_html = preg_replace(
			'/<figure[^>]*class="[^"]*wp-block-media-text__media[^"]*"[^>]*>.*?<\/figure>/s',
			'<div class="andw-media-placeholder"><span class="andw-media-icon">🖼️</span><span class="andw-media-info">[メディア]</span></div>',
			$inner_html
		);

		return $processed_html;
	}

	/**
	 * 従来エディタ用のコンテンツ処理
	 *
	 * @param string $content 元のコンテンツ
	 * @return string 処理済みコンテンツ
	 */
	private function process_classic_content_for_original_display( $content ) {
		// figure要素（画像 + figcaption）を処理
		$content = preg_replace_callback(
			'/<figure[^>]*>(.*?)<\/figure>/s',
			array( $this, 'replace_figure_with_info' ),
			$content
		);

		// 残りの img タグを画像情報に置換
		$content = preg_replace_callback(
			'/<img[^>]*>/i',
			array( $this, 'replace_img_tag_with_info' ),
			$content
		);

		return $content;
	}

	/**
	 * figure要素を画像情報に置換するコールバック
	 *
	 * @param array $matches マッチした内容
	 * @return string 置換後の文字列
	 */
	private function replace_figure_with_info( $matches ) {
		$figure_content = $matches[1];
		$image_info = array();
		$figcaption_content = '';

		// figcaption を抽出・保持
		if ( preg_match('/<figcaption[^>]*>(.*?)<\/figcaption>/s', $figure_content, $caption_matches ) ) {
			$figcaption_content = wp_strip_all_tags( $caption_matches[1] );
		}

		// img タグから情報を抽出
		if ( preg_match('/<img[^>]*>/i', $figure_content, $img_matches ) ) {
			$img_tag = $img_matches[0];

			// alt属性を抽出
			if ( preg_match('/alt=["\']([^"\']*)["\']/', $img_tag, $alt_matches ) ) {
				$image_info[] = 'ALT: ' . esc_html( $alt_matches[1] );
			}

			// title属性を抽出
			if ( preg_match('/title=["\']([^"\']*)["\']/', $img_tag, $title_matches ) ) {
				$image_info[] = 'タイトル: ' . esc_html( $title_matches[1] );
			}

			// src属性からファイル名を抽出
			if ( preg_match('/src=["\']([^"\']*)["\']/', $img_tag, $src_matches ) ) {
				$filename = basename( $src_matches[1] );
				$image_info[] = 'ファイル名: ' . esc_html( $filename );
			}
		}

		if ( empty( $image_info ) ) {
			$image_info[] = '[画像]';
		}

		$result = '<div class="andw-image-placeholder">' .
				  '<span class="andw-image-icon">🖼️</span>' .
				  '<span class="andw-image-info">' . implode( ' | ', $image_info ) . '</span>' .
				  '</div>';

		// figcaption があれば追加
		if ( ! empty( $figcaption_content ) ) {
			$result .= '<figcaption class="andw-preserved-figcaption">' . esc_html( $figcaption_content ) . '</figcaption>';
		}

		return $result;
	}

	/**
	 * imgタグを画像情報に置換するコールバック
	 *
	 * @param array $matches マッチした内容
	 * @return string 置換後の文字列
	 */
	private function replace_img_tag_with_info( $matches ) {
		$img_tag = $matches[0];
		$image_info = array();

		// alt属性を抽出
		if ( preg_match('/alt=["\']([^"\']*)["\']/', $img_tag, $alt_matches ) ) {
			$image_info[] = 'ALT: ' . esc_html( $alt_matches[1] );
		}

		// title属性を抽出
		if ( preg_match('/title=["\']([^"\']*)["\']/', $img_tag, $title_matches ) ) {
			$image_info[] = 'タイトル: ' . esc_html( $title_matches[1] );
		}

		// src属性からファイル名を抽出
		if ( preg_match('/src=["\']([^"\']*)["\']/', $img_tag, $src_matches ) ) {
			$filename = basename( $src_matches[1] );
			$image_info[] = 'ファイル名: ' . esc_html( $filename );
		}

		if ( empty( $image_info ) ) {
			$image_info[] = '[画像]';
		}

		return '<div class="andw-image-placeholder">' .
			   '<span class="andw-image-icon">🖼️</span>' .
			   '<span class="andw-image-info">' . implode( ' | ', $image_info ) . '</span>' .
			   '</div>';
	}

}