/**
 * andW AI Translate - ブロックサイドバー統合
 */

(function() {
    'use strict';

    // デバッグログ: スクリプト読み込み
    console.log('andW AI Translate - ブロックサイドバースクリプト読み込み');

    // WordPress APIの存在チェック
    if (!window.wp) {
        console.error('andW AI Translate - WordPress APIが利用できません');
        return;
    }

    if (!window.wp.plugins) {
        console.error('andW AI Translate - wp.pluginsが利用できません');
        return;
    }

    if (!window.wp.editor && !window.wp.editPost) {
        console.error('andW AI Translate - wp.editorまたはwp.editPostが利用できません');
        return;
    }

    console.log('andW AI Translate - WordPress API確認完了');

    const { registerPlugin } = wp.plugins;

    // PluginSidebar のインポートを安全にチェック（wp.editorを優先）
    let PluginSidebar, PluginSidebarMoreMenuItem;

    if (wp.editor && wp.editor.PluginSidebar) {
        PluginSidebar = wp.editor.PluginSidebar;
        PluginSidebarMoreMenuItem = wp.editor.PluginSidebarMoreMenuItem;
        console.log('andW AI Translate - wp.editorからコンポーネントを取得（推奨）');
    } else if (wp.editPost && wp.editPost.PluginSidebar) {
        PluginSidebar = wp.editPost.PluginSidebar;
        PluginSidebarMoreMenuItem = wp.editPost.PluginSidebarMoreMenuItem;
        console.log('andW AI Translate - wp.editPostからコンポーネントを取得（非推奨）');
    } else {
        console.error('andW AI Translate - PluginSidebarコンポーネントが取得できません');
        console.log('andW AI Translate - 利用可能なAPI:', {
            'wp.editor': !!wp.editor,
            'wp.editPost': !!wp.editPost,
            'wp.editor.PluginSidebar': !!(wp.editor && wp.editor.PluginSidebar),
            'wp.editPost.PluginSidebar': !!(wp.editPost && wp.editPost.PluginSidebar)
        });
        return;
    }

    if (!PluginSidebar || !PluginSidebarMoreMenuItem) {
        console.error('andW AI Translate - 必要なコンポーネントが取得できません', { PluginSidebar, PluginSidebarMoreMenuItem });
        return;
    }

    const { PanelBody, Button, SelectControl, Notice } = wp.components;
    const { useState } = wp.element;
    const { useSelect, useDispatch } = wp.data;
    const { __ } = wp.i18n;

    /**
     * ブロック翻訳サイドバーコンポーネント（Pure JavaScript版）
     */
    function AndwBlockTranslateSidebar() {
        console.log('andW AI Translate - AndwBlockTranslateSidebar render');

        // 基本的なサイドバー内容を返す
        return wp.element.createElement(
            PanelBody,
            {
                title: __('ブロック翻訳', 'andw-ai-translate'),
                initialOpen: true
            },
            wp.element.createElement(
                'p',
                {},
                __('翻訳したいブロックを選択してください', 'andw-ai-translate')
            ),
            wp.element.createElement(
                SelectControl,
                {
                    label: __('対象言語', 'andw-ai-translate'),
                    value: 'en',
                    options: [
                        { label: __('英語', 'andw-ai-translate'), value: 'en' },
                        { label: __('中国語（簡体字）', 'andw-ai-translate'), value: 'zh' },
                        { label: __('韓国語', 'andw-ai-translate'), value: 'ko' }
                    ],
                    onChange: function(value) {
                        console.log('言語選択:', value);
                    }
                }
            ),
            wp.element.createElement(
                Button,
                {
                    isPrimary: true,
                    onClick: function() {
                        console.log('翻訳ボタンクリック');
                        alert('翻訳機能は実装中です');
                    },
                    style: { width: '100%', marginTop: '12px' }
                },
                __('このブロックを翻訳', 'andw-ai-translate')
            )
        );
    }

    /**
     * プラグインサイドバーの登録
     */
    function AndwTranslateSidebarPlugin() {
        console.log('andW AI Translate - AndwTranslateSidebarPlugin render');

        return wp.element.createElement(
            wp.element.Fragment,
            {},
            wp.element.createElement(
                PluginSidebarMoreMenuItem,
                {
                    target: "andw-ai-translate-sidebar",
                    icon: "translation"
                },
                __('AI翻訳', 'andw-ai-translate')
            ),
            wp.element.createElement(
                PluginSidebar,
                {
                    name: "andw-ai-translate-sidebar",
                    title: __('AI翻訳', 'andw-ai-translate'),
                    icon: "translation"
                },
                wp.element.createElement(AndwBlockTranslateSidebar)
            )
        );
    }

    // プラグインとして登録
    console.log('andW AI Translate - プラグインサイドバーを登録');

    try {
        registerPlugin('andw-ai-translate-sidebar', {
            render: AndwTranslateSidebarPlugin,
            icon: 'translation',
        });
        console.log('andW AI Translate - プラグイン登録成功');
    } catch (error) {
        console.error('andW AI Translate - プラグイン登録失敗:', error);
    }

    // 登録確認ログ
    setTimeout(() => {
        if (wp.plugins && wp.plugins.getPlugins) {
            const plugins = wp.plugins.getPlugins();
            console.log('andW AI Translate - 登録済みプラグイン:', plugins);
        }
    }, 1000);

})();