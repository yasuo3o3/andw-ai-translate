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

    if (!window.wp.editPost && !window.wp.editor) {
        console.error('andW AI Translate - wp.editPostまたはwp.editorが利用できません');
        return;
    }

    console.log('andW AI Translate - WordPress API確認完了');

    const { registerPlugin } = wp.plugins;

    // PluginSidebar のインポートを安全にチェック
    let PluginSidebar, PluginSidebarMoreMenuItem;

    if (wp.editPost) {
        PluginSidebar = wp.editPost.PluginSidebar;
        PluginSidebarMoreMenuItem = wp.editPost.PluginSidebarMoreMenuItem;
        console.log('andW AI Translate - wp.editPostからコンポーネントを取得');
    } else if (wp.editor) {
        PluginSidebar = wp.editor.PluginSidebar;
        PluginSidebarMoreMenuItem = wp.editor.PluginSidebarMoreMenuItem;
        console.log('andW AI Translate - wp.editorからコンポーネントを取得');
    } else {
        console.error('andW AI Translate - PluginSidebarコンポーネントが取得できません');
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
     * ブロック翻訳サイドバーコンポーネント
     */
    function AndwBlockTranslateSidebar() {
        const [isTranslating, setIsTranslating] = useState(false);
        const [targetLanguage, setTargetLanguage] = useState('en');
        const [provider, setProvider] = useState('openai');
        const [notice, setNotice] = useState(null);

        // 選択中のブロックを取得
        const selectedBlock = useSelect((select) => {
            const selectedBlockId = select('core/block-editor').getSelectedBlockId();
            if (selectedBlockId) {
                return select('core/block-editor').getBlock(selectedBlockId);
            }
            return null;
        });

        // ブロックエディタのディスパッチャー
        const { updateBlockAttributes } = useDispatch('core/block-editor');

        // 言語オプション
        const languageOptions = [
            { label: __('英語', 'andw-ai-translate'), value: 'en' },
            { label: __('中国語（簡体字）', 'andw-ai-translate'), value: 'zh' },
            { label: __('中国語（繁体字）', 'andw-ai-translate'), value: 'zh-TW' },
            { label: __('韓国語', 'andw-ai-translate'), value: 'ko' },
            { label: __('フランス語', 'andw-ai-translate'), value: 'fr' },
            { label: __('ドイツ語', 'andw-ai-translate'), value: 'de' },
            { label: __('スペイン語', 'andw-ai-translate'), value: 'es' }
        ];

        // プロバイダオプション（設定から取得する必要がある）
        const providerOptions = [
            { label: 'OpenAI', value: 'openai' },
            { label: 'Claude', value: 'claude' }
        ];

        // 翻訳実行
        const handleTranslate = () => {
            if (!selectedBlock) {
                setNotice({
                    status: 'error',
                    content: __('ブロックが選択されていません', 'andw-ai-translate')
                });
                return;
            }

            setIsTranslating(true);
            setNotice(null);

            // AJAX リクエスト
            wp.apiFetch({
                path: '/andw-ai-translate/v1/block',
                method: 'POST',
                data: {
                    block_data: selectedBlock,
                    target_language: targetLanguage,
                    provider: provider,
                    nonce: andwBlockTranslate.nonce
                }
            }).then((response) => {
                if (response.success) {
                    // ブロックを翻訳結果で更新
                    const translatedBlock = response.data.translated_block;
                    updateBlockAttributes(selectedBlock.clientId, translatedBlock.attributes || {});

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
            }).catch((error) => {
                setNotice({
                    status: 'error',
                    content: __('通信エラーが発生しました', 'andw-ai-translate')
                });
            }).finally(() => {
                setIsTranslating(false);
            });
        };

        // 翻訳可能なブロックかチェック
        const isTranslatableBlock = (block) => {
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

        return (
            <PanelBody
                title={__('ブロック翻訳', 'andw-ai-translate')}
                initialOpen={true}
            >
                {notice && (
                    <Notice
                        status={notice.status}
                        isDismissible={false}
                        style={{ marginBottom: '16px' }}
                    >
                        {notice.content}
                    </Notice>
                )}

                {!selectedBlock && (
                    <p>{__('翻訳したいブロックを選択してください', 'andw-ai-translate')}</p>
                )}

                {selectedBlock && !isTranslatableBlock(selectedBlock) && (
                    <Notice status="warning" isDismissible={false}>
                        {__('選択されたブロックは翻訳に対応していません', 'andw-ai-translate')}
                    </Notice>
                )}

                {selectedBlock && isTranslatableBlock(selectedBlock) && (
                    <>
                        <p><strong>{__('選択中のブロック', 'andw-ai-translate')}:</strong> {selectedBlock.name}</p>

                        <SelectControl
                            label={__('対象言語', 'andw-ai-translate')}
                            value={targetLanguage}
                            options={languageOptions}
                            onChange={setTargetLanguage}
                            disabled={isTranslating}
                        />

                        <SelectControl
                            label={__('プロバイダ', 'andw-ai-translate')}
                            value={provider}
                            options={providerOptions}
                            onChange={setProvider}
                            disabled={isTranslating}
                        />

                        <Button
                            isPrimary
                            isBusy={isTranslating}
                            disabled={isTranslating}
                            onClick={handleTranslate}
                            style={{ width: '100%', marginTop: '12px' }}
                        >
                            {isTranslating
                                ? __('翻訳中...', 'andw-ai-translate')
                                : __('このブロックを翻訳', 'andw-ai-translate')
                            }
                        </Button>
                    </>
                )}
            </PanelBody>
        );
    }

    /**
     * プラグインサイドバーの登録
     */
    function AndwTranslateSidebarPlugin() {
        return (
            <>
                <PluginSidebarMoreMenuItem
                    target="andw-ai-translate-sidebar"
                    icon="translation"
                >
                    {__('AI翻訳', 'andw-ai-translate')}
                </PluginSidebarMoreMenuItem>

                <PluginSidebar
                    name="andw-ai-translate-sidebar"
                    title={__('AI翻訳', 'andw-ai-translate')}
                    icon="translation"
                >
                    <AndwBlockTranslateSidebar />
                </PluginSidebar>
            </>
        );
    }

    // シンプルなテスト用プラグイン登録
    console.log('andW AI Translate - プラグインサイドバーを登録');

    const TestSidebarPlugin = function() {
        console.log('andW AI Translate - TestSidebarPlugin render 呼び出し');

        return wp.element.createElement(
            wp.element.Fragment,
            {},
            wp.element.createElement(PluginSidebarMoreMenuItem, {
                target: "andw-ai-translate-sidebar",
                icon: "translation"
            }, "AI翻訳"),
            wp.element.createElement(PluginSidebar, {
                name: "andw-ai-translate-sidebar",
                title: "AI翻訳",
                icon: "translation"
            }, wp.element.createElement('p', {}, 'テスト用AI翻訳サイドバー'))
        );
    };

    try {
        registerPlugin('andw-ai-translate-sidebar', {
            render: TestSidebarPlugin,
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