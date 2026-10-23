#!/bin/bash
# Create the two field-test apps on the server: octane-app (laravel/octane) and rocketship-app (this repo, as a
# path repository at ~/rocketship-field/rocketship). Needs MySQL with database and user rocketship_field, whose
# password comes from FIELD_DB_PASSWORD:  FIELD_DB_PASSWORD=… setup-apps.sh
set -euo pipefail
DBPASS=${FIELD_DB_PASSWORD:?set FIELD_DB_PASSWORD to the rocketship_field MySQL password}
F=~/rocketship-field; B=/opt/rocketship-php/8.4-nts
export PHP_INI_SCAN_DIR=$B/conf.d
for app in octane-app rocketship-app; do
  rm -rf $F/$app
  php8.4 /usr/local/bin/composer create-project -q --no-interaction laravel/laravel $F/$app
  cd $F/$app
  if [ $app = octane-app ]; then
    php8.4 /usr/local/bin/composer require -q --no-interaction laravel/octane:2.22.0
  else
    php8.4 /usr/local/bin/composer config repositories.rocketship '{"type": "path", "url": "'$F'/rocketship", "options": {"symlink": false}}'
    php8.4 /usr/local/bin/composer require -q --no-interaction 'projectsaturnstudios/rocketship:*@dev'
  fi
  cp $F/harness/bench.php routes/bench.php
  cp $F/harness/bench.blade.php resources/views/bench.blade.php
  # Load routes/bench.php without the web middleware: no session or cookie per request.
  python3 - <<PY
p='bootstrap/app.php'; s=open(p).read()
old="health: '/up',"
assert old in s
s=s.replace(old, old+"\n        then: function () {\n            Illuminate\\\\Support\\\\Facades\\\\Route::group([], base_path('routes/bench.php'));\n        },")
open(p,'w').write(s)
PY
  sed -i -e 's/^APP_ENV=.*/APP_ENV=production/' -e 's/^APP_DEBUG=.*/APP_DEBUG=false/' \
    -e 's/^DB_CONNECTION=.*/DB_CONNECTION=mysql/' -e 's/^# *DB_HOST=.*/DB_HOST=127.0.0.1/' -e 's/^# *DB_PORT=.*/DB_PORT=3306/' \
    -e 's/^# *DB_DATABASE=.*/DB_DATABASE=rocketship_field/' -e 's/^# *DB_USERNAME=.*/DB_USERNAME=rocketship_field/' \
    -e "s/^# *DB_PASSWORD=.*/DB_PASSWORD=$DBPASS/" \
    -e 's/^SESSION_DRIVER=.*/SESSION_DRIVER=file/' -e 's/^CACHE_STORE=.*/CACHE_STORE=file/' -e 's/^QUEUE_CONNECTION=.*/QUEUE_CONNECTION=sync/' -e 's/^LOG_LEVEL=.*/LOG_LEVEL=error/' .env
  echo "$app: $(grep -E '^(APP_ENV|APP_DEBUG|DB_CONNECTION|DB_DATABASE|SESSION_DRIVER|CACHE_STORE)=' .env | tr '\n' ' ')"
done
cd $F/octane-app
php8.4 artisan octane:install --server=roadrunner -n > /dev/null 2>&1 || echo "roadrunner install: check"
php8.4 artisan octane:install --server=frankenphp -n > /dev/null 2>&1 || echo "frankenphp install: check"
ls -la rr frankenphp 2>&1 | awk '{print $NF, $5}'
MYSQL_PWD=$DBPASS mysql -urocketship_field rocketship_field 2>/dev/null <<SQL
DROP TABLE IF EXISTS items;
CREATE TABLE items (id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT, name VARCHAR(100) NOT NULL, payload CHAR(200) NOT NULL);
SET SESSION cte_max_recursion_depth = 10000;
INSERT INTO items (name, payload) WITH RECURSIVE n(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < 10000) SELECT CONCAT('item ', i), RPAD(SHA2(i, 256), 200, 'x') FROM n;
SELECT COUNT(*) AS items FROM items;
SQL
for app in octane-app rocketship-app; do
  cd $F/$app && php8.4 artisan config:cache -q && php8.4 artisan route:cache -q && php8.4 artisan view:cache -q
  echo "$app routes: $(php8.4 artisan route:list --path=plain --json 2>/dev/null | head -c 80)"
done
echo SETUP DONE
