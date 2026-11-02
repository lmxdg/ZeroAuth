<?php
// 首页模板 V1 插件元数据
declare(strict_types=1);

return [
    'code' => 'tpl_v1',
    'name' => 'V1 营销官网模板',
    'cat' => 'template',
    'description' => '可配置轮播图、顶部公告、功能卡与页脚的营销型首页，后台「应用商店 → 配置」可视化调整。',
    'version' => '1.1.0',
    'author' => '官方内置',
    'core' => 1,
    'installable' => 1,
    'status' => 2,
    'sort' => 9,
    'config' => [
        [
            'key'   => 'announce_on',
            'label' => '显示顶部公告',
            'type'  => 'switch',
            'hint'  => '开启后在页面顶部显示公告条',
        ],
        [
            'key'   => 'announce_text',
            'label' => '公告内容',
            'type'  => 'text',
            'hint'  => '公告文字，留空则不显示公告条',
        ],
        [
            'key'   => 'announce_link',
            'label' => '公告跳转链接',
            'type'  => 'text',
            'hint'  => '可选，点击公告打开的地址',
        ],
        [
            'key'   => 'banners',
            'label' => '轮播图配置',
            'type'  => 'textarea',
            'hint'  => '每行一张：图片地址|跳转链接|标题说明（链接与标题可留空），留空则隐藏轮播区',
        ],
        [
            'key'   => 'banner_autoplay',
            'label' => '轮播切换间隔',
            'type'  => 'select',
            'options' => ['3000' => '3 秒', '5000' => '5 秒', '8000' => '8 秒', '0' => '不自动切换'],
            'hint'  => '鼠标拖拽可手动切换',
        ],
        [
            'key'   => 'hero_title',
            'label' => '主标题',
            'type'  => 'text',
            'hint'  => '轮播下方主视觉标题，留空使用默认',
        ],
        [
            'key'   => 'hero_sub',
            'label' => '主标题副文案',
            'type'  => 'textarea',
            'hint'  => '主视觉描述文字，留空使用默认',
        ],
        [
            'key'   => 'features',
            'label' => '功能卡配置',
            'type'  => 'textarea',
            'hint'  => '每行一张：标题|描述|图标地址（图标可留空），留空使用默认四张',
        ],
        [
            'key'   => 'show_query',
            'label' => '显示域名授权查询',
            'type'  => 'switch',
            'hint'  => '关闭后首页不展示查询卡片',
        ],
        [
            'key'   => 'footer_text',
            'label' => '页脚附加文字',
            'type'  => 'text',
            'hint'  => '显示在版权信息旁，如备案号',
        ],
    ],
];
