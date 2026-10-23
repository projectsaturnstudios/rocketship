#!/bin/bash
# Provision PHP 8.4 NTS for the field test (run as forge; needs /opt/rocketship-php owned by forge and php8.4-dev,
# php8.4-apcu, php8.4-swoole installed with apcu disabled in the stock PHP). Stock files are read, never written.
set -euo pipefail
F=~/rocketship-field; mkdir -p $F/src $F/build
cd $F/src
[ -d epoll ] || git clone -q --depth 1 --branch v0.10.6 https://github.com/php-io-extensions/epoll.git epoll 2>/dev/null
[ -d posi ] || git clone -q --depth 1 --branch v0.10.6 https://github.com/php-io-extensions/posi.git posi 2>/dev/null
b=/opt/rocketship-php/8.4-nts
rm -rf "$b"/*; mkdir -p "$b/ext" "$b/conf.d" "$b/toggles/apcu" "$b/toggles/swoole"
for ext in epoll posi; do
  d=$F/build/8.4-nts-$ext; rm -rf "$d"; cp -a "$F/src/$ext" "$d"; cd "$d"
  phpize8.4 > phpize.log 2>&1
  ./configure --with-php-config=php-config8.4 > configure.log 2>&1
  make -j2 > make.log 2>&1 || { echo "$ext FAILED (see $d/make.log)"; exit 1; }
  cp modules/$ext.so "$b/ext/"; echo "$ext built"
done
for f in /etc/php/8.4/cli/conf.d/*.ini; do
  case $(basename "$f") in *apcu*|*swoole*) continue ;; esac
  cp "$f" "$b/conf.d/"
done
printf 'opcache.enable=1\nopcache.enable_cli=1\nopcache.jit=disable\n' > "$b/conf.d/90-field-opcache.ini"
echo "extension=$b/ext/epoll.so" > "$b/conf.d/90-field-epoll.ini"
echo "extension=$b/ext/posi.so" > "$b/conf.d/90-field-posi.ini"
cp /etc/php/8.4/mods-available/apcu.ini "$b/toggles/apcu/"
printf 'apc.enable_cli=1\napc.use_request_time=0\n' > "$b/toggles/apcu/zz-field-apcu.ini"
cp /etc/php/8.4/mods-available/swoole.ini "$b/toggles/swoole/"
cd $F; if [ ! -x wrk/wrk ]; then git clone -q --depth 1 --branch 4.2.0 https://github.com/wg/wrk.git wrk 2>/dev/null; (cd wrk && make -j2 > make.log 2>&1); fi
echo "wrk: $(wrk/wrk --version 2>&1 | head -1)"
echo provisioned
