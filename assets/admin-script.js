/**
 * andW AI Translate - 管理画面JavaScript
 */

(function($) {
    'use strict';

    var andwAITranslateAdmin = {
        init: function() {
            this.bindEvents();
            this.checkApiStatus();
        },

        bindEvents: function() {
            // 即時停止ボタンの確認
            $('input[name="emergency_stop"]').on('click', this.confirmEmergencyStop);

            // 納品完了ボタンの確認
            $('input[name="mark_delivery_completed"]').on('click', this.confirmDelivery);

            // 期限延長ボタンの確認
            $('input[name="extend_expiry"]').on('click', this.confirmExtend);

            // APIキー入力時の検証
            $('input[name="openai_api_key"], input[name="claude_api_key"]').on('blur', this.validateApiKey);

            // フォーム送信時のローディング表示
            $('form').on('submit', this.showLoading);
        },

        confirmEmergencyStop: function(e) {
            if (!confirm(andwAITranslate.strings.confirmStop)) {
                e.preventDefault();
                return false;
            }
        },

        confirmDelivery: function(e) {
            if (!confirm(andwAITranslate.strings.confirmDelivery)) {
                e.preventDefault();
                return false;
            }
        },

        confirmExtend: function(e) {
            if (!confirm(andwAITranslate.strings.confirmExtend)) {
                e.preventDefault();
                return false;
            }
        },

        validateApiKey: function() {
            var $this = $(this);
            var provider = $this.attr('name').replace('_api_key', '');
            var apiKey = $this.val().trim();

            if (!apiKey) {
                return;
            }

            var isValid = false;

            if (provider === 'openai') {
                isValid = /^sk-(?:proj-)?[A-Za-z0-9_-]{20,}$/.test(apiKey);
            } else if (provider === 'claude') {
                isValid = /^sk-ant-[A-Za-z0-9_-]+$/.test(apiKey);
            }

            if (!isValid) {
                $this.css('border-color', '#dc3232');
                andwAITranslateAdmin.showNotice('APIキーの形式が正しくありません', 'error');
            } else {
                $this.css('border-color', '');
            }
        },

        checkApiStatus: function() {
            // API状態チェック機能は現在未実装のため、コメントアウト
            // 将来的に実装する場合はAJAXハンドラーの追加が必要
            /*
            $.ajax({
                url: andwAITranslate.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'andw_ai_translate_check_api_status',
                    nonce: andwAITranslate.nonce
                },
                success: function(response) {
                    if (response.success) {
                        andwAITranslateAdmin.updateApiStatus(response.data);
                    }
                }
            });
            */
        },

        updateApiStatus: function(status) {
            $.each(status, function(provider, isConnected) {
                var $statusElement = $('.andw-api-key-status[data-provider="' + provider + '"]');
                if (isConnected) {
                    $statusElement.removeClass('disconnected').addClass('connected').text('接続済み');
                } else {
                    $statusElement.removeClass('connected').addClass('disconnected').text('未接続');
                }
            });
        },

        showLoading: function() {
            var $form = $(this);
            var $submitButton = $form.find('input[type="submit"]');

            $submitButton.prop('disabled', true);
            $submitButton.after('<span class="andw-spinner"></span>');
        },

        showNotice: function(message, type) {
            type = type || 'info';

            var $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
            $('.wrap h1').after($notice);

            // 自動削除
            setTimeout(function() {
                $notice.fadeOut();
            }, 5000);
        }
    };

    // 初期化
    $(document).ready(function() {
        andwAITranslateAdmin.init();
    });

})(jQuery);
