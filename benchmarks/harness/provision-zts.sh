#!/bin/bash
# Build PHP 8.4.27 ZTS for the field test into /opt/rocketship-php/8.4-zts (run as forge; needs /opt/rocketship-php
# owned by forge and the -dev libraries: libxml2 libssl libsqlite3 libcurl4-openssl libonig libzip libsodium libreadline
# zlib1g). No ZTS package exists, so PHP is built from the release tarball, with the stock CLI php.ini and the modules
# a Laravel app uses. Same layout as the NTS build: conf.d holds what every cell loads, toggles/apcu and
# toggles/swoole are added through PHP_INI_SCAN_DIR. The stock PHP's files are read, never written.
set -euo pipefail
V=8.4.27; F=~/rocketship-field; b=/opt/rocketship-php/8.4-zts; src=$F/src/zts
mkdir -p $src; cd $src
[ -d php-$V ] || { curl -sL https://www.php.net/distributions/php-$V.tar.xz | tar xJ; }
for p in parallel-1.2.15 apcu-5.1.28 swoole-6.2.3; do [ -d $p ] || curl -sL https://pecl.php.net/get/$p.tgz | tar xz; done
for ext in epoll posi; do [ -d $ext ] || git clone -q --depth 1 --branch v0.10.6 https://github.com/php-io-extensions/$ext.git $ext 2>/dev/null; done

rm -rf "$b"; mkdir -p "$b/etc" "$b/conf.d" "$b/toggles/apcu" "$b/toggles/swoole"
cd $src/php-$V
make distclean > /dev/null 2>&1 || true
./configure --prefix=$b --with-config-file-path=$b/etc --with-config-file-scan-dir=$b/conf.d --enable-zts \
  --disable-cgi --enable-bcmath --enable-calendar --enable-exif --enable-mbstring --enable-pcntl --enable-sockets \
  --enable-mysqlnd --with-mysqli=mysqlnd --with-pdo-mysql=mysqlnd --with-pdo-sqlite --with-sqlite3 \
  --with-openssl --with-curl --with-zlib --with-zip --with-sodium --with-readline > $F/build/zts-php-configure.log 2>&1
make -j2 > $F/build/zts-php-make.log 2>&1 || { echo "php FAILED (see $F/build/zts-php-make.log)"; exit 1; }
make install > $F/build/zts-php-install.log 2>&1
echo "php built: $($b/bin/php -v | head -1)"

pecl_build() { # dir name [configure flags...]
  local d=$1 name=$2; shift 2
  cd $src/$d; make distclean > /dev/null 2>&1 || true
  $b/bin/phpize > $F/build/zts-$name-phpize.log 2>&1
  ./configure --with-php-config=$b/bin/php-config "$@" > $F/build/zts-$name-configure.log 2>&1
  make -j2 > $F/build/zts-$name-make.log 2>&1 || { echo "$name FAILED (see $F/build/zts-$name-make.log)"; exit 1; }
  make install > /dev/null; echo "$name built"
}
pecl_build epoll epoll
pecl_build posi posi
pecl_build parallel-1.2.15 parallel
pecl_build apcu-5.1.28 apcu
pecl_build swoole-6.2.3 swoole --enable-openssl --enable-sockets --enable-mysqlnd --enable-swoole-curl

cp /etc/php/8.4/cli/php.ini $b/etc/php.ini
printf 'zend_extension=opcache\nopcache.enable=1\nopcache.enable_cli=1\nopcache.jit=disable\n' > "$b/conf.d/90-field-opcache.ini"
for ext in epoll posi parallel; do echo "extension=$ext" > "$b/conf.d/90-field-$ext.ini"; done
printf 'extension=apcu\napc.enable_cli=1\napc.use_request_time=0\n' > "$b/toggles/apcu/zz-field-apcu.ini"
echo 'extension=swoole' > "$b/toggles/swoole/swoole.ini"
echo "modules: $($b/bin/php -m | tr '\n' ' ')"
echo provisioned
