# Panini Trading app

A barebones PHP app that makes use of the [Silex](http://silex.sensiolabs.org/) web framework, which can easily be deployed to Heroku.

This application supports the [Getting Started with PHP on Heroku](https://devcenter.heroku.com/articles/getting-started-with-php) article - check it out.

## Running Locally

Make sure you have PHP, Apache and Composer installed.  Also, install the [Heroku Toolbelt](https://toolbelt.heroku.com/) and php_pdo_pgsql.

```sh
$ git clone git@github.com:WEForum/panini-trading.git # or clone your own fork
$ cd panini-trading
$ composer update
```

Your app should now be running on [localhost/panini-trading/public/](http://localhost/panini-trading/public/).

## Deploying to Heroku

```
$ heroku create
$ git push heroku master
$ heroku open
```

## Documentation

For more information about this poject:

- [PHP on Heroku](https://devcenter.heroku.com/categories/php)
