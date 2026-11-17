<?php

declare(strict_types=1);

/*
 * 从 spec/plugin/plugin.proto 生成插件协议 xihan.plugin.v1 的 PHP 消息类，写到 src/Plugin/Protocol/V1。
 *
 * 用法：php scripts/generate-plugin-protocol.php [protoc 的路径]，缺省用 PATH 里的 protoc。
 * protoc 的版本必须是下面的 $protocVersion：生成代码与 composer.json 里 google/protobuf 的运行时同属一个发布，
 * 换版本时两处一起改。规范文件不带 PHP 的命名空间选项，这里在临时副本里补上 php_namespace 与
 * php_metadata_namespace，生成的类落在本包的命名空间下，不占用全局的 GPBMetadata 与 Xihan 命名空间。
 */

$protocVersion = '36.2';
$namespace = 'XiHan\\Framework\\Plugin\\Protocol\\V1';
$metadataNamespace = $namespace . '\\Metadata';

$root = dirname(__DIR__);
$protoc = $argv[1] ?? 'protoc';
$source = $root . '/spec/plugin/plugin.proto';
$target = $root . '/src/Plugin/Protocol/V1';

// 运行命令并返回标准输出；失败即退出
$run = static function (string ...$command): string {
    $process = proc_open(array_values($command), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        fwrite(STDERR, 'Cannot start ' . $command[0] . ".\n");
        exit(1);
    }
    $output = (string) stream_get_contents($pipes[1]);
    $errors = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        fwrite(STDERR, implode(' ', $command) . " failed:\n" . $errors);
        exit(1);
    }

    return $output;
};

/**
 * 递归删除目录。
 */
$remove = static function (string $path) use (&$remove): void {
    if (!file_exists($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        unlink($path);

        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            $remove($path . '/' . $entry);
        }
    }
    rmdir($path);
};

/**
 * 递归复制目录（临时目录与仓库常在不同的文件系统上，rename() 搬不动目录）；文件末尾只留一个换行，
 * 与 .editorconfig 一致（protoc 在文件末尾多写一个空行）。
 */
$copy = static function (string $from, string $to) use (&$copy): void {
    mkdir($to, 0777, true);
    foreach (scandir($from) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        if (is_dir($from . '/' . $entry)) {
            $copy($from . '/' . $entry, $to . '/' . $entry);
        } else {
            file_put_contents($to . '/' . $entry, rtrim((string) file_get_contents($from . '/' . $entry)) . "\n");
        }
    }
};

$version = trim($run($protoc, '--version'));
if ($version !== 'libprotoc ' . $protocVersion) {
    fwrite(STDERR, sprintf("protoc %s is required, got '%s'.\n", $protocVersion, $version));
    exit(1);
}

$proto = file_get_contents($source);
if ($proto === false || substr_count($proto, "\npackage xihan.plugin.v1;\n") !== 1) {
    fwrite(STDERR, "Cannot find the package declaration in spec/plugin/plugin.proto.\n");
    exit(1);
}
// 选项放在 package 声明之后；proto 的字符串字面量里反斜杠要写两个
$options = sprintf(
    "\noption php_namespace = \"%s\";\noption php_metadata_namespace = \"%s\";\n",
    str_replace('\\', '\\\\', $namespace),
    str_replace('\\', '\\\\', $metadataNamespace),
);
$proto = str_replace("\npackage xihan.plugin.v1;\n", "\npackage xihan.plugin.v1;\n" . $options, $proto);

$work = sys_get_temp_dir() . '/xihan-plugin-protocol-' . bin2hex(random_bytes(8));
mkdir($work . '/in', 0700, true);
mkdir($work . '/out', 0700, true);
try {
    // 文件名保持 plugin.proto：元数据类名取自文件名
    file_put_contents($work . '/in/plugin.proto', $proto);
    $run($protoc, '--php_out=' . $work . '/out', '-I', $work . '/in', $work . '/in/plugin.proto');
    $generated = $work . '/out/' . str_replace('\\', '/', $namespace);
    if (!is_dir($generated)) {
        fwrite(STDERR, "protoc did not write the expected namespace directory.\n");
        exit(1);
    }
    $remove($target);
    $copy($generated, $target);
} finally {
    $remove($work);
}

echo 'Generated ', str_replace($root . '/', '', $target), ' with protoc ', $protocVersion, ".\n";
