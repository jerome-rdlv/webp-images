# WebP Images

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
