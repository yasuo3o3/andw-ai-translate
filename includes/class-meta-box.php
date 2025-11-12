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
		add_action( 'wp_ajax_andw_ai_translate_block', array( $this, 'ajax_translate_block' ) );
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
				'strings' => array(
					'translating' => __( '翻訳中...', 'andw-ai-translate' ),
					'translated' => __( '翻訳完了', 'andw-ai-translate' ),
					'error' => __( 'エラーが発生しました', 'andw-ai-translate' ),
					'confirmApprove' => __( 'この翻訳を承認しますか？', 'andw-ai-translate' ),
					'confirmReject' => __( 'この翻訳を却下しますか？', 'andw-ai-translate' ),
					'noProvider' => __( '利用可能なプロバイダがありません', 'andw-ai-translate' ),
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
						<option value="en"><?php esc_html_e( '英語', 'andw-ai-translate' ); ?></option>
						<option value="zh"><?php esc_html_e( '中国語（簡体字）', 'andw-ai-translate' ); ?></option>
						<option value="zh-TW"><?php esc_html_e( '中国語（繁体字）', 'andw-ai-translate' ); ?></option>
						<option value="ko"><?php esc_html_e( '韓国語', 'andw-ai-translate' ); ?></option>
						<option value="fr"><?php esc_html_e( 'フランス語', 'andw-ai-translate' ); ?></option>
						<option value="de"><?php esc_html_e( 'ドイツ語', 'andw-ai-translate' ); ?></option>
						<option value="es"><?php esc_html_e( 'スペイン語', 'andw-ai-translate' ); ?></option>
					</select>
				</p>

				<p>
					<label for="andw-provider"><?php esc_html_e( 'プロバイダ', 'andw-ai-translate' ); ?></label>
					<select id="andw-provider" name="provider">
						<?php foreach ( $available_providers as $key => $name ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $name ); ?></option>
						<?php endforeach; ?>
					</select>
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

			<!-- ブロック単位翻訳エリア -->
			<div id="andw-block-translation">
				<h4><?php esc_html_e( 'ブロック単位翻訳', 'andw-ai-translate' ); ?></h4>
				<p><?php esc_html_e( 'エディタでブロックを選択してから翻訳ボタンを押してください', 'andw-ai-translate' ); ?></p>

				<button type="button" id="andw-translate-selected-block" class="button button-secondary">
					<?php esc_html_e( '選択ブロックを翻訳', 'andw-ai-translate' ); ?>
				</button>
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
		// nonce と権限チェック
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'andw_ai_translate_meta_box' ) ||
			! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( '権限がありません', 'andw-ai-translate' ) );
		}

		$post_id = (int) $_POST['post_id'];
		$target_language = sanitize_text_field( wp_unslash( $_POST['target_language'] ) );
		$provider = sanitize_text_field( wp_unslash( $_POST['provider'] ) );

		// 翻訳の実行
		$result = $this->block_parser->translate_post_blocks( $post_id, $target_language, $provider );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		// 再翻訳の実行（品質確認用：翻訳結果を元の言語に戻す）
		$back_translation = $this->translation_engine->back_translate( $result['translated_content'], 'ja', $provider );

		if ( is_wp_error( $back_translation ) ) {
			wp_send_json_error( $back_translation->get_error_message() );
		}

		// 結果の保存（承認前の一時データ）
		update_post_meta( $post_id, '_andw_ai_translate_pending', array(
			'translation_result' => $result,
			'back_translation' => $back_translation,
			'timestamp' => current_time( 'timestamp' ),
		) );

		wp_send_json_success( array(
			'translation' => $result,
			'back_translation' => $back_translation,
		) );
	}

	/**
	 * AJAX: ブロック単位翻訳
	 */
	public function ajax_translate_block() {
		// nonce と権限チェック
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'andw_ai_translate_meta_box' ) ||
			! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( '権限がありません', 'andw-ai-translate' ) );
		}

		$block_data = json_decode( stripslashes( $_POST['block_data'] ), true );
		$target_language = sanitize_text_field( wp_unslash( $_POST['target_language'] ) );
		$provider = sanitize_text_field( wp_unslash( $_POST['provider'] ) );

		// ブロック翻訳の実行
		$result = $this->block_parser->translate_block( $block_data, $target_language, $provider );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: A/B比較
	 */
	public function ajax_ab_compare() {
		// nonce と権限チェック
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'andw_ai_translate_meta_box' ) ||
			! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( '権限がありません', 'andw-ai-translate' ) );
		}

		$post_id = (int) $_POST['post_id'];
		$target_language = sanitize_text_field( wp_unslash( $_POST['target_language'] ) );

		// 利用可能なプロバイダの取得
		$providers = $this->translation_engine->get_available_providers();
		$provider_keys = array_keys( $providers );

		if ( count( $provider_keys ) < 2 ) {
			wp_send_json_error( __( 'A/B比較には2つ以上のプロバイダが必要です', 'andw-ai-translate' ) );
		}

		$results = array();

		// 各プロバイダで翻訳実行
		foreach ( array_slice( $provider_keys, 0, 2 ) as $provider ) {
			$translation = $this->block_parser->translate_post_blocks( $post_id, $target_language, $provider );
			if ( is_wp_error( $translation ) ) {
				wp_send_json_error( $translation->get_error_message() );
			}

			$back_translation = $this->translation_engine->back_translate( $translation['translated_content'], 'ja', $provider );

			$results[ $provider ] = array(
				'translation' => $translation,
				'back_translation' => $back_translation,
			);
		}

		wp_send_json_success( $results );
	}

	/**
	 * AJAX: 翻訳承認
	 */
	public function ajax_approve_translation() {
		// nonce と権限チェック
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'andw_ai_translate_meta_box' ) ||
			! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( '権限がありません', 'andw-ai-translate' ) );
		}

		$post_id = (int) $_POST['post_id'];
		$target_language = sanitize_text_field( wp_unslash( $_POST['target_language'] ) );

		// 承認済みデータとして保存
		$pending_data = get_post_meta( $post_id, '_andw_ai_translate_pending', true );
		if ( ! $pending_data ) {
			wp_send_json_error( __( '承認する翻訳データが見つかりません', 'andw-ai-translate' ) );
		}

		// 言語別ページの生成
		if ( class_exists( 'ANDW_AI_Translate_Page_Generator' ) ) {
			$page_generator = new ANDW_AI_Translate_Page_Generator();
			$result = $page_generator->create_translated_page( $post_id, $target_language, $pending_data );

			// ページ生成結果をデバッグログに記録
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				if ( is_wp_error( $result ) ) {
					error_log( 'andW AI Translate - ページ生成失敗: ' . $result->get_error_message() );
				} else {
					error_log( 'andW AI Translate - ページ生成成功: 投稿ID ' . $result );
				}
			}
		}

		// 承認済みデータとして保存
		update_post_meta( $post_id, '_andw_ai_translate_approved_' . $target_language, $pending_data );
		delete_post_meta( $post_id, '_andw_ai_translate_pending' );

		wp_send_json_success( array(
			'message' => __( '翻訳を承認しました', 'andw-ai-translate' ),
		) );
	}
}