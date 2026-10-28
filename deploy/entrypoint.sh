#!/bin/sh
#
# Startskript des Anwendungscontainers. Laeuft bei jedem Start, also auch bei
# jedem Update.
set -eu

warte_auf_datenbank() {
	versuch=0

	until php -r '
		new PDO(
			"pgsql:host=" . getenv("DB_HOST")
				. ";port=" . (getenv("DB_PORT") ?: "5432")
				. ";dbname=" . getenv("DB_DATABASE"),
			getenv("DB_USERNAME"),
			getenv("DB_PASSWORD")
		);
	' 2>/dev/null; do
		versuch=$((versuch + 1))

		if [ "$versuch" -ge 60 ]; then
			echo "Datenbank nach 60 Sekunden nicht erreichbar, Abbruch." >&2
			exit 1
		fi

		sleep 1
	done
}

# Die Volumes werden leer gemountet, die Verzeichnisse muessen also bei jedem
# Start vorhanden sein, nicht nur im Image.
mkdir -p storage/app/private storage/logs \
	storage/framework/cache/data storage/framework/sessions storage/framework/views \
	bootstrap/cache

warte_auf_datenbank

php artisan migrate --force

# Erst hier, nicht im Build: config:cache friert die Umgebung ein, in der es
# laeuft. Im Build waeren das die Buildzeit-Werte, und APP_KEY sowie die
# DB-Zugangsdaten aus /etc/learn/app.env wuerden zur Laufzeit ignoriert.
php artisan optimize

exec "$@"
