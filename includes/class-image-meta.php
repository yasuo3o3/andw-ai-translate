<?php
/**
 * 画像言語別メタデータ管理クラス
 */

// 直接アクセスを防ぐ
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 画像のalt/titleテキストを言語別に管理するクラス
 */
class ANDW_AI_Translate_Image_Meta {

	/**
	 * 翻訳エンジンインスタンス
	 */
	private $translation_engine;

	/**
	 * コンストラクタ
	 */
	public function __construct() {
		$this->translation_engine = new ANDW_AI_Translate_Translation_Engine();

		// フック設定
		add_action( 'add_meta_boxes_attachment', array( $this, 'add_image_meta_box' ) );
		add_action( 'edit_attachment', array( $this, 'save_image_meta' ) );
		add_filter( 'wp_get_attachment_image_attributes', array( $this, 'filter_image_attributes' ), 10, 3 );
		add_filter( 'wp_calculate_image_srcset', array( $this, 'filter_image_srcset' ), 10, 5 );
		add_action( 'wp_ajax_andw_ai_translate_image_meta', array( $this, 'ajax_translate_image_meta' ) );
	}

	/**
	 * 画像編集画面にメタボックスを追加
	 */
	public function add_image_meta_box() {
		add_meta_box(
			'andw-ai-translate-image-meta',
			__( 'AI翻訳 - 画像メタデータ', 'andw-ai-translate' ),
			array( $this, 'render_image_meta_box' ),
			'attachment',
			'normal',
			'default'
		);
	}

	/**
	 * 画像メタボックスの表示
	 */
	public function render_image_meta_box( $post ) {
		// 画像ファイル以外は表示しない
		if ( ! wp_attachment_is_image( $post->ID ) ) {
			echo '<p>' . esc_html__( 'この機能は画像ファイルのみに対応しています', 'andw-ai-translate' ) . '</p>';
			return;
		}

		wp_nonce_field( 'andw_ai_translate_image_meta', 'andw_ai_translate_image_nonce' );

		// 既存のalt/titleテキスト
		$alt_text = get_post_meta( $post->ID, '_wp_attachment_image_alt', true );
		$attachment = get_post( $post->ID );
		$title_text = $attachment->post_excerpt;

		// 言語別メタデータの取得
		$language_meta = get_post_meta( $post->ID, '_andw_ai_translate_image_meta', true );
		if ( ! is_array( $language_meta ) ) {
			$language_meta = array();
		}

		// 対応言語一覧
		$languages = array(
			'en' => __( '英語', 'andw-ai-translate' ),
			'zh' => __( '中国語（簡体字）', 'andw-ai-translate' ),
			'zh-TW' => __( '中国語（繁体字）', 'andw-ai-translate' ),
			'ko' => __( '韓国語', 'andw-ai-translate' ),
			'fr' => __( 'フランス語', 'andw-ai-translate' ),
			'de' => __( 'ドイツ語', 'andw-ai-translate' ),
			'es' => __( 'スペイン語', 'andw-ai-translate' ),
		);

		?>
		<div class="andw-image-meta-container">
			<!-- 現在の日本語メタデータ -->
			<div class="andw-original-meta">
				<h4><?php esc_html_e( '日本語（元データ）', 'andw-ai-translate' ); ?></h4>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Altテキスト', 'andw-ai-translate' ); ?></th>
						<td>
							<input type="text" value="<?php echo esc_attr( $alt_text ); ?>" class="widefat" readonly />
							<p class="description"><?php esc_html_e( '日本語のaltテキストです（読み取り専用）', 'andw-ai-translate' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'キャプション', 'andw-ai-translate' ); ?></th>
						<td>
							<input type="text" value="<?php echo esc_attr( $title_text ); ?>" class="widefat" readonly />
							<p class="description"><?php esc_html_e( '日本語のキャプションです（読み取り専用）', 'andw-ai-translate' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<!-- 翻訳操作 -->
			<div class="andw-translate-controls">
				<h4><?php esc_html_e( '自動翻訳', 'andw-ai-translate' ); ?></h4>
				<p>
					<select id="andw-image-target-language">
						<option value=""><?php esc_html_e( '翻訳する言語を選択', 'andw-ai-translate' ); ?></option>
						<?php foreach ( $languages as $code => $name ) : ?>
							<option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $name ); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="button" id="andw-translate-image-meta" class="button button-secondary">
						<?php esc_html_e( '翻訳実行', 'andw-ai-translate' ); ?>
					</button>
				</p>
				<div id="andw-image-translation-progress" style="display: none;">
					<p><?php esc_html_e( '翻訳中...', 'andw-ai-translate' ); ?></p>
				</div>
			</div>

			<!-- 言語別メタデータ -->
			<div class="andw-language-meta">
				<h4><?php esc_html_e( '言語別メタデータ', 'andw-ai-translate' ); ?></h4>

				<?php foreach ( $languages as $lang_code => $lang_name ) : ?>
					<div class="andw-language-section" data-language="<?php echo esc_attr( $lang_code ); ?>">
						<h5><?php echo esc_html( $lang_name ); ?> (<?php echo esc_html( $lang_code ); ?>)</h5>
						<table class="form-table">
							<tr>
								<th scope="row"><?php esc_html_e( 'Altテキスト', 'andw-ai-translate' ); ?></th>
								<td>
									<input type="text"
										   name="andw_image_meta[<?php echo esc_attr( $lang_code ); ?>][alt]"
										   value="<?php echo esc_attr( isset( $language_meta[ $lang_code ]['alt'] ) ? $language_meta[ $lang_code ]['alt'] : '' ); ?>"
										   class="widefat andw-alt-input" />
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'キャプション', 'andw-ai-translate' ); ?></th>
								<td>
									<input type="text"
										   name="andw_image_meta[<?php echo esc_attr( $lang_code ); ?>][caption]"
										   value="<?php echo esc_attr( isset( $language_meta[ $lang_code ]['caption'] ) ? $language_meta[ $lang_code ]['caption'] : '' ); ?>"
										   class="widefat andw-caption-input" />
								</td>
							</tr>
						</table>
					</div>
				<?php endforeach; ?>
			</div>
		</div>

