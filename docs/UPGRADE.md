# Upgrade

If installation has been done by git, which is the recommended way, then upgrade should
be quite easy.

First verify that you're on correct origin:
```
[rqwatch]$ git remote -v
origin	https://github.com/bilias/rqwatch/ (fetch)
origin	https://github.com/bilias/rqwatch/ (push)
```

## Update to [latest Rqwatch release](https://github.com/bilias/rqwatch/releases/latest)
```
su - rqwatch -s /bin/bash

cd /var/www/html/rqwatch/

git fetch --tags origin

# Get latest release tagged version
git describe --tags --abbrev=0

git checkout v1.8.3

# upgrade dependencies
composer install

# verify php modules
composer check-platform-reqs

# Check and perform Database updates
./bin/cli.php db:migrate

# needed if you have run it in the past
composer dump-autoload
```

Your should also read Release Instructions carefully for important changes.
