# DiffCS

A tool to perform code sniffer checks of your pull requests on Github.

## How To Install

You can grab a copy of marcelsud/diffcs in either of the following ways:

### As a phar (recommended)

You can simply download a pre-compiled and ready-to-use version as a Phar to any directory. Simply download the latest diffcs.phar file from our [releases page](https://github.com/marcelsud/diffcs/releases):

```
curl -LO https://github.com/marcelsud/diffcs/releases/download/v0.2.1/diffcs.phar
php diffcs.phar --help
```

Optionally you can install it globally by adding it to your bin folder:

```
chmod +x diffcs.phar
mv diffcs.phar /usr/local/bin/diffcs
```

### Via composer:

```
composer global require "marcelsud/diffcs":"dev-master"
sudo ln -nfs ~/.composer/vendor/bin/diffcs /usr/local/bin/diffcs
```

### Via docker:

```
docker run --rm -it marcelsud/diffcs --help
```

## How To Use

### For public repositories:

Run the following command: `diffcs <source>/<project> <pull request id>`, where:

- `<source>` is the corporation/user behind the project;
- `<project>` is the project name on Github;
- `<pull request id>` is the pull request id, created by Github.

**Example**:

```
diffcs symfony/symfony 13342
```

### For private repositories:

#### Authenticate with username and password
Execute following command: `diffcs <source>/<project> <pull request id> --github-user=<github username>`, where:

- `<github username>` is your Github username.
- the password will be asked afterwards and is only required check private repositories.

**Example**:

```
diffcs symfony/symfony 13342 --github-user=yourusername
```

#### Authenticate with Github token
Execute following command: `diffcs <source>/<project> <pull request id> --github-token=<github token>`, where:

- you can generate the `<github token>` in [your Github account settings](https://github.com/settings/tokens/new?scopes=repo&description=Diffcs%20token)).

**Example**:

```
diffcs symfony/symfony 13342 --github-token=256199c24f9132f84e9bb06271ff65a3176a2f05
```

![Image](output.png)
