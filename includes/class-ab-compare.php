<?php
/**
 * A/B比較モードクラス
 */

// 直接アクセスを防ぐ
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 複数AIプロバイダーの翻訳結果を並列比較する機能を提供するクラス
 */
class ANDW_AI_Translate_AB_Compare {

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

		// AJAX フック
		add_action( 'wp_ajax_andw_ai_translate_ab_compare_detailed', array( $this, 'ajax_ab_compare_detailed' ) );
		add_action( 'wp_ajax_andw_ai_translate_ab_select_result', array( $this, 'ajax_select_ab_result' ) );
	}

	/**
	 * A/B比較の実行
	 *
	 * @param int $post_id 投稿ID
	 * @param string $target_language 対象言語
	 * @return array|WP_Error A/B比較結果またはエラー
	 */
	public function run_ab_comparison( $post_id, $target_language ) {
		// 機能の利用可能性チェック
		if ( ! $this->expiry_manager->is_feature_available() ) {
			return new WP_Error( 'feature_unavailable', __( 'A/B比較機能は現在利用できません', 'andw-ai-translate' ) );
		}

		// 利用可能なプロバイダを取得
		$providers = $this->translation_engine->get_available_providers();
		$provider_keys = array_keys( $providers );

		if ( count( $provider_keys ) < 2 ) {
			return new WP_Error( 'insufficient_providers', __( 'A/B比較には2つ以上のプロバイダが必要です', 'andw-ai-translate' ) );
		}

		// 最大2つのプロバイダで比較
		$comparison_providers = array_slice( $provider_keys, 0, 2 );
		$results = array();

		foreach ( $comparison_providers as $provider ) {
			// 翻訳実行
			$translation_result = $this->block_parser->translate_post_blocks( $post_id, $target_language, $provider );

			if ( is_wp_error( $translation_result ) ) {
				// エラーが発生した場合はそのプロバイダをスキップ
				$results[ $provider ] = array(
					'error' => $translation_result->get_error_message(),
				);
				continue;
			}

			// 再翻訳実行
			$back_translation = $this->translation_engine->back_translate(
				$translation_result['translated_content'],
				'ja',
				$provider
			);

			if ( is_wp_error( $back_translation ) ) {
				$back_translation = array(
					'back_translated_text' => __( '再翻訳でエラーが発生しました', 'andw-ai-translate' ),
				);
			}

			// 品質評価の実行
			$quality_score = $this->calculate_quality_score( $translation_result, $back_translation );

			$results[ $provider ] = array(
				'provider_name' => $providers[ $provider ],
				'translation' => $translation_result,
				'back_translation' => $back_translation,
				'quality_score' => $quality_score,
				'timestamp' => current_time( 'timestamp' ),
			);
		}

		// A/B比較データを一時保存
		$comparison_id = $this->save_ab_comparison( $post_id, $target_language, $results );

		return array(
			'comparison_id' => $comparison_id,
			'target_language' => $target_language,
			'providers' => $comparison_providers,
			'results' => $results,
		);
	}

	/**
	 * 品質スコアの計算
	 *
	 * @param array $translation_result 翻訳結果
	 * @param array $back_translation 再翻訳結果
	 * @return float 品質スコア（0-100）
	 */
	private function calculate_quality_score( $translation_result, $back_translation ) {
		// 基本スコア
		$score = 50.0;

		// 翻訳内容の長さチェック（極端に短い/長い場合は減点）
		$original_length = strlen( $translation_result['original_content'] );
		$translated_length = strlen( $translation_result['translated_content'] );

		if ( $original_length > 0 ) {
			$length_ratio = $translated_length / $original_length;

			// 極端に短い（0.3以下）または長い（3.0以上）場合は減点
			if ( $length_ratio < 0.3 || $length_ratio > 3.0 ) {
				$score -= 20;
			} elseif ( $length_ratio < 0.5 || $length_ratio > 2.0 ) {
				$score -= 10;
			}
		}

		// 再翻訳との類似度チェック（簡易版）
		if ( isset( $back_translation['back_translated_text'] ) ) {
			$similarity = $this->calculate_text_similarity(
				$translation_result['original_content'],
				$back_translation['back_translated_text']
			);

			// 類似度に基づくスコア調整
			$score += ( $similarity - 0.5 ) * 40; // -20 to +20 points
		}

		// ブロック構造の維持チェック
		if ( isset( $translation_result['blocks'] ) ) {
			$structure_preserved = $this->check_block_structure_preservation( $translation_result );
			if ( $structure_preserved ) {
				$score += 10;
			} else {
				$score -= 15;
			}
		}

		// スコアの範囲制限（0-100）
		return max( 0, min( 100, $score ) );
	}

	/**
	 * テキスト類似度の計算（簡易版）
	 *
	 * @param string $text1 テキスト1
	 * @param string $text2 テキスト2
	 * @return float 類似度（0-1）
	 */
	private function calculate_text_similarity( $text1, $text2 ) {
		// 簡易的な文字レベルの類似度計算
		$text1 = mb_strtolower( trim( $text1 ) );
		$text2 = mb_strtolower( trim( $text2 ) );

		if ( empty( $text1 ) || empty( $text2 ) ) {
			return 0;
		}

		// レーベンシュタイン距離を使用した類似度計算
		$max_length = max( mb_strlen( $text1 ), mb_strlen( $text2 ) );
		$distance = levenshtein( $text1, $text2 );

		return max( 0, 1 - ( $distance / $max_length ) );
	}

	/**
	 * ブロック構造保持チェック
	 *
	 * @param array $translation_result 翻訳結果
	 * @return bool 構造が保持されているかどうか
	 */
	private function check_block_structure_preservation( $translation_result ) {
		if ( ! isset( $translation_result['blocks'] ) ) {
			return false;
		}

		// 元のブロック構造と翻訳後のブロック構造を比較
		$original_blocks = parse_blocks( $translation_result['original_content'] );
		$translated_blocks = $translation_result['blocks'];

		// ブロック数の比較
		if ( count( $original_blocks ) !== count( $translated_blocks ) ) {
			return false;
		}

		// ブロックタイプの比較
		for ( $i = 0; $i < count( $original_blocks ); $i++ ) {
			if ( $original_blocks[ $i ]['blockName'] !== $translated_blocks[ $i ]['blockName'] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * A/B比較データの保存
	 *
	 * @param int $post_id 投稿ID
	 * @param string $target_language 対象言語
	 * @param array $results 比較結果
	 * @return string 比較ID
	 */
	private function save_ab_comparison( $post_id, $target_language, $results ) {
		$comparison_id = uniqid( 'ab_' );

		$comparison_data = array(
			'post_id' => $post_id,
			'target_language' => $target_language,
			'results' => $results,
			'created_at' => current_time( 'timestamp' ),
			'status' => 'pending',
		);

		// 一時的にtransientに保存（24時間）
		set_transient( 'andw_ai_translate_ab_' . $comparison_id, $comparison_data, DAY_IN_SECONDS );

		return $comparison_id;
	}

	/**
	 * A/B比較データの取得
	 *
	 * @param string $comparison_id 比較ID
	 * @return array|false 比較データまたはfalse
	 */
	public function get_ab_comparison( $comparison_id ) {
		return get_transient( 'andw_ai_translate_ab_' . $comparison_id );
	}

	/**
	 * A/B比較結果の選択処理
	 *
	 * @param string $comparison_id 比較ID
	 * @param string $selected_provider 選択されたプロバイダ
	 * @return bool|WP_Error 成功またはエラー
	 */
	public function select_ab_result( $comparison_id, $selected_provider ) {
		$comparison_data = $this->get_ab_comparison( $comparison_id );

		if ( ! $comparison_data ) {
			return new WP_Error( 'comparison_not_found', __( 'A/B比較データが見つかりません', 'andw-ai-translate' ) );
		}

		if ( ! isset( $comparison_data['results'][ $selected_provider ] ) ) {
			return new WP_Error( 'provider_not_found', __( '指定されたプロバイダの結果が見つかりません', 'andw-ai-translate' ) );
		}

		$selected_result = $comparison_data['results'][ $selected_provider ];

		// 選択された結果を承認待ちデータとして保存
		update_post_meta(
			$comparison_data['post_id'],
			'_andw_ai_translate_pending',
			array(
				'translation_result' => $selected_result['translation'],
				'back_translation' => $selected_result['back_translation'],
				'provider' => $selected_provider,
				'quality_score' => $selected_result['quality_score'],
				'comparison_id' => $comparison_id,
				'timestamp' => current_time( 'timestamp' ),
			)
		);

		// 比較データのステータスを更新
		$comparison_data['status'] = 'selected';
		$comparison_data['selected_provider'] = $selected_provider;
		set_transient( 'andw_ai_translate_ab_' . $comparison_id, $comparison_data, DAY_IN_SECONDS );

		return true;
	}

	/**
	 * AJAX: 詳細A/B比較
	 */
	public function ajax_ab_compare_detailed() {
		// POSTデータ存在チェック
		if ( ! isset( $_POST['nonce'], $_POST['post_id'], $_POST['target_language'] ) ) {
			wp_die( esc_html__( '必須パラメータが不足しています', 'andw-ai-translate' ) );
		}

		// nonce と権限チェック
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'andw_ai_translate_meta_box' ) ||
			! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( '権限がありません', 'andw-ai-translate' ) );
		}

		$post_id = (int) $_POST['post_id'];
		$target_language = sanitize_text_field( wp_unslash( $_POST['target_language'] ) );

		$result = $this->run_ab_comparison( $post_id, $target_language );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: A/B比較結果の選択
	 */
	public function ajax_select_ab_result() {
		// POSTデータ存在チェック
		if ( ! isset( $_POST['nonce'], $_POST['comparison_id'], $_POST['selected_provider'] ) ) {
			wp_die( esc_html__( '必須パラメータが不足しています', 'andw-ai-translate' ) );
		}

		// nonce と権限チェック
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'andw_ai_translate_meta_box' ) ||
			! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( '権限がありません', 'andw-ai-translate' ) );
		}

		$comparison_id = sanitize_text_field( wp_unslash( $_POST['comparison_id'] ) );
		$selected_provider = sanitize_text_field( wp_unslash( $_POST['selected_provider'] ) );

		$result = $this->select_ab_result( $comparison_id, $selected_provider );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( array(
			'message' => __( 'A/B比較結果を選択しました。承認画面で最終確認してください。', 'andw-ai-translate' ),
		) );
	}

	/**
	 * A/B比較履歴の取得
	 *
	 * @param int $post_id 投稿ID
	 * @param int $limit 取得件数制限
	 * @return array A/B比較履歴
	 */
	public function get_ab_history( $post_id, $limit = 10 ) {
		// 投稿のA/B比較履歴を取得
		$history = get_post_meta( $post_id, '_andw_ai_translate_ab_history', true );

		if ( ! is_array( $history ) ) {
			return array();
		}

		// 作成日時で降順ソート
		usort( $history, function( $a, $b ) {
			return $b['created_at'] - $a['created_at'];
		} );

		return array_slice( $history, 0, $limit );
	}

	/**
	 * A/B比較統計の取得
	 *
	 * @return array 統計データ
	 */
	public function get_ab_statistics() {
		$providers = $this->translation_engine->get_available_providers();
		$stats = array();

		foreach ( $providers as $provider_key => $provider_name ) {
			$stats[ $provider_key ] = array(
				'name' => $provider_name,
				'total_comparisons' => 0,
				'total_selected' => 0,
				'average_quality' => 0,
				'selection_rate' => 0,
			);
		}

		// 実際の統計データ取得（簡易版）
		// 本格実装では専用テーブルまたはオプションで管理

		return $stats;
	}

	/**
	 * A/B比較設定の取得
	 */
	public function get_ab_settings() {
		return array(
			'enable_quality_scoring' => get_option( 'andw_ai_translate_ab_quality_scoring', true ),
			'auto_suggest_best' => get_option( 'andw_ai_translate_ab_auto_suggest', false ),
			'save_comparison_history' => get_option( 'andw_ai_translate_ab_save_history', true ),
			'max_history_items' => get_option( 'andw_ai_translate_ab_max_history', 50 ),
		);
	}

	/**
	 * A/B比較設定の保存
	 */
	public function save_ab_settings( $settings ) {
		$allowed_settings = array(
			'andw_ai_translate_ab_quality_scoring',
			'andw_ai_translate_ab_auto_suggest',
			'andw_ai_translate_ab_save_history',
			'andw_ai_translate_ab_max_history',
		);

		foreach ( $settings as $key => $value ) {
			if ( in_array( $key, $allowed_settings, true ) ) {
				update_option( $key, $value );
			}
		}
	}
}