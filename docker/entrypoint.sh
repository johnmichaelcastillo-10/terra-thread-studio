#!/bin/sh
# Prepares the bind-mounted data folders, installs and seeds the site on first run, then
# hands over to the stock PHP/Apache entrypoint.
set -e
cd /var/www/project

# Apache runs as www-data. On some hosts (Linux) bind mounts belong to the host user, so make
# only the data folders writable, and only when they aren't already.
for dir in database config logs public/wp-content/uploads; do
	mkdir -p "$dir"
	if ! su -s /bin/sh www-data -c "test -w '$dir'"; then
		chmod -R a+rwX "$dir"
	fi
done

if [ ! -f database/terra-thread.sqlite ]; then
	echo "First run: installing WordPress and seeding Terra & Thread (the admin password is printed below)..."
	su -s /bin/sh www-data -c "TT_URL='${TT_URL:-http://localhost:8090}' php bin/install.php"
fi

exec docker-php-entrypoint "$@"
