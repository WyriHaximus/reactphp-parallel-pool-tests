# set all to phony
SHELL=bash

.PHONY: *

ifneq ("$(wildcard /.dockerenv)","")
    DOCKER_RUN=
else
	DOCKER_RUN=docker run --rm -it \
		-v `pwd`:`pwd` \
		-w `pwd` \
		"wyrihaximusnet/php:7.4-zts-alpine3.10-dev"
endif

all: lint cs-fix cs stan psalm composer-require-checker composer-unused

lint:
	$(DOCKER_RUN) vendor/bin/parallel-lint --exclude vendor .

cs:
	$(DOCKER_RUN) vendor/bin/phpcs --parallel=$(nproc)

cs-fix:
	$(DOCKER_RUN) vendor/bin/phpcbf --parallel=$(nproc)

stan:
	$(DOCKER_RUN) vendor/bin/phpstan analyse src --level max --ansi -c phpstan.neon

psalm:
	$(DOCKER_RUN) vendor/bin/psalm --threads=$(nproc)

composer-require-checker:
	$(DOCKER_RUN) vendor/bin/composer-require-checker --ignore-parse-errors --ansi -vvv

composer-unused:
	$(DOCKER_RUN) composer unused --ansi
