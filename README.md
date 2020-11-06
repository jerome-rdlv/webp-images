# WebP Images

This plugin only creates WebP images. To actually serve those you can
add the following in your .htaccess:

```apacheconfig
# WebP
AddType image/webp .webp
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{HTTP_ACCEPT} image/webp
    RewriteCond %{REQUEST_URI} (?i)(.*)\.(jpe?g|png)$
    RewriteCond %{DOCUMENT_ROOT}%1.webp -f
    RewriteRule (?i)(.*)\.(jpe?g|png)$ %1.webp [NC,T=image/webp,L]
</IfModule>
<IfModule mod_headers.c>
    <If "%{REQUEST_URI} =~ m#\.(jpe?g|png)$#">
        Header append Vary Accept
    </If>
</IfModule>
```
