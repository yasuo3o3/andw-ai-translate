<?php
/**
 * プラグインアンインストール処理
 *
 * このファイルはプラグインが削除される時にのみ実行される。
 * プラグイン無効化時には実行されない。
 */

// WordPressからの直接呼び出しであることを確認
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// 削除対象のオプション（当プラグインが作成したもののみ）
$options_to_delete = array(
	// APIキー関連
	'andw_ai_translate_openai_key',
	'andw_ai_translate_claude_key',

	// 設定関連
	'andw_ai_translate_provider',
	'andw_ai_translate_languages',
	'andw_ai_translate_limit_daily',
	'andw_ai_translate_limit_monthly',
	'andw_ai_translate_expiry_preset',

	// 期限管理関連
	'andw_ai_translate_delivery_date',
	'andw_ai_translate_expiry_date',
	'andw_ai_translate_extension_used',

	// 統計・使用量関連
	'andw_ai_translate_usage_daily',
	'andw_ai_translate_usage_monthly',
	'andw_ai_translate_last_reset_daily',
	'andw_ai_translate_last_reset_monthly',
);

// オプションの削除
foreach ( $options_to_delete as $option ) {
	delete_option( $option );
}

// Transients の削除（andw_ai_translate プレフィックスのみ）
$transient_keys = array_filter(
	get_option( '', array() ) ? array_keys( wp_load_alloptions() ) : array(),
	function( $key ) { return strpos( $key, '_transient_andw_ai_translate_' ) === 0; }
);
foreach ( $transient_keys as $key ) {
	$transient_name = str_replace( '_transient_', '', $key );
	if ( strpos( $transient_name, 'timeout_' ) !== 0 ) {
		delete_transient( $transient_name );
	}
}

// サイト全体のキャッシュから当プラグイン関連のみ削除
$cache_keys_to_delete = array(
	'andw_ai_translate_queue',
	'andw_ai_translate_processing',
	'andw_ai_translate_rate_limit',
);

foreach ( $cache_keys_to_delete as $cache_key ) {
	wp_cache_delete( $cache_key, 'andw_ai_translate' );
}

// User Meta の削除（当プラグインで使用するもの）
$user_meta_to_delete = array(
	'andw_ai_translate_last_notification',
	'andw_ai_translate_dismissed_notices',
);

$users = get_users( array( 'fields' => 'ID' ) );
foreach ( $users as $user_id ) {
	foreach ( $user_meta_to_delete as $meta_key ) {
		delete_user_meta( $user_id, $meta_key );
	}
}

// Post Meta の削除（翻訳関連のメタデータ）
$meta_keys_to_delete = array(
	'_andw_ai_translate_approved_ja', '_andw_ai_translate_approved_en',
	'_andw_ai_translate_pending', '_andw_ai_translate_original_content',
	'_andw_ai_translate_version'
);
foreach ( $meta_keys_to_delete as $meta_key ) {
	delete_post_meta_by_key( $meta_key );
}

// 注意：wp_cache_flush() は使用しない（WORDPRESS.md の指示に従う）
// サイト全体のキャッシュをクリアするような処理は実行しない