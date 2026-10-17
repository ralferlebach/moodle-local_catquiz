# Local checks before a push - the ones the GitHub CI runs, without the CI (local_catquiz #135 and others: "make check").
#
#   make check MOODLE=/path/to/moodle [PHPCS=/path/to/phpcs]
#
# MOODLE is a Moodle tree with this plugin installed and PHPUnit initialised; "make sync" copies this working tree
# into it first. Behat is not part of "check" (it needs a browser): "make behat BEHAT_CONFIG=...".

MOODLE ?= $(HOME)/moodle
PLUGINDIR := local/catquiz
PHPCS ?= phpcs
PHPCSFLAGS := --standard=moodle --severity=1 --extensions=php \
	--exclude=PSR1.Classes.ClassDeclaration,moodle.Commenting.TodoComment \
	--ignore=*/node_modules/*,*/vendor/*,*/catquizcentralhub/*
FEATURES ?= tests/behat

.PHONY: check lint phpcs phpunit sync behat

check: lint phpcs phpunit

lint:
	@find . -name '*.php' -not -path './node_modules/*' -not -path './vendor/*' -not -path './catquizcentralhub/*' -print0 \
		| xargs -0 -n 50 -P 4 php -l > /dev/null
	@echo "lint: ok"

phpcs:
	$(PHPCS) $(PHPCSFLAGS) .

sync:
	rsync -a --delete --exclude .git --exclude node_modules ./ $(MOODLE)/$(PLUGINDIR)/

phpunit:
	cd $(MOODLE) && vendor/bin/phpunit --testsuite local_catquiz_testsuite

behat:
	cd $(MOODLE) && vendor/bin/behat --config $(BEHAT_CONFIG) --profile=chrome $(PLUGINDIR)/$(FEATURES)
