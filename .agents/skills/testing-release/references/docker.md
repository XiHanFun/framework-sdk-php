# 本机用 Docker 跑门禁

本机没有 PHP 与 Composer 时用 Docker。下面的命令在仓库根执行；PowerShell 直接用 `${PWD}`，Git Bash 里在命令前加 `MSYS_NO_PATHCONV=1`，挂载路径用 `$(pwd -W)`。

## 官方镜像

```powershell
docker run --rm -v "${PWD}:/app" -w /app composer:2 install
docker run --rm -v "${PWD}:/app" -w /app php:8.3-cli vendor/bin/phpcs
docker run --rm -v "${PWD}:/app" -w /app php:8.3-cli vendor/bin/phpstan analyse --memory-limit=1G
docker run --rm -v "${PWD}:/app" -w /app php:8.3-cli vendor/bin/phpunit
```

- 把镜像换成 `php:8.4-cli` 再跑一遍。`composer.json` 把依赖解析的平台锁在 PHP 8.3.0，同一份 `vendor/` 在 8.3 与 8.4 上都能运行。
- 官方 PHP 镜像里没有 Go：在容器里装 Go 的 linux 版本再跑 `vendor/bin/phpunit`，或给 `docker run` 加 `-e XIHAN_CONFORMANCE_REQUIRED=0` 跳过一致性测试（跳过不算验证过）。
- 官方镜像不带 pcntl：`docker-php-ext-install pcntl` 之后才能跑 SIGTERM 的用例。
- amphp/socket 要的 openssl 与 amphp/http 间接要的 mbstring 在官方的 `php:8.3-cli` 镜像里都有。

## 带 Go、protoc 与 pcntl 的本地镜像

本机建过 `xihan-php-plugin` 镜像，先 `docker image ls xihan-php-plugin` 核实：

| 标签 | 内容 |
| --- | --- |
| `8.3`、`8.4` | 官方 `php:<版本>-cli` 加 git、unzip、Go、protoc、composer，没有 pcntl |
| `8.3-pcntl`、`8.4-pcntl` | 上面的镜像加 pcntl |

常用组合是 `xihan-php-plugin:8.3`（没有 pcntl，SIGTERM 用例跳过）与 `xihan-php-plugin:8.4-pcntl`（SIGTERM 用例真正运行），两个都跑一遍。Go 的模块缓存放在命名卷 `xihan-gomod`，容器之间复用。

```bash
# Git Bash，在仓库根
MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd -W):/app" -v xihan-gomod:/root/go/pkg/mod -w /app \
  xihan-php-plugin:8.3 sh -c 'composer validate --no-interaction && composer check'
MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd -W):/app" -v xihan-gomod:/root/go/pkg/mod -w /app \
  xihan-php-plugin:8.4-pcntl sh -c 'composer validate --no-interaction && composer check'
```

打印 PHP 版本与是否有 pcntl，确认跑在预期的环境里：

```bash
php -r 'echo PHP_VERSION, " pcntl=", extension_loaded("pcntl") ? "yes" : "no", PHP_EOL;'
```

镜像丢了就重建。基础镜像（`PHP` 取 `8.3` 或 `8.4`；`GO_VERSION` 不低于 `spec/conformance/go.mod` 的 `go` 行，`PROTOC_VERSION` 等于 `scripts/generate-plugin-protocol.php` 的 `$protocVersion`；Go 从国内镜像下载，模块经国内代理）：

```dockerfile
ARG PHP=8.3
FROM php:${PHP}-cli
ARG GO_VERSION
ARG PROTOC_VERSION
RUN apt-get update && apt-get install -y --no-install-recommends unzip git && rm -rf /var/lib/apt/lists/*
RUN curl -fsSL https://golang.google.cn/dl/go${GO_VERSION}.linux-amd64.tar.gz | tar -C /usr/local -xz
RUN curl -fsSL -o /tmp/protoc.zip https://github.com/protocolbuffers/protobuf/releases/download/v${PROTOC_VERSION}/protoc-${PROTOC_VERSION}-linux-x86_64.zip \
    && unzip -q /tmp/protoc.zip -d /usr/local/protoc && rm /tmp/protoc.zip
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
ENV PATH=/usr/local/go/bin:/usr/local/protoc/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin \
    GOPROXY=https://goproxy.cn,direct \
    GOSUMDB=sum.golang.google.cn \
    GOPRIVATE=github.com/xihanfun/framework-spec \
    GOFLAGS=-buildvcs=false
```

带 pcntl 的一层：

```dockerfile
ARG BASE=xihan-php-plugin:8.3
FROM ${BASE}
RUN docker-php-ext-install pcntl
```

```bash
docker build -t xihan-php-plugin:8.3 --build-arg PHP=8.3 --build-arg GO_VERSION=<版本> --build-arg PROTOC_VERSION=<版本> -f <基础镜像的 Dockerfile> <空目录>
docker build -t xihan-php-plugin:8.3-pcntl --build-arg BASE=xihan-php-plugin:8.3 -f <pcntl 的 Dockerfile> <空目录>
```

Dockerfile 放在 scratchpad 之类的临时目录，不提交进仓库。

## 在容器里核对协议代码

```bash
MSYS_NO_PATHCONV=1 docker run --rm -v "$(pwd -W):/app" -w /app xihan-php-plugin:8.3 php scripts/generate-plugin-protocol.php
git status --short src/Plugin/Protocol    # 在宿主机上看：有改动或多出文件就是不一致
```

## 手动跑黑盒运行器

排查单个用例时在容器里照 `ConformanceTest` 的做法手动跑：

```sh
#!/bin/sh
# 把 echo 放进临时目录，生成启动脚本，运行 xihan-conformance；额外参数（如 --case stdin）原样传给运行器
set -e
cd /app
dir=$(mktemp -d)
cp examples/echo/xihan.manifest.json "$dir/xihan.manifest.json"
printf '#!/bin/sh\nexec %s %s "$@"\n' "$(command -v php)" "/app/examples/echo/bin/echo.php" > "$dir/echo"
chmod +x "$dir/echo"
cd /app/spec/conformance
go run ./cmd/xihan-conformance plugin --dir "$dir" --config '{"greeting":"hello"}' --sample 'echoes.create={"text":"hi"}' --exclude echoes.crash "$@"
```

脚本同样放在临时目录，挂进容器（如 `-v "<临时目录>:/scratch"`）后 `sh /scratch/<脚本>` 运行。
