# Terra & Thread Studio: WordPress on SQLite in one Apache + PHP container.
#
# WordPress core and the SQLite Database Integration plugin are baked into the image (fast:
# no Windows bind mount for thousands of core files). The theme, the core plugin, uploads,
# the SQLite database, salts and logs are bind-mounted from the repo by docker-compose.yml,
# so the containerised site and the host tooling (bin/publish.ps1) share the same data.
FROM php:8.5-apache

ARG WP_VERSION=7.1.3
ARG SQLITE_VERSION=3.1.0

# GD (with JPEG/WebP) for image sizes; unzip to unpack WordPress and the plugin. PHP 8.5
# already includes OPcache, curl, mbstring and pdo_sqlite. GD is built without -j: parallel
# make races on libgd/.libs.
RUN apt-get update \
	&& apt-get install -y --no-install-recommends unzip libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype-dev \
	&& docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
	&& docker-php-ext-install gd exif \
	&& rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite headers

WORKDIR /var/www/project

# WordPress core in public/wordpress (its bundled wp-content is unused: WP_CONTENT_DIR is
# public/wp-content), the SQLite plugin, and the db.php drop-in made from its template.
RUN curl -fsSL "https://wordpress.org/wordpress-${WP_VERSION}.zip" -o /tmp/wp.zip \
	&& mkdir -p public/wp-content/plugins public/wp-content/themes \
	&& unzip -q /tmp/wp.zip -d public \
	&& rm -rf public/wordpress/wp-content /tmp/wp.zip \
	&& curl -fsSL "https://downloads.wordpress.org/plugin/sqlite-database-integration.${SQLITE_VERSION}.zip" -o /tmp/sqlite.zip \
	&& unzip -q /tmp/sqlite.zip -d public/wp-content/plugins \
	&& rm /tmp/sqlite.zip \
	&& sed 's#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#g' \
		public/wp-content/plugins/sqlite-database-integration/db.copy > public/wp-content/db.php \
	&& chown -R www-data:www-data public

COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/terra-thread.ini
COPY docker/entrypoint.sh /usr/local/bin/tt-entrypoint
RUN chmod +x /usr/local/bin/tt-entrypoint

ENTRYPOINT ["tt-entrypoint"]
CMD ["apache2-foreground"]
