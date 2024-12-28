default: caddy
	php-fpm -D && caddy run --config ./Caddyfile

caddy: www
	mkdir -p /var/log/caddy
	curl -o /usr/local/bin/caddy "https://caddyserver.com/api/download?os=linux&arch=amd64" && chmod +x /usr/local/bin/caddy

www: blog

blog: wordpress
	sed -i '487s/;//' /usr/local/etc/php-fpm.d/www.conf

plugins:
	#!/usr/bin/env bash
	contentDir="wordpress/wp-content"
	standard_plugin_list=(simple-cloudflare-turnstile wp-statistics all-in-one-seo-pack featured-image-with-url google-site-kit)
	mkdir -p ${contentDir}/plugins
	function download_and_move() {
		if [ -d ${contentDir}/plugins/$2 ]; then
			return
		fi
		wget -O $2.zip $1
		unzip $2.zip
		rm -f $2.zip
		mv ${2}* ${contentDir}/plugins/$2
	}
	for plugin in ${standard_plugin_list[@]}; do
	    download_and_move https://downloads.wordpress.org/plugin/${plugin}.zip ${plugin}
	done
	
	# wp-statistics
	sed -i '19i \\treturn true;' ${contentDir}/plugins/wp-statistics/src/Service/Geolocation/Provider/CloudflareGeolocationProvider.php

	chmod -R 777 ${contentDir}/plugins

contentDir := "wordpress/wp-content"
themes:
	mkdir -p {{ contentDir }}/themes
	rm -rf {{ contentDir }}/themes/Sakurairo
	wget -O Sakurairo-preview.zip https://github.com/mirai-mamori/Sakurairo/archive/refs/heads/preview.zip
	unzip Sakurairo-preview.zip
	mv Sakurairo-preview {{ contentDir }}/themes/Sakurairo
	rm -f Sakurairo-preview.zip
	chmod -R 777 {{ contentDir }}/themes

wordpress: && plugins themes
	#!/usr/bin/env bash
	if [ ! -f "wordpress/index.php" ]; then
		wget -O wordpress.zip https://cn.wordpress.org/latest-zh_CN.zip
		unzip wordpress.zip
		rm -f wordpress.zip
		mkdir -p wordpress
		mv wp-config.php wordpress/
		mkdir -p wordpress/uploads/
		chmod -R 777 wordpress
	fi
	