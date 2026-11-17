<?php

declare(strict_types=1);

/*
 * 回声插件的入口：宿主以插件目录为工作目录启动它（handshake.md §1），清单读工作目录里的 xihan.manifest.json，
 * 握手时原样发出，与目录里的文件逐字段一致；工作目录里没有时读示例自己的清单。
 */

use XiHan\Framework\Examples\Echo\EchoPlugin;
use XiHan\Framework\Plugin\Runtime;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$manifest = is_file(getcwd() . '/xihan.manifest.json')
    ? getcwd() . '/xihan.manifest.json'
    : dirname(__DIR__) . '/xihan.manifest.json';

exit(Runtime::serve(EchoPlugin::options((string) file_get_contents($manifest))));
