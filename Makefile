# Local checks before a push - the ones the GitHub CI runs, without the CI (local_catquiz #135 and others: "make check").
#
#   make check MOODLE=/path/to/moodle [PHPCS=/path/to/phpcs]
#
# MOODLE is a Moodle tree with this plugin installed and PHPUnit initialised; "make sync" copies this working tree
# into it first. Behat is not part of "check" (it needs a browser): "make behat BEHAT_CONFIG=...".

MOODLE ?= $(HOME)/moodle
PLUGINDIR := public/local/catquiz
PHPCS ?= phpcs
# The same files as the CI: .moodle-plugin-ci.yml leaves out the recorded data under tests/fixtures (86 MB), and
# moodle-plugin-ci skips hidden directories such as .github (CI tooling, not plugin code).
PHPCSFLAGS := --standard=moodle --severity=1 --extensions=php \
	--exclude=PSR1.Classes.ClassDeclaration,moodle.Commenting.TodoComment \
	--ignore=*/node_modules/*,*/vendor/*,*/.github/*,*/catquizcentralhub/*,*/tests/fixtures/*,*/tests/behat/fixtures/*,*/strategy_test.php
FEATURES ?= tests/behat

.PHONY: check lint amd phpcs phpunit sync behat

check: lint amd phpcs phpunit

lint:
	@find . -name '*.php' -not -path './node_modules/*' -not -path './vendor/*' -not -path './catquizcentralhub/*' -print0 \
		| xargs -0 -n 50 -P 4 php -l > /dev/null
	@echo "lint: ok"

# The committed AMD build has to belong to the committed sources (the hard CI check of issue #31).
amd:
	python3 .github/ci/check_amd_build.py .

phpcs:
	$(PHPCS) $(PHPCSFLAGS) .

sync:
	rsync -a --delete --exclude .git --exclude node_modules ./ $(MOODLE)/$(PLUGINDIR)/

phpunit:
	cd $(MOODLE) && vendor/bin/phpunit --testsuite local_catquiz_testsuite

behat:
	cd $(MOODLE) && vendor/bin/behat --config $(BEHAT_CONFIG) --profile=chrome $(PLUGINDIR)/$(FEATURES)
