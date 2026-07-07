# Kodbox Nextcloud Desktop 兼容同步插件

独立于旧 WebDAV 插件，只处理 Nextcloud Desktop 同步入口。旧 WebDAV 可继续负责普通 WebDAV 挂载；本插件启用后，旧 WebDAV 会让出 Nextcloud 相关路由。

## 1. 启用插件

启用插件 `webdavNextcloud`。客户端使用 Kodbox 账号密码登录，权限与 Web 端一致。

兼容入口：

- `/status.php`
- `/ocs/v1.php/...`
- `/ocs/v2.php/...`
- `/remote.php/dav/files/<user>/...`
- `/remote.php/dav/uploads/<user>/...`

## 2. 导入 SQL

执行：

```sql
plugins/webdavNextcloud/sql/nextcloud_compat.mysql.sql
```

作用：

- 复用 `io_source_meta`，不新增业务表；
- 确保 `io_source_meta.key`、`sourceID_key` 索引存在；
- 初始化 `webdavEtag` 元数据，提升 Nextcloud 客户端增量扫描稳定性。

脚本已做索引存在性判断，可重复执行。

## 3. 配置 Nginx

以下 location 放在通用 PHP location 之前。`fastcgi_pass_request_body off` 只能用于 `/remote.php/dav/files/`，不要放到全局 PHP location。

```nginx
location ^~ /remote.php/dav/files/ {
    client_max_body_size 100G;
    client_body_in_file_only clean;
    fastcgi_request_buffering on;

    fastcgi_pass unix:/var/run/php-fpm.sock;
    fastcgi_index index.php;
    include fastcgi_params;

    fastcgi_param SCRIPT_FILENAME $document_root/index.php;
    fastcgi_param SCRIPT_NAME /index.php;
    fastcgi_param PATH_INFO "";

    fastcgi_param KOD_NGINX_BODY_FILE $request_body_file;
    fastcgi_param KOD_NGINX_BODY_SIZE $content_length;

    fastcgi_pass_request_body off;
    fastcgi_param CONTENT_LENGTH "";

    fastcgi_read_timeout 3600;
    fastcgi_send_timeout 3600;
}

location = /status.php {
    fastcgi_pass unix:/var/run/php-fpm.sock;
    fastcgi_index index.php;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/index.php;
    fastcgi_param SCRIPT_NAME /index.php;
    fastcgi_param PATH_INFO "";
}

location ^~ /ocs/ {
    fastcgi_pass unix:/var/run/php-fpm.sock;
    fastcgi_index index.php;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/index.php;
    fastcgi_param SCRIPT_NAME /index.php;
    fastcgi_param PATH_INFO "";
}

location ^~ /remote.php/ {
    fastcgi_pass unix:/var/run/php-fpm.sock;
    fastcgi_index index.php;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root/index.php;
    fastcgi_param SCRIPT_NAME /index.php;
    fastcgi_param PATH_INFO "";
}
```

HTTPS 或反向代理站点，以上 location 内建议额外传入：

```nginx
fastcgi_param HTTPS on;
fastcgi_param REQUEST_SCHEME https;
fastcgi_param HTTP_X_FORWARDED_PROTO https;
fastcgi_param HTTP_X_FORWARDED_HOST $host;
```

检查并重载：

```bash
nginx -t
nginx -s reload
```

同时重启 PHP-FPM 或清理 Opcache。

## 4. 验证

`/status.php` 必须返回 JSON，而不是 Kodbox 首页 HTML。

大文件上传启用诊断日志后，正常应看到：

```text
[NEXTCLOUD-COMPAT] nextcloud file put timing: ...;bodyFile=1;recv=0s;import=3.237s;total=3.245s
```

含义：

- `bodyFile=1`：PHP 直接使用 Nginx 已缓存的请求体文件；
- `recv=0s`：没有再从 `php://input` 慢读；
- `bodyFile=0`：Nginx 未传入 `KOD_NGINX_BODY_FILE`，上传 100% 后会明显等待。

## 5. 常见问题

`GET /status.php` 返回 `200 2443`：

- 返回的是 Kodbox 首页 HTML；
- 插件未启用、PHP 未重启、Opcache 未清理，或 Nginx 未转发到 `index.php`。

HTTPS 登录异常：

- 客户端访问 `https://`，但插件生成 `http://` 登录、轮询或 server URL；
- 常见原因是 Nginx/FastCGI 没传 `HTTPS on`、`REQUEST_SCHEME https`、`HTTP_X_FORWARDED_PROTO https`；
- 也要确认 Kodbox `APP_HOST` 配置为真实外部 HTTPS 地址。
