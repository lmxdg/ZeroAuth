<?php
// 腾讯云人脸核身实名认证插件元数据
// 文档：https://cloud.tencent.com/document/product/1007
declare(strict_types=1);

return [
    'code'        => 'tencent_faceid',
    'name'        => '腾讯云人脸核身',
    'cat'         => 'realname',
    'description' => '对接腾讯云人脸核身 FaceID（H5 / 微信内）：姓名 + 身份证发起核身，用户完成活体刷脸后回跳站点，核验通过自动写入实名状态，证件号 AES 加密存储，全部留痕审计。文档：https://cloud.tencent.com/document/product/1007',
    'version'     => '1.0.0',
    'author'      => '官方插件',
    'core'        => 0,
    'installable' => 1,
    'status'      => 3,
    'sort'        => 5,
    'config'      => [
        [
            'key'     => 'secret_id',
            'label'   => 'SecretId',
            'type'    => 'text',
            'hint'    => '腾讯云 API 密钥 SecretId（控制台 → 访问管理 → API 密钥管理）',
            'default' => '',
        ],
        [
            'key'   => 'secret_key',
            'label' => 'SecretKey',
            'type'  => 'password',
            'hint'  => '腾讯云 API 密钥 SecretKey，仅服务端使用，保存后不再回显',
        ],
        [
            'key'     => 'rule_id',
            'label'   => 'RuleId（业务流程 ID）',
            'type'    => 'text',
            'hint'    => '人脸核身控制台「自助接入」创建的业务流程 ID（纯数字，如 1）',
            'default' => '',
        ],
        [
            'key'     => 'auto_pass',
            'label'   => '核验通过后自动完成实名',
            'type'    => 'switch',
            'hint'    => '开启：腾讯云核验通过后自动置为「已通过」；关闭：仅置为「待审核」，由管理员人工复核',
            'default' => '1',
        ],
        [
            'key'     => 'require_name',
            'label'   => '发起前填写姓名与证件号',
            'type'    => 'switch',
            'hint'    => '开启：用户先填写姓名 + 身份证号再发起核身（权威源比对，推荐）；关闭：直接发起核身，身份信息由腾讯云页面采集',
            'default' => '1',
        ],
    ],
];
