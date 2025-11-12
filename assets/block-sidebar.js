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

        // 状態管理（シンプルな実装）
        const [isTranslating, setIsTranslating] = useState(false);
        const [targetLanguage, setTargetLanguage] = useState('en');
        const [provider, setProvider] = useState('openai');
        const [notice, setNotice] = useState(null);

        // 選択中のブロックを取得（エラーハンドリング付き）
        const selectedBlock = useSelect(function(select) {
            try {
                const blockEditorSelect = select('core/block-editor');

                // 利用可能なメソッドを確認
                console.log('andW AI Translate - 利用可能なblock-editorメソッド:', Object.keys(blockEditorSelect));

                // 複数の方法でブロック選択を試行
                let selectedBlockId = null;
                let selectedBlock = null;

                // 方法1: getSelectedBlockId
                if (typeof blockEditorSelect.getSelectedBlockId === 'function') {
                    selectedBlockId = blockEditorSelect.getSelectedBlockId();
                    console.log('andW AI Translate - getSelectedBlockId結果:', selectedBlockId);
                }

                // 方法2: getSelectedBlocks（複数選択対応）
                if (!selectedBlockId && typeof blockEditorSelect.getSelectedBlocks === 'function') {
                    const selectedBlocks = blockEditorSelect.getSelectedBlocks();
                    if (selectedBlocks && selectedBlocks.length > 0) {
                        selectedBlock = selectedBlocks[0];
                        console.log('andW AI Translate - getSelectedBlocks結果:', selectedBlocks);
                        return selectedBlock;
                    }
                }

                // 方法3: getBlockSelectionStart
                if (!selectedBlockId && typeof blockEditorSelect.getBlockSelectionStart === 'function') {
                    selectedBlockId = blockEditorSelect.getBlockSelectionStart();
                    console.log('andW AI Translate - getBlockSelectionStart結果:', selectedBlockId);
                }

                // ブロックIDからブロックオブジェクトを取得
                if (selectedBlockId && typeof blockEditorSelect.getBlock === 'function') {
                    selectedBlock = blockEditorSelect.getBlock(selectedBlockId);
                    console.log('andW AI Translate - 取得したブロック:', selectedBlock);
                    return selectedBlock;
                }

                return null;
            } catch (error) {
                console.error('andW AI Translate - ブロック選択取得エラー:', error);
                return null;
            }
        }, []);

        // ブロックエディタのディスパッチャー（エラーハンドリング付き）
        const blockEditorDispatch = useDispatch('core/block-editor');

        // ディスパッチメソッドの安全な取得
        const updateBlockAttributes = blockEditorDispatch && blockEditorDispatch.updateBlockAttributes;
        const replaceBlocks = blockEditorDispatch && blockEditorDispatch.replaceBlocks;

        // 翻訳実行
        const handleTranslate = function() {
            if (!selectedBlock) {
                setNotice({
                    status: 'error',
                    content: __('ブロックが選択されていません', 'andw-ai-translate')
                });
                return;
            }

            console.log('andW AI Translate - 翻訳開始:', {
                blockName: selectedBlock.name,
                blockId: selectedBlock.clientId,
                targetLanguage: targetLanguage,
                provider: provider
            });

            setIsTranslating(true);
            setNotice(null);

            // AJAX呼び出し（既存のメタボックス機能を利用）
            jQuery.ajax({
                url: ajaxurl,
                type: 'POST',
                timeout: 300000, // 5分のタイムアウト
                data: {
                    action: 'andw_ai_translate_block',
                    nonce: andwBlockTranslate.nonce,
                    block_data: JSON.stringify(selectedBlock),
                    target_language: targetLanguage,
                    provider: provider
                },
                beforeSend: function() {
                    console.log('andW AI Translate - AJAX送信開始:', {
                        url: ajaxurl,
                        action: 'andw_ai_translate_block',
                        nonce: andwBlockTranslate.nonce ? 'あり' : 'なし',
                        blockDataLength: JSON.stringify(selectedBlock).length,
                        targetLanguage: targetLanguage,
                        provider: provider
                    });
                }
            }).then(function(response) {
                console.log('andW AI Translate - 翻訳API応答:', response);

                if (response.success && response.data) {
                    const translatedBlock = response.data.translated_block;

                    // ブロックを翻訳結果で更新（安全チェック付き）
                    if (replaceBlocks && selectedBlock.clientId) {
                        replaceBlocks(selectedBlock.clientId, translatedBlock);
                        console.log('andW AI Translate - ブロック更新完了');
                    } else if (updateBlockAttributes && selectedBlock.clientId) {
                        // replaceBlocksが使えない場合はupdateBlockAttributesを試行
                        updateBlockAttributes(selectedBlock.clientId, translatedBlock.attributes || {});
                        console.log('andW AI Translate - ブロック属性更新完了');
                    } else {
                        console.error('andW AI Translate - ブロック更新メソッドが利用できません');
                        setNotice({
                            status: 'error',
                            content: __('ブロック更新に失敗しました', 'andw-ai-translate')
                        });
                        return;
                    }

                    setNotice({
                        status: 'success',
                        content: __('ブロックが翻訳されました', 'andw-ai-translate')
                    });
                } else {
                    setNotice({
                        status: 'error',
                        content: response.data || __('翻訳に失敗しました', 'andw-ai-translate')
                    });
                }
            }).catch(function(xhr, status, error) {
                console.error('andW AI Translate - 翻訳エラー:', {
                    xhr: xhr,
                    status: status,
                    error: error,
                    responseText: xhr.responseText
                });

                let errorMessage = __('通信エラーが発生しました', 'andw-ai-translate');

                if (status === 'timeout') {
                    errorMessage = __('タイムアウトが発生しました。しばらく待ってから再試行してください', 'andw-ai-translate');
                } else if (xhr.responseJSON && xhr.responseJSON.data) {
                    errorMessage = xhr.responseJSON.data;
                } else if (xhr.responseText) {
                    errorMessage = __('サーバーエラー: ', 'andw-ai-translate') + xhr.responseText.substring(0, 100);
                }

                setNotice({
                    status: 'error',
                    content: errorMessage
                });
            }).finally(function() {
                console.log('andW AI Translate - 翻訳処理終了（成功/失敗問わず）');
                setIsTranslating(false);
            });
        };

        // 翻訳可能なブロックかチェック
        const isTranslatableBlock = function(block) {
            if (!block) return false;
            const translatableBlocks = [
                'core/paragraph',
                'core/heading',
                'core/list',
                'core/quote',
                'core/pullquote',
                'core/verse',
                'core/preformatted',
                'core/button',
                'core/cover',
                'core/media-text'
            ];
            return translatableBlocks.includes(block.name);
        };

        // UI要素の作成
        const elements = [];

        // 通知の表示
        if (notice) {
            elements.push(
                wp.element.createElement(
                    Notice,
                    {
                        status: notice.status,
                        isDismissible: false,
                        key: 'notice'
                    },
                    notice.content
                )
            );
        }

        // 選択ブロック情報
        if (!selectedBlock) {
            elements.push(
                wp.element.createElement(
                    'p',
                    { key: 'no-selection' },
                    __('翻訳したいブロックを選択してください', 'andw-ai-translate')
                )
            );
        } else if (!isTranslatableBlock(selectedBlock)) {
            elements.push(
                wp.element.createElement(
                    Notice,
                    {
                        status: 'warning',
                        isDismissible: false,
                        key: 'not-translatable'
                    },
                    __('選択されたブロックは翻訳に対応していません', 'andw-ai-translate')
                )
            );
        } else {
            // 翻訳可能なブロックが選択されている場合のUI
            elements.push(
                wp.element.createElement(
                    'p',
                    { key: 'block-info' },
                    wp.element.createElement('strong', {}, __('選択中のブロック', 'andw-ai-translate') + ': '),
                    selectedBlock.name
                )
            );

            elements.push(
                wp.element.createElement(
                    SelectControl,
                    {
                        label: __('対象言語', 'andw-ai-translate'),
                        value: targetLanguage,
                        options: [
                            { label: __('英語', 'andw-ai-translate'), value: 'en' },
                            { label: __('中国語（簡体字）', 'andw-ai-translate'), value: 'zh' },
                            { label: __('中国語（繁体字）', 'andw-ai-translate'), value: 'zh-TW' },
                            { label: __('韓国語', 'andw-ai-translate'), value: 'ko' },
                            { label: __('フランス語', 'andw-ai-translate'), value: 'fr' },
                            { label: __('ドイツ語', 'andw-ai-translate'), value: 'de' },
                            { label: __('スペイン語', 'andw-ai-translate'), value: 'es' }
                        ],
                        onChange: setTargetLanguage,
                        disabled: isTranslating,
                        key: 'language-select'
                    }
                )
            );

            elements.push(
                wp.element.createElement(
                    SelectControl,
                    {
                        label: __('プロバイダ', 'andw-ai-translate'),
                        value: provider,
                        options: [
                            { label: 'OpenAI', value: 'openai' },
                            { label: 'Claude', value: 'claude' }
                        ],
                        onChange: setProvider,
                        disabled: isTranslating,
                        key: 'provider-select'
                    }
                )
            );

            elements.push(
                wp.element.createElement(
                    Button,
                    {
                        isPrimary: true,
                        isBusy: isTranslating,
                        disabled: isTranslating,
                        onClick: handleTranslate,
                        style: { width: '100%', marginTop: '12px' },
                        key: 'translate-button'
                    },
                    isTranslating
                        ? __('翻訳中...', 'andw-ai-translate')
                        : __('このブロックを翻訳', 'andw-ai-translate')
                )
            );
        }

        // パネルボディに全ての要素を含める
        return wp.element.createElement(
            PanelBody,
            {
                title: __('ブロック翻訳', 'andw-ai-translate'),
                initialOpen: true
            },
            ...elements
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