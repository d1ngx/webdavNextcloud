# Kodbox Nextcloud Desktop 兼容同步插件

这是独立于旧版 WebDAV 插件的新插件，只处理 Nextcloud Desktop 同步客户端需要的兼容入口：

- `/status.php`
- `/ocs/v1.php/...`
- `/ocs/v2.php/...`
- `/remote.php/dav/files/<user>/...`
- `/remote.php/dav/uploads/<user>/...`

旧版 WebDAV 插件仍可继续负责普通 WebDAV 挂载、网络驱动器和第三方 WebDAV 存储挂载。本插件启用后，旧版 WebDAV 插件会对上述 Nextcloud 兼容入口让路，由本插件优先处理。

## Nginx 推荐配置

将以下 location 放在通用 `location ~ [^/]\.php(/|$)` 之前。大文件直传加速只应放在 `/remote.php/dav/files/`，不要放到通用 PHP location。

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

通用 PHP location 中不要配置以下几项，否则普通 POST 表单和 API 会拿不到请求体：

```nginx
client_body_in_file_only clean;
fastcgi_param KOD_NGINX_BODY_FILE $request_body_file;
fastcgi_param KOD_NGINX_BODY_SIZE $content_length;
fastcgi_pass_request_body off;
fastcgi_param CONTENT_LENGTH "";
```

修改后执行：

```bash
nginx -t
nginx -s reload
```

## 日志判断

插件设置里打开“Nextcloud兼容诊断日志”后，正常的大文件上传日志应类似：

```text
[NEXTCLOUD-COMPAT] nextcloud file put using nginx body file: file=/var/lib/nginx/tmp/client_body/0000000014;size=973770666;expect=973770666
[NEXTCLOUD-COMPAT] nextcloud file put timing: path={source:110}/;size=973770666;tmpDir=/var/lib/nginx/tmp/client_body/;bodyFile=1;recv=0s;import=3.237s;total=3.245s
```

`bodyFile=1`、`recv=0s` 表示 PHP 直接使用 Nginx 已缓存好的请求体文件。若出现 `bodyFile=0`，说明 Nginx 没有把 `KOD_NGINX_BODY_FILE` 传给 PHP，上传完成后的 100% 等待会明显变长。

## 登录失败排查

Nextcloud Desktop 连接服务端时，第一步会请求 `/status.php`。正常响应是 JSON，体积通常很小；如果 Nginx 日志里看到：

```text
GET /status.php HTTP/1.1" 200 2443
```

通常表示返回的是 Kodbox 首页 HTML，而不是 Nextcloud status JSON。这说明插件路由没有接管请求，需要确认：

- `webdavNextcloud` 插件已启用；
- Nginx 的 `/status.php` 已转发到 Kodbox `index.php`，或根目录 `status.php` 内容为 `include(dirname(__FILE__).'/index.php');`；
- 修改插件后已重启 PHP-FPM 或清理 Opcache。
