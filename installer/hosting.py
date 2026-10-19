"""Bounded native web profiles; each generated site owns one hostname only."""
from pathlib import Path
import re
import shlex
import shutil
import subprocess


class WebServer:
    def __init__(self, kind, error):
        if kind not in ('nginx', 'apache2'):
            raise error('The saved web-server profile is unsupported; no website was changed.')
        self.kind, self.error = kind, error
        self.directory = Path('/etc') / kind
        self.service = kind
        self.check_command = ['apache2ctl', 'configtest'] if kind == 'apache2' else ['nginx', '-t']

    @classmethod
    def discover(cls, system, error):
        if system == ('debian', '13'):
            if shutil.which('apache2') or shutil.which('caddy'):
                raise error('Debian 13 provisioning supports Nginx. Existing Apache/Caddy was preserved; use the tested Ubuntu Apache profile or a separate supported VPS.')
            return cls('nginx', error)
        if system in (('ubuntu', '22.04'), ('ubuntu', '24.04')):
            # Installed or running software on other ports is not a conflict.
            # preflight separately proves every 80/443 socket belongs to Apache.
            return cls('apache2', error)
        raise error('Setup supports Debian 13 with Nginx, or Ubuntu 22.04/24.04 LTS with Apache, on amd64/systemd. This host is unsupported; nothing has been installed.')

    def configuration(self):
        command = ['apache2ctl', '-S'] if self.kind == 'apache2' else ['nginx', '-T']
        result = subprocess.run(command, capture_output=True, text=True)
        if result.returncode:
            raise self.error('The existing web-server configuration is invalid. Correct its configuration check before setup; no website was changed.')
        return result.stdout + result.stderr

    def check_layout(self, configuration):
        if self.kind == 'apache2':
            # IP-specific vhosts take precedence over a generated *:80/*:443
            # site even with a different ServerName. Do not silently shadow it.
            for address, port in re.findall(r'(?m)^\s*(\S+):(80|443|0|\*)\s+', configuration):
                if port in ('*', '0'):
                    raise self.error('Apache uses a wildcard-port website. Light requires explicit *:80 and *:443 virtual hosts. Existing sites were preserved; wildcard-port virtual hosts are outside both editions’ supported profiles.')
                if address != '*':
                    raise self.error(f'Apache has an address-specific website on {address}:{port}.\n'
                                     'Emailer Light supports only wildcard (*:80 and *:443) Apache virtual hosts.\n'
                                     'This server configuration requires Emailer Ripe with address-specific Apache support.\n'
                                     'Existing websites and saved setup progress were preserved. Use the supported Ripe setup package.\n'
                                     'Do not delete installation state or database files.')

    def names(self, configuration):
        if self.kind == 'apache2':
            # Apache's parsed inventory includes included files and aliases.
            return re.findall(r'(?:namevhost|alias|wild alias|default server)\s+([^\s(]+)', configuration) + re.findall(r'(?m)^\s*[^\s]+:(?:80|443)\s+([^\s(]+)\s+\(', configuration)
        return [name for declaration in re.findall(r'server_name\s+([^;]+);', configuration) for name in declaration.split()]

    def check_assignment(self, hostname, owned_site):
        # Parsed inventories include other files added while setup was interrupted.
        own = False
        foreign = []
        configuration = self.configuration()
        self.check_layout(configuration)
        for line in configuration.splitlines():
            if self.kind == 'apache2':
                source = re.search(r'\((/.*):[0-9]+\)', line)
            else:
                source = re.match(r'# configuration file (/.*):$', line)
            if source:
                own = Path(source.group(1)).resolve() == Path(owned_site).resolve()
            if not own:
                foreign.append(line)
        if self.conflicts(hostname, self.names('\n'.join(foreign))):
            raise self.error('The saved Emailer address is now assigned to another website. That website was preserved. Remove the conflicting hostname assignment or restore the original dedicated Emailer address, then retry this setup file.')

    @staticmethod
    def conflicts(host, names):
        import fnmatch
        return any(fnmatch.fnmatchcase(host, name.lower()) or (name.startswith('.') and (host == name[1:] or host.endswith(name))) for name in names)

    def modules(self, call):
        if self.kind == 'apache2':
            # No mod_php package, global FPM handler or MPM replacement.
            call(['a2enmod', 'ssl', 'proxy', 'proxy_fcgi', 'rewrite', 'headers'])

    def site(self, *, hostname, name, public, webroot=None, certificate=None, certificate_key=None, maintenance=False):
        if self.kind == 'nginx':
            return self.nginx_site(hostname, name, public, webroot, certificate, certificate_key, maintenance)
        challenge = '' if not webroot else f'''    Alias /.well-known/acme-challenge/ {webroot}/.well-known/acme-challenge/
    <Directory {webroot}/.well-known/acme-challenge>
        Options None
        AllowOverride None
        Require all granted
    </Directory>
'''
        redirect = f'    RewriteRule ^ https://{hostname}%{{REQUEST_URI}} [R=301,L,NE]' if certificate else '    RewriteRule ^ - [R=503,L]'
        http = f'''<VirtualHost *:80>
    ServerName {hostname}
    CustomLog /dev/null combined
    RewriteEngine On
{challenge}    RewriteCond %{{REQUEST_URI}} !^/\\.well-known/acme-challenge/
{redirect}
</VirtualHost>
'''
        if not certificate:
            return http
        hold = '''    RewriteEngine On
    RewriteCond %{CONN_REMOTE_ADDR} !^127\\.0\\.0\\.1$
    RewriteCond %{CONN_REMOTE_ADDR} !^::1$
    RewriteRule ^ - [R=503,L]
    ErrorDocument 503 "Emailer setup is finishing. Please try again shortly."
''' if maintenance else ''
        return http + f'''<VirtualHost *:443>
    ServerName {hostname}
    SSLEngine on
    SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1
    SSLCertificateFile {certificate}
    SSLCertificateKeyFile {certificate_key}
    DocumentRoot {public}
    CustomLog /dev/null combined
    RequestHeader unset Proxy early
    ProxyFCGISetEnvIf "true" HTTPS on
{hold}    <Directory {public}>
        Options -Indexes -Includes -ExecCGI -MultiViews +FollowSymLinks
        AllowOverride None
        Require all granted
        DirectoryIndex index.php
        RewriteEngine On
        RewriteRule "(^|/)\\." "-" [R=404,END]
        RewriteRule "\\.(env|log|sql|dump|backup|bak|key|pem|p12|pfx)$" "-" [R=404,END,NC]
        RewriteRule "^(?!index\\.php$).*(?i:\\.(?:php[0-9]?|phtml|phar))(?:[./]|$)" "-" [R=404,END]
        RewriteCond %{{REQUEST_FILENAME}} !-f
        RewriteRule ^ index.php [END]
        <Files index.php>
            SetHandler "proxy:unix:/run/{name}-fpm/php.sock|fcgi://localhost/"
        </Files>
    </Directory>
</VirtualHost>
'''

    @staticmethod
    def nginx_site(host, name, public, webroot, certificate, certificate_key, maintenance):
        challenge = '' if not webroot else f'    location ^~ /.well-known/acme-challenge/ {{ root {webroot}; }}\n'
        redirect = f'return 301 https://{host}$request_uri;' if certificate else 'return 503;'
        http = f'''server {{
    listen 80;
    server_name {host};
    access_log off;
{challenge}    location / {{ {redirect} }}
}}
'''
        if not certificate:
            return http
        restriction = 'allow 127.0.0.1; allow ::1; deny all;\n    error_page 403 =503 @installing;\n    location @installing { return 503 "Emailer setup is finishing. Please try again shortly."; }' if maintenance else ''
        return http + f'''server {{
    listen 443 ssl;
    server_name {host};
    ssl_certificate {certificate};
    ssl_certificate_key {certificate_key};
    root {public};
    index index.php;
    access_log off;
    {restriction}
    location = /index.php {{
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_param HTTP_PROXY "";
        fastcgi_param HTTPS on;
        fastcgi_param REQUEST_SCHEME https;
        fastcgi_pass unix:/run/{name}-fpm/php.sock;
    }}
    location / {{ try_files $uri /index.php$is_args$args; }}
    location ~ \\.php$ {{ return 404; }}
    location ~ (^|/)\\. {{ return 404; }}
    location ~* \\.(?:env|log|sql|dump|backup|bak|key|pem|p12|pfx)$ {{ return 404; }}
}}
'''

    @staticmethod
    def nginx_without_comments(text):
        # A hash in a quoted/escaped argument is data, not the beginning of a
        # comment. Keep real directives after it visible to adoption checks.
        result=[];quote=None;escaped=False;comment=False
        for char in text:
            if comment:
                if char=='\n': comment=False;result.append(char)
                continue
            if escaped: result.append(char);escaped=False;continue
            if char=='\\': result.append(char);escaped=True;continue
            if quote:
                result.append(char)
                if char==quote: quote=None
            elif char in ('"',"'"): quote=char;result.append(char)
            elif char=='#': comment=True
            else: result.append(char)
        return ''.join(result)

    def source_site(self, releases, hostname):
        sites=[]
        for enabled in (self.directory/'sites-enabled').iterdir():
            path=enabled.resolve()
            if not path.is_file() or path in [item[0] for item in sites]: continue
            text=path.read_text()
            root=str(releases/'current/public')
            directive=r'(?im)^\s*DocumentRoot\s+"?'+re.escape(root)+r'"?\s*$' if self.kind=='apache2' else r'\broot\s+'+re.escape(root)+r'\s*;'
            if not re.search(directive,text): continue
            if self.kind=='apache2':
                clean=re.sub(r'(?m)^\s*#.*$', '', text)
                blocks=re.findall(r'(?is)<VirtualHost\s+([^>]+)>(.*?)</VirtualHost\s*>',clean)
                outside=re.sub(r'(?is)<VirtualHost\s+[^>]+>.*?</VirtualHost\s*>','',clean).strip()
                listeners=[item[0].strip() for item in blocks]
                roots=re.findall(r'(?im)^\s*DocumentRoot\s+"?([^\s"]+)"?\s*$',clean)
                if outside or not blocks or len(set(listeners))!=len(listeners) or '*:443' not in listeners or any(value not in ('*:80','*:443') for value in listeners) or not roots or any(value!=root for value in roots) or re.search(r'(?im)^\s*(?:ProxyPass|ProxyPassMatch|ScriptAlias|AliasMatch|ScriptAliasMatch|Virtual(?:DocumentRoot|ScriptAlias)(?:IP)?)\b',clean):
                    raise self.error('The Apache file contains additional listeners, sites or document roots. It was preserved; automatic handoff requires one dedicated Emailer site on standard ports.')
                for rule in re.findall(r'(?im)^\s*RewriteRule\s+([^\n]+)',clean):
                    try:
                        parts=shlex.split(rule)
                    except ValueError:
                        parts=[]
                    allowed=(len(parts)==3 and (
                        (parts[1]=='-' and parts[2] in ('[R=503,L]','[R=404,END]','[R=404,END,NC]')) or
                        (parts[1]=='index.php' and parts[2]=='[END]') or
                        (parts[1]==f'https://{hostname}%{{REQUEST_URI}}' and parts[2]=='[R=301,L,NE]')))
                    if not allowed:
                        raise self.error('The Apache file contains custom rewrite routing. It was preserved; isolate Emailer into its dedicated site before automatic handoff.')
                files=re.findall(r'(?is)<Files\s+index\.php\s*>\s*(SetHandler\s+[^\n]+)\s*</Files\s*>',clean)
                remainder=re.sub(r'(?is)<Files\s+index\.php\s*>\s*SetHandler\s+[^\n]+\s*</Files\s*>','',clean)
                directories=re.findall(r'(?im)^\s*<Directory\s+([^>]+)>',clean)
                if re.search(r'(?im)^\s*(?:SetHandler|AddHandler|Action|Redirect|RedirectMatch|ProxyPassReverse)\b|<\s*(?:Location|LocationMatch|FilesMatch|Proxy)\b',remainder) or len(files)>1 or any(not re.fullmatch(r'SetHandler\s+"proxy:unix:/run/[A-Za-z0-9_.-]+-fpm/php\.sock\|fcgi://localhost/"',value.strip()) for value in files) or any(value.strip('" ')!=root and not re.fullmatch(r'/[A-Za-z0-9_./-]+/\.well-known/acme-challenge',value.strip('" ')) for value in directories):
                    raise self.error('The Apache file contains custom handlers or directory routing. It was preserved; automatic handoff requires a dedicated Emailer front controller.')
                aliases=re.findall(r'(?im)^\s*Alias\s+([^\n]+)',clean)
                if any(not re.fullmatch(r'/\.well-known/acme-challenge/\s+/[A-Za-z0-9_./-]+/\.well-known/acme-challenge/',alias.strip()) for alias in aliases):
                    raise self.error('The Apache file also serves an unrelated alias. It was preserved; isolate Emailer into its dedicated site before using this profile.')
                names=re.findall(r'(?im)^\s*ServerName\s+([^\s]+)\s*$',text)
                if re.search(r'(?im)^\s*(ServerAlias|Include|IncludeOptional)\b',text) or not names or any(name!=hostname for name in names):
                    raise self.error('The Emailer Apache file also contains aliases, includes or another website name. Keep its sites unchanged; this profile needs one dedicated Emailer hostname file.')
                cert=re.findall(r'(?im)^\s*SSLCertificateFile\s+(/[A-Za-z0-9_./-]+)\s*$',text)
                key=re.findall(r'(?im)^\s*SSLCertificateKeyFile\s+(/[A-Za-z0-9_./-]+)\s*$',text)
                acme=re.findall(r'(?im)^\s*Alias\s+/\.well-known/acme-challenge/\s+(/[A-Za-z0-9_./-]+)/\.well-known/acme-challenge/\s*$',text)
            else:
                clean=self.nginx_without_comments(text)
                text=clean
                includes=re.findall(r'\binclude\s+([^;]+);',clean)
                if any(value.strip()!='fastcgi_params' for value in includes) or re.search(r'\b(?:proxy_pass|grpc_pass|uwsgi_pass|scgi_pass|memcached_pass|rewrite)\s+',clean):
                    raise self.error('The Nginx file contains custom included or proxy routing. It was preserved; isolate Emailer into its dedicated site before automatic handoff.')
                controllers=re.findall(r'location\s+=\s+/index\.php\s*\{([^{}]*)\}',clean)
                remainder=re.sub(r'location\s+=\s+/index\.php\s*\{[^{}]*\}','',clean)
                if len(controllers)!=1 or re.search(r'\bfastcgi_(?:pass|param|store|split_path_info)\s+',remainder) or re.findall(r'\bfastcgi_param\s+SCRIPT_FILENAME\s+([^;]+);',controllers[0])!=['$document_root/index.php'] or len(re.findall(r'\bfastcgi_pass\s+unix:/run/[A-Za-z0-9_./-]+\.sock\s*;',controllers[0]))!=1:
                    raise self.error('The Nginx file contains custom FastCGI routing. It was preserved; automatic handoff requires only the dedicated Emailer index.php handler.')
                names=re.findall(r'\bserver_name\s+([^;]+);',text)
                if not names or any(name.strip()!=hostname for name in names):
                    raise self.error('The Emailer Nginx file also contains other website names. Keep its sites unchanged; this profile needs one dedicated Emailer hostname file.')
                cert=re.findall(r'\bssl_certificate\s+(/[A-Za-z0-9_./-]+);',text)
                key=re.findall(r'\bssl_certificate_key\s+(/[A-Za-z0-9_./-]+);',text)
                acme=re.findall(r'location\s+\^~\s+/\.well-known/acme-challenge/\s*\{\s*root\s+(/[A-Za-z0-9_./-]+);\s*\}',text)
                roots=re.findall(r'\broot\s+([^;]+);',text)
                listeners=re.findall(r'\blisten\s+([^;]+);',text)
                if any(value.strip() not in [root,*acme] for value in roots) or any(not re.fullmatch(r'(?:\[::\]:)?(?:80|443)(?:\s+(?:ssl|http2|default_server))*',value.strip()) for value in listeners) or re.search(r'\balias\s+',text):
                    raise self.error('The Nginx file contains another document root, listener or alias. It was preserved; automatic handoff requires one dedicated Emailer site.')
            if len(set(cert))!=1 or len(set(key))!=1 or len(set(acme))>1:
                raise self.error('The dedicated Emailer site needs one existing trusted HTTPS certificate and an unambiguous renewal webroot. Its configuration was preserved.')
            sites.append((path,cert[0],key[0],acme[0] if acme else None))
        if len(sites)!=1:
            raise self.error('Setup could not identify exactly one dedicated Emailer HTTPS website. No web server was changed.')
        return sites[0]
