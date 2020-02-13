# Setup email forwarding for account emails
# -----------------------------------------

echo -e "bastian@getkirby.com\nlukas@getkirby.com" > ~/.qmail

# Configure the web server
# ------------------------

uberspace web domain add trykirby.com
uberspace tools version use php 7.4
uberspace web log php_error enable

# Install Composer
# ----------------

EXPECTED_SIGNATURE="$(wget -q -O - https://composer.github.io/installer.sig)"
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
ACTUAL_SIGNATURE="$(php -r "echo hash_file('sha384', 'composer-setup.php');")"

if [ "$EXPECTED_SIGNATURE" != "$ACTUAL_SIGNATURE" ]
then
    >&2 echo 'ERROR: Invalid Composer installer signature'
    rm composer-setup.php
    exit 1
fi

php composer-setup.php --quiet --install-dir="$HOME/bin" --filename="composer"
rm composer-setup.php

# Setup the Demo tool
# -------------------

git clone https://github.com/getkirby/demo-manager /var/www/virtual/$USER/demo
rmdir /var/www/virtual/$USER/html
ln -s /var/www/virtual/$USER/demo/public /var/www/virtual/$USER/html

mkdir /var/www/virtual/$USER/demo/public/_media

# Setup cronjobs
# --------------

# Clean up instances every 10 minutes, prepare every 5 minutes,
# collect stats twice an hour, clean up media daily
cron="MAILTO=\"lukas@getkirby.com\"
*/10 * * * * /var/www/virtual/$USER/demo/bin/demo_cleanup
* * * * * /var/www/virtual/$USER/demo/bin/demo_prepare
28,58 * * * * /var/www/virtual/$USER/demo/bin/demo_stats --csv >> /var/www/virtual/$USER/demo/data/stats.csv
12 2 * * * /var/www/virtual/$USER/demo/data/template/bin/cleanup"
(crontab -l; echo "$cron") | crontab -

# Helpful aliases
# ---------------

ln -s /var/www/virtual/$USER/demo ~/demo
echo 'export EDITOR="nano"' >> ~/.bashrc
echo 'export PATH="$PATH:/var/www/virtual/$USER/demo/bin"' >> ~/.bashrc