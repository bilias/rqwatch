# Upgrade

If installation has been done by git, which is the recommended way, then upgrade should
be quite easy.

First verify that you're on correct origin:
```
[rqwatch]$ git remote -v
origin	https://github.com/bilias/rqwatch/ (fetch)
origin	https://github.com/bilias/rqwatch/ (push)
```

If you're on `master` branch:
```
[rqwatch]$ git branch
* master

# Perform the upgrade:
[rqwatch]$ git pull

# upgrade dependencies
[rqwatch]$ composer install

# needed if you have run it in the past
[rqwatch]$ composer dump-autoload
```

If you are on a Release tag then:
```
[rqwatch]$ git fetch origin

[rqwatch]$ git checkout v1.7.3

# upgrade dependencies
[rqwatch]$ composer install

# needed if you have run it in the past
[rqwatch]$ composer dump-autoload
```

Your should also read Release Instructions carefully for important changes.