		<script type="text/javascript">
		jQuery(document).ready(function($) {
			$('#andw-translate-image-meta').on('click', function() {
				var targetLang = $('#andw-image-target-language').val();
				if (!targetLang) {
					alert('<?php esc_js_e( '翻訳する言語を選択してください', 'andw-ai-translate' ); ?>');
					return;
				}

				$('#andw-image-translation-progress').show();
				$(this).prop('disabled', true);

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'andw_ai_translate_image_meta',
						nonce: '<?php echo esc_js( wp_create_nonce( 'andw_ai_translate_image_meta' ) ); ?>',
						attachment_id: <?php echo esc_js( $post->ID ); ?>,
						target_language: targetLang,
						alt_text: '<?php echo esc_js( $alt_text ); ?>',
						caption_text: '<?php echo esc_js( $title_text ); ?>'
					},
					success: function(response) {
						if (response.success) {
							var langSection = $('.andw-language-section[data-language="' + targetLang + '"]');
							langSection.find('.andw-alt-input').val(response.data.alt);
							langSection.find('.andw-caption-input').val(response.data.caption);
							alert('<?php esc_js_e( '翻訳が完了しました', 'andw-ai-translate' ); ?>');
						} else {
							alert('<?php esc_js_e( 'エラー:', 'andw-ai-translate' ); ?> ' + (response.data || '<?php esc_js_e( '不明なエラー', 'andw-ai-translate' ); ?>'));
						}
					},
					error: function() {
						alert('<?php esc_js_e( '通信エラーが発生しました', 'andw-ai-translate' ); ?>');
					},
					complete: function() {
						$('#andw-image-translation-progress').hide();
						$('#andw-translate-image-meta').prop('disabled', false);
					}
				});
			});
		});
		</script>

		<style>
		.andw-image-meta-container {
			max-width: 100%;
		}

		.andw-original-meta,
		.andw-language-meta {
			margin-bottom: 20px;
			padding: 15px;
			border: 1px solid #ddd;
			border-radius: 4px;
			background: #f9f9f9;
		}

		.andw-original-meta h4,
		.andw-language-meta h4 {
			margin-top: 0;
			margin-bottom: 15px;
			color: #0073aa;
			border-bottom: 2px solid #0073aa;
			padding-bottom: 5px;
		}

		.andw-language-section {
			margin-bottom: 20px;
			padding: 10px;
			border: 1px solid #ccc;
			border-radius: 3px;
			background: white;
		}

		.andw-language-section h5 {
			margin-top: 0;
			margin-bottom: 10px;
			color: #333;
			font-size: 14px;
		}

		.andw-translate-controls {
			margin-bottom: 20px;
			padding: 15px;
			border: 1px solid #ddd;
			border-radius: 4px;
			background: white;
		}

		.andw-translate-controls h4 {
			margin-top: 0;
			margin-bottom: 10px;
			color: #0073aa;
		}

		#andw-image-target-language {
			margin-right: 10px;
			min-width: 200px;
		}

		#andw-image-translation-progress {
			margin-top: 10px;
			padding: 10px;
			background: #fff3cd;
			border: 1px solid #ffeaa7;
			border-radius: 3px;
		}
		</style>
		<?php
	}

	/**
	 * 画像メタデータの保存
	 */
	public function save_image_meta( $attachment_id ) {
		// nonce チェック
		if ( ! isset( $_POST['andw_ai_translate_image_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['andw_ai_translate_image_nonce'] ) ), 'andw_ai_translate_image_meta' ) ) {
			return;
		}

		// 権限チェック
		if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
			return;
		}

		// 言語別メタデータの保存
		// —— 言語別メタデータの保存（安全版）——
		if ( isset( $_POST['andw_image_meta'] ) && is_array( $_POST['andw_image_meta'] ) ) {
			// 配列全体を再帰サニタイズ
			$image_meta = map_deep( wp_unslash( $_POST['andw_image_meta'] ), 'sanitize_text_field' );
			$language_meta = array();

			foreach ( $image_meta as $lang_code_raw => $meta_data ) {
				$lang_code = sanitize_key( $lang_code_raw );

				if ( is_array( $meta_data ) ) {
					$alt     = isset( $meta_data['alt'] ) ? sanitize_text_field( $meta_data['alt'] ) : '';
					$caption = isset( $meta_data['caption'] ) ? sanitize_textarea_field( $meta_data['caption'] ) : '';

					if ( '' !== $alt || '' !== $caption ) {
						$language_meta[ $lang_code ] = array(
							'alt'     => $alt,
							'caption' => $caption,
						);
					}
				}
			}

			update_post_meta( $attachment_id, '_andw_ai_translate_image_meta', $language_meta );
		}

	}

	/**
	 * AJAX: 画像メタデータの翻訳
	 */
	public function ajax_translate_image_meta() {
		// 必要なPOSTデータの検証
		if ( ! isset( $_POST['nonce'], $_POST['attachment_id'], $_POST['target_language'], $_POST['alt_text'], $_POST['caption_text'] ) ) {
			wp_send_json_error( __( '無効なリクエストです', 'andw-ai-translate' ) );
		}

		$request = wp_unslash( $_POST );

		// nonce と権限チェック
		if ( ! wp_verify_nonce( sanitize_text_field( $request['nonce'] ), 'andw_ai_translate_image_meta' ) ||
			! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( '権限がありません', 'andw-ai-translate' ) );
		}

		$attachment_id = absint( $request['attachment_id'] );
		$target_language = sanitize_text_field( $request['target_language'] );
		$alt_text = sanitize_text_field( $request['alt_text'] );
		$caption_text = sanitize_text_field( $request['caption_text'] );

		$translated_data = array();

		// altテキストの翻訳
		if ( ! empty( $alt_text ) ) {
			$alt_result = $this->translation_engine->translate( $alt_text, $target_language );
			if ( is_wp_error( $alt_result ) ) {
				wp_send_json_error( $alt_result->get_error_message() );
			}
			$translated_data['alt'] = $alt_result['translated_text'];
		} else {
			$translated_data['alt'] = '';
		}

		// キャプションの翻訳
		if ( ! empty( $caption_text ) ) {
			$caption_result = $this->translation_engine->translate( $caption_text, $target_language );
			if ( is_wp_error( $caption_result ) ) {
				wp_send_json_error( $caption_result->get_error_message() );
			}
			$translated_data['caption'] = $caption_result['translated_text'];
		} else {
			$translated_data['caption'] = '';
		}

		wp_send_json_success( $translated_data );
	}

	/**
	 * 画像属性のフィルタリング（出力時に言語別メタを適用）
	 */
	/**
	 * @param array        $attr       画像属性配列.
	 * @param WP_Post      $attachment 添付ファイルオブジェクト.
	 * @param string|array $size       画像サイズ.
	 * @return array
	 */
	public function filter_image_attributes( $attr, $attachment, $size ) {
		// 現在の言語を判定
		$current_language = $this->get_current_language();

		// 日本語の場合はそのまま返す
		if ( $current_language === 'ja' ) {
			return $attr;
		}

		// 言語別メタデータの取得
		$language_meta = get_post_meta( $attachment->ID, '_andw_ai_translate_image_meta', true );

		if ( is_array( $language_meta ) && isset( $language_meta[ $current_language ] ) ) {
			$meta = $language_meta[ $current_language ];

			// altテキストの差し替え
			if ( ! empty( $meta['alt'] ) ) {
				$attr['alt'] = $meta['alt'];
			}

			// titleテキストの差し替え（キャプション）
			if ( ! empty( $meta['caption'] ) ) {
				$attr['title'] = $meta['caption'];
			}
		}

		return $attr;
	}

	/**
	 * 現在の言語を判定
	 */
	private function get_current_language() {
		// 言語別ページかどうかをチェック
		global $post;

		if ( $post ) {
			$language = get_post_meta( $post->ID, '_andw_ai_translate_language', true );
			if ( ! empty( $language ) ) {
				return $language;
			}
		}

		// URLパラメータからの判定（公開ページの言語切替のためnonce不要）
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 公開ページの言語判定でnonceは不要
		if ( isset( $_GET['lang'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 公開ページの言語判定でnonceは不要
			return sanitize_text_field( wp_unslash( $_GET['lang'] ) );
		}

		// デフォルトは日本語
		return 'ja';
	}

	/**
	 * 画像のsrcsetフィルタ（必要に応じて）
	 */
	public function filter_image_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
		// 必要に応じて実装
		return $sources;
	}

	/**
	 * 言語別メタデータの取得（APIなど外部からの利用）
	 */
	public function get_language_meta( $attachment_id, $language = null ) {
		$language_meta = get_post_meta( $attachment_id, '_andw_ai_translate_image_meta', true );

		if ( ! is_array( $language_meta ) ) {
			return array();
		}

		if ( $language ) {
			return isset( $language_meta[ $language ] ) ? $language_meta[ $language ] : array();
		}

		return $language_meta;
	}

	/**
	 * 言語別メタデータの設定
	 */
	public function set_language_meta( $attachment_id, $language, $alt_text, $caption_text ) {
		$language_meta = get_post_meta( $attachment_id, '_andw_ai_translate_image_meta', true );

		if ( ! is_array( $language_meta ) ) {
			$language_meta = array();
		}

		$language_meta[ $language ] = array(
			'alt' => sanitize_text_field( $alt_text ),
			'caption' => sanitize_text_field( $caption_text ),
		);

		update_post_meta( $attachment_id, '_andw_ai_translate_image_meta', $language_meta );
	}

	/**
	 * 画像メタデータのフィルタ処理
	 */
	public function filter_metadata( $metadata, $attachment_id ) {
		// 現在の言語を判定
		$current_language = $this->get_current_language();

		// 日本語の場合はそのまま返す
		if ( $current_language === 'ja' ) {
			return $metadata;
		}

		// 言語別画像メタデータの処理
		// 現在は基本的な実装として、オリジナルメタデータをそのまま返す
		// 将来的に画像メタデータの言語別処理が必要な場合はここで実装
		return $metadata;
	}

	/**
	 * 対応言語の一覧取得
	 */
	public function get_supported_languages() {
		return array(
			'en' => __( '英語', 'andw-ai-translate' ),
			'zh' => __( '中国語（簡体字）', 'andw-ai-translate' ),
			'zh-TW' => __( '中国語（繁体字）', 'andw-ai-translate' ),
			'ko' => __( '韓国語', 'andw-ai-translate' ),
			'fr' => __( 'フランス語', 'andw-ai-translate' ),
			'de' => __( 'ドイツ語', 'andw-ai-translate' ),
			'es' => __( 'スペイン語', 'andw-ai-translate' ),
		);
	}
}