/**
 * andW AI Translate - メタボックスJavaScript
 */

(function($) {
    'use strict';

    var andwTranslateMeta = {
        init: function() {
            this.bindEvents();
            this.checkAvailability();
        },

        bindEvents: function() {
            // ページ全体翻訳
            $('#andw-translate-post').on('click', this.translatePost.bind(this));

            // A/B比較
            $('#andw-ab-compare').on('click', this.runAbCompare.bind(this));

            // ブロック単位翻訳
            $('#andw-translate-selected-block').on('click', this.translateSelectedBlock.bind(this));

            // 承認・却下
            $('#andw-approve-translation').on('click', this.approveTranslation.bind(this));
            $('#andw-reject-translation').on('click', this.rejectTranslation.bind(this));

            // A/B比較での選択
            $(document).on('click', '.andw-select-translation', this.selectAbTranslation.bind(this));
        },

        checkAvailability: function() {
            // プロバイダが利用可能かチェック
            var providerCount = $('#andw-provider option').length;

            if (providerCount === 0) {
                this.showError(andwTranslate.strings.noProvider);
                $('#andw-translate-post, #andw-ab-compare').prop('disabled', true);
            }

            // A/B比較ボタンの表示制御
            if (providerCount < 2) {
                $('#andw-ab-compare').prop('disabled', true).attr('title', '2つ以上のプロバイダが必要です');
            }
        },

        translatePost: function() {
            if (!this.validateInputs()) {
                return;
            }

            var targetLanguage = $('#andw-target-language').val();
            var provider = $('#andw-provider').val();

            // デバッグログ: 翻訳開始
            console.log('andW AI Translate - 翻訳開始:', {
                target_language: targetLanguage,
                provider: provider,
                post_id: andwTranslate.postId
            });

            this.showProgress(andwTranslate.strings.translating, 0);

            $.ajax({
                url: andwTranslate.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'andw_ai_translate_post',
                    nonce: andwTranslate.nonce,
                    post_id: andwTranslate.postId,
                    target_language: targetLanguage,
                    provider: provider
                },
                success: function(response) {
                    // デバッグログ: AJAX応答
                    console.log('andW AI Translate - AJAX応答:', response);

                    if (response.success) {
                        console.log('andW AI Translate - 翻訳成功:', response.data);
                        andwTranslateMeta.displayTranslationResults(response.data);
                        andwTranslateMeta.hideProgress();
                    } else {
                        console.error('andW AI Translate - 翻訳エラー:', response.data);
                        andwTranslateMeta.showError(response.data || andwTranslate.strings.error);
                        andwTranslateMeta.hideProgress();
                    }
                },
                error: function(xhr, status, error) {
                    // デバッグログ: AJAX接続エラー
                    console.error('andW AI Translate - AJAX接続エラー:', {
                        status: status,
                        error: error,
                        response: xhr.responseText
                    });
                    andwTranslateMeta.showError(andwTranslate.strings.error);
                    andwTranslateMeta.hideProgress();
                }
            });
        },

        runAbCompare: function() {
            if (!this.validateInputs()) {
                return;
            }

            var targetLanguage = $('#andw-target-language').val();

            this.showProgress('A/B比較実行中...', 0);

            $.ajax({
                url: andwTranslate.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'andw_ai_translate_ab_compare',
                    nonce: andwTranslate.nonce,
                    post_id: andwTranslate.postId,
                    target_language: targetLanguage
                },
                success: function(response) {
                    if (response.success) {
                        andwTranslateMeta.displayAbResults(response.data);
                        andwTranslateMeta.hideProgress();
                    } else {
                        andwTranslateMeta.showError(response.data || andwTranslate.strings.error);
                        andwTranslateMeta.hideProgress();
                    }
                },
                error: function() {
                    andwTranslateMeta.showError(andwTranslate.strings.error);
                    andwTranslateMeta.hideProgress();
                }
            });
        },

        translateSelectedBlock: function() {
            // Gutenbergエディタからブロックデータを取得
            var selectedBlock = this.getSelectedBlock();
            if (!selectedBlock) {
                this.showError('ブロックが選択されていません');
                return;
            }

            if (!this.validateInputs()) {
                return;
            }

            var targetLanguage = $('#andw-target-language').val();
            var provider = $('#andw-provider').val();

            this.showProgress('ブロック翻訳中...', 0);

            $.ajax({
                url: andwTranslate.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'andw_ai_translate_block',
                    nonce: andwTranslate.nonce,
                    block_data: JSON.stringify(selectedBlock),
                    target_language: targetLanguage,
                    provider: provider
                },
                success: function(response) {
                    if (response.success) {
                        andwTranslateMeta.updateBlockInEditor(response.data.translated_block);
                        andwTranslateMeta.hideProgress();
                        andwTranslateMeta.showSuccess('ブロックが翻訳されました');
                    } else {
                        andwTranslateMeta.showError(response.data || andwTranslate.strings.error);
                        andwTranslateMeta.hideProgress();
                    }
                },
                error: function() {
                    andwTranslateMeta.showError(andwTranslate.strings.error);
                    andwTranslateMeta.hideProgress();
                }
            });
        },

        approveTranslation: function() {
            if (!confirm(andwTranslate.strings.confirmApprove)) {
                return;
            }

            var targetLanguage = $('#andw-target-language').val();

            $.ajax({
                url: andwTranslate.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'andw_ai_translate_approve',
                    nonce: andwTranslate.nonce,
                    post_id: andwTranslate.postId,
                    target_language: targetLanguage
                },
                success: function(response) {
                    if (response.success) {
                        andwTranslateMeta.showSuccess(response.data.message);
                        $('#andw-translation-results').hide();
                    } else {
                        andwTranslateMeta.showError(response.data || andwTranslate.strings.error);
                    }
                },
                error: function() {
                    andwTranslateMeta.showError(andwTranslate.strings.error);
                }
            });
        },

        rejectTranslation: function() {
            if (!confirm(andwTranslate.strings.confirmReject)) {
                return;
            }

            $('#andw-translation-results').hide();
            this.showSuccess('翻訳を却下しました');
        },

        selectAbTranslation: function(e) {
            var provider = $(e.target).data('provider');
            var translationData = provider === 'a' ?
                $('#andw-translation-a').html() :
                $('#andw-translation-b').html();

            // 選択された翻訳を通常の結果表示エリアに移動
            $('#andw-translated-content').html(translationData);
            $('#andw-ab-results').hide();
            $('#andw-translation-results').show();

            this.showSuccess('翻訳を選択しました');
        },

        displayTranslationResults: function(data) {
            $('#andw-translated-content').html(this.formatContent(data.translation.translated_content));
            $('#andw-back-translated-content').html(this.formatContent(data.back_translation.back_translated_text));

            $('#andw-translation-results').show();
            $('#andw-ab-results').hide();
        },

        displayAbResults: function(data) {
            var providers = Object.keys(data);

            if (providers.length >= 2) {
                var providerA = providers[0];
                var providerB = providers[1];

                $('#andw-provider-a-name').text(providerA);
                $('#andw-provider-b-name').text(providerB);

                $('#andw-translation-a').html(this.formatContent(data[providerA].translation.translated_content));
                $('#andw-back-translation-a').html(this.formatContent(data[providerA].back_translation.back_translated_text));

                $('#andw-translation-b').html(this.formatContent(data[providerB].translation.translated_content));
                $('#andw-back-translation-b').html(this.formatContent(data[providerB].back_translation.back_translated_text));
            }

            $('#andw-ab-results').show();
            $('#andw-translation-results').hide();
        },

        formatContent: function(content) {
            // HTMLコンテンツの表示用フォーマット
            return $('<div>').text(content).html().replace(/\n/g, '<br>');
        },

        getSelectedBlock: function() {
            // Gutenbergエディタから選択中のブロックを取得
            // この実装はWordPressのバージョンにより異なる可能性があります
            if (window.wp && window.wp.data) {
                var selectedBlockId = wp.data.select('core/block-editor').getSelectedBlockId();
                if (selectedBlockId) {
                    return wp.data.select('core/block-editor').getBlock(selectedBlockId);
                }
            }
            return null;
        },

        updateBlockInEditor: function(translatedBlock) {
            // Gutenbergエディタのブロックを更新
            if (window.wp && window.wp.data && translatedBlock) {
                var selectedBlockId = wp.data.select('core/block-editor').getSelectedBlockId();
                if (selectedBlockId) {
                    wp.data.dispatch('core/block-editor').updateBlock(selectedBlockId, translatedBlock);
                }
            }
        },

        validateInputs: function() {
            var targetLanguage = $('#andw-target-language').val();
            var provider = $('#andw-provider').val();

            if (!targetLanguage || !provider) {
                this.showError('言語とプロバイダを選択してください');
                return false;
            }

            return true;
        },

        showProgress: function(text, percentage) {
            $('#andw-progress-text').text(text);
            $('.andw-progress-fill').css('width', percentage + '%');
            $('#andw-progress').show();
            this.disableButtons();
        },

        hideProgress: function() {
            $('#andw-progress').hide();
            this.enableButtons();
        },

        disableButtons: function() {
            $('#andw-ai-translate-meta-box button').prop('disabled', true).addClass('andw-loading');
        },

        enableButtons: function() {
            $('#andw-ai-translate-meta-box button').prop('disabled', false).removeClass('andw-loading');
        },

        showError: function(message) {
            this.removeNotices();
            $('#andw-ai-translate-meta-box').prepend(
                '<div class="andw-error">' + message + '</div>'
            );
        },

        showSuccess: function(message) {
            this.removeNotices();
            $('#andw-ai-translate-meta-box').prepend(
                '<div class="andw-success">' + message + '</div>'
            );
        },

        removeNotices: function() {
            $('.andw-error, .andw-success').remove();
        }
    };

    // 初期化
    $(document).ready(function() {
        andwTranslateMeta.init();
    });

})(jQuery);