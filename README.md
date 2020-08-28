# WebP Images

You need to install `cwebp` locally:

```shell script
wget -c https://storage.googleapis.com/downloads.webmproject.org/releases/webp/libwebp-1.1.0-linux-x86-64.tar.gz -O - | tar -zxOvf - libwebp-1.1.0-linux-x86-64/bin/cwebp > cwebp
chmod +x cwebp
``` 

This plugin only creates WebP images. To actually serve those you can
add the following in your .htaccess:

```apacheconfig
# WebP
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{HTTP_ACCEPT} image/webp
    RewriteCond %{REQUEST_URI}  (?i)(.*)(\.jpe?g|\.png)$
    RewriteCond %{DOCUMENT_ROOT}%1.webp -f
    RewriteRule (?i)(.*)(\.jpe?g|\.png)$ %1\.webp [NC,T=image/webp,E=webp,L]
</IfModule>
<IfModule mod_headers.c>
    Header append Vary Accept env=REDIRECT_webp
</IfModule>
AddType image/webp .webp
```
