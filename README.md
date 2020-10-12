# Database Changes Finder

DatabaseChangesFinder is an application that measures changes from a specific point in time based on statistical information of a database and obtains differential data.
The information is predictive from statistics and table column naming conventions. Please use it as a reference value.

**It is specific to PostgreSQL statistics and is not available for other databases.**

## Setup

### environment variable

Set the connection information for the database used by Database Changes Finder in an environment variable.
You should be able to connect from within the docker environment.

```shell script
$ export DATABASE_URL=pgsql://user:pass@host_name:5432/database_name
```

### docker build

```shell script
$ docker-compose build
$ docker-compose up -d
```

### composer install

```shell script
$ docker-compose run phpcli74 composer install
```

## Usage

### make snapshot data

Save preoperational database statistics to a file

```shell script
php DatabaseChangesFinder.php start transactionKey
```

The transactionKey is a string used to identify the start and end pairs. As it is used as part of the file name, it will not work if you try to use characters that are not available in the file name.
The file dcf.transactionKey.json is created after the command is executed.

### get changes from snapshot

Acquire the difference of statistical information before and after the operation, and estimate and extract the updated data in the database based on the difference.

```shell script
php DatabaseChangesFinder.php end transactionKey
```


### example

```
$ docker-compose run phpcli74 php DatabaseChangesFinder.php start 202010111200
:

File dcf.202010111200.json is created.

:

some kind of operations
ex. exec login action

:

$ docker-compose run phpcli74 php DatabaseChangesFinder.php end 202010111200
{
    "accounts": {
        "update_count": 1,
        "data": [
            {
                "id": 193,
                "family_name": "Uno",
                "first_name": "Kazuhiko",
                "last_login": "2020-10-11 21:56:15",
                "created": "2020-10-10 08:50:10",
                "creator": 1,
                "updated": "2020-10-11 21:56:15",
                "updater": 193,
                "deleted": null,
                "deleter": null,
            }
        ]
    },
    "login_logs": {
        "insert_count": 1,
        "data": [
            {
                "id": 100,
                "account_id": 193,
                "created": "2020-10-11 21:56:15",
                "creator": 193,
            }
        ]
    }
}
```

## TODO

- Refactoring code
- Add unit tests.


