<?php
declare(strict_types=1);

/** 附件与头像上传：真实落盘到 uploads/，返回 { url, name, size } */

function qly_api_upload(): void
{
    $type = (string) qly_input('type', 'attachment'); // attachment | avatar | logo

    // 头像允许未登录上传：注册页需要在登录前先传头像（注册后头像为必填）。
    // 安全约束：仅 type=avatar、仅 jpg/png、且不得超过 2MB（见下方校验）。
    // 附件/logo 上传仍需登录。
    $user = null;
    if ($type === 'avatar') {
        $user = qly_current_user();
        if ($user === null) {
            $user = ['id' => 0, 'account' => 'guest', 'roles' => ''];
        }
    } else {
        $user = qly_auth_user();
    }

    $cfg = qly_app_config();
    // logo：灯牌下方小动物图，仅图片、上限 2MB，与头像同量级
    $maxSize = $type === 'avatar' || $type === 'logo'
        ? (int) $cfg['avatar_max_size']
        : (int) $cfg['max_upload_size'];

    $file = $_FILES['file'] ?? null;
    if (!$file || !isset($file['tmp_name'])) {
        // 请求体超出 PHP post_max_size 时，$_POST/$_FILES 会被清空。
        // 但该判断只能用于 multipart 表单：JSON（base64）提交时 $_POST/$_FILES 本来就为空，
        // 若不加内容类型限定，会把正常的 base64 上传误判为「过大」。
        $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
        $isMultipart = stripos($contentType, 'multipart/form-data') !== false;
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($isMultipart && $contentLength > 0 && empty($_POST) && empty($_FILES)) {
            qly_fail('上传内容过大，超出服务器允许的请求体上限（单个附件请不超过 50MB）');
        }
        // 兼容 base64 提交（部分前端上传场景）
        $raw = (string) qly_input('fileBase64', '');
        $fileName = (string) qly_input('fileName', '');
        if ($raw === '') {
            qly_fail('请选择要上传的文件');
        }
        if (preg_match('/^data:([^;]+);base64,(.+)$/s', $raw, $m)) {
            $mime = $m[1];
            $bin = base64_decode($m[2], true);
        } else {
            $mime = (string) qly_input('mimeType', 'application/octet-stream');
            $bin = base64_decode($raw, true);
        }
        if ($bin === false) {
            qly_fail('文件内容解析失败');
        }
        if (strlen($bin) > $maxSize) {
            qly_fail('文件大小超出限制');
        }
        if ($type === 'avatar') {
            if (!in_array(strtolower(pathinfo($fileName, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png'], true)) {
                qly_fail('头像仅支持 jpg / png 格式');
            }
            if (strpos($bin, "\x89PNG\r\n\x1a\n") !== 0 && substr($bin, 0, 2) !== "\xFF\xD8") {
                qly_fail('头像文件不是有效的图片');
            }
        }
        if ($type === 'logo') {
            if (!in_array(strtolower(pathinfo($fileName, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
                qly_fail('logo 仅支持 jpg / png / gif / webp 格式');
            }
            if (substr($bin, 0, 2) !== "\xFF\xD8"
                && strpos($bin, "\x89PNG\r\n\x1a\n") !== 0
                && strpos($bin, 'GIF8') !== 0
                && strpos($bin, 'RIFF') !== 0) {
                qly_fail('logo 文件不是有效的图片');
            }
        }
        qly_store_file($bin, $fileName, $mime, $type, $user);
        return;
    }

    if ((int) $file['error'] !== UPLOAD_ERR_OK) {
        $code = (int) $file['error'];
        if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
            qly_fail('文件过大，超出服务器允许的上传上限（单个附件请不超过 50MB）');
        }
        if ($code === UPLOAD_ERR_PARTIAL) {
            qly_fail('文件上传中断，请重试');
        }
        qly_fail('文件上传失败（错误码 ' . $code . '）');
    }
    if ((int) $file['size'] > $maxSize) {
        qly_fail($type === 'avatar' ? '头像不能超过 2MB' : '单个附件不能超过 50MB');
    }
    $name = (string) $file['name'];
    $mime = (string) ($file['type'] ?? '');
    $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
    if ($type === 'avatar') {
        if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
            qly_fail('头像仅支持 jpg / png 格式');
        }
        // 未登录也能传头像，因此必须确认它真的是图片（防止被当作任意文件存储）
        $info = @getimagesize((string) $file['tmp_name']);
        if ($info === false || !in_array($info[2] ?? 0, [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            qly_fail('头像文件不是有效的图片');
        }
    } elseif ($type === 'logo') {
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            qly_fail('logo 仅支持 jpg / png / gif / webp 格式');
        }
        $info = @getimagesize((string) $file['tmp_name']);
        if ($info === false || !in_array($info[2] ?? 0, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
            qly_fail('logo 文件不是有效的图片');
        }
    } else {
        // 以扩展名为准（浏览器上报的 MIME 对 docx/xlsx 等不一致，不可靠）
        $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg',
            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'zip', 'rar', '7z'];
        if ($ext === '' || !in_array($ext, $allowedExt, true)) {
            qly_fail('不支持的文件类型：' . ($ext !== '' ? '.' . $ext : '未知格式'));
        }
    }
    $bin = file_get_contents((string) $file['tmp_name']);
    if ($bin === false) {
        qly_fail('读取上传文件失败');
    }
    qly_store_file($bin, $name, $mime, $type, $user);
}

function qly_store_file(string $bin, string $name, string $mime, string $type, array $user): void
{
    $cfg = qly_app_config();
    // logo 单独放 uploads/logo，便于与用户附件区分
    $sub = $type === 'avatar' ? 'avatar' : ($type === 'logo' ? 'logo' : date('Ym'));
    $dir = rtrim((string) $cfg['upload_dir'], '/\\') . DIRECTORY_SEPARATOR . $sub;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        qly_fail('创建上传目录失败');
    }
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext === '') {
        $ext = 'dat';
    }
    $safeExt = preg_replace('/[^a-z0-9]/', '', $ext);
    $fileName = date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $safeExt;
    $path = $dir . DIRECTORY_SEPARATOR . $fileName;
    if (file_put_contents($path, $bin) === false) {
        qly_fail('文件保存失败');
    }
    $url = (string) $cfg['upload_url'] . '/' . $sub . '/' . $fileName;
    qly_ok([
        'url' => $url,
        'name' => $name,
        'size' => strlen($bin),
        'mimeType' => $mime,
    ], '上传成功');
}
