PHP ?= php
PHPIZE ?= phpize
PHP_CONFIG ?= php-config
PHP_PREFIX ?=

UNAME_S := $(shell uname -s)
WINDOWS := $(findstring MINGW,$(UNAME_S))$(findstring MSYS,$(UNAME_S))$(findstring CYGWIN,$(UNAME_S))
ifneq ($(WINDOWS),)
EXT := pie/modules/php_rapidfuzz.dll
else
EXT := pie/modules/rapidfuzz.so
endif

.PHONY: test bench cover build clean FORCE

build: src/rapidfuzz_version.h
ifneq ($(WINDOWS),)
	cl_bin=$$(command -v cl || command -v cl.exe); \
	test -n "$$cl_bin"; \
	PATH="$$(dirname "$$cl_bin"):$$PATH"; \
	cd pie && cmd //c "phpize && configure --enable-rapidfuzz --with-prefix=$(PHP_PREFIX) && nmake"
	mkdir -p pie/modules
	dll=$$(find pie -name php_rapidfuzz.dll -type f ! -path '*/modules/*' | head -n 1); \
	test -n "$$dll"; \
	cp "$$dll" $(EXT)
else
	cd pie && if ! test -f configure || test config.m4 -nt configure; then $(PHPIZE); fi
	stamp=$$($(PHP_CONFIG) --version; $(PHP_CONFIG) --includes; $(PHP_CONFIG) --extension-dir); \
	if ! test -f pie/.build-stamp \
		|| ! printf '%s\n' "$$stamp" | cmp -s - pie/.build-stamp \
		|| test pie/config.m4 -nt pie/config.status; then \
		rm -rf pie/conftest pie/conftest.c pie/conftest.dSYM pie/conftest.err; \
		cd pie && ./configure --with-php-config=$(PHP_CONFIG) \
			&& grep -q -- '-std=c++17' Makefile \
			&& grep -q 'third_party/rapidfuzz/include' Makefile \
			&& printf '%s\n' "$$stamp" > .build-stamp \
			|| { echo "configure did not enable the C++17 RapidFuzz build" >&2; exit 1; }; \
	fi
	$(MAKE) -C pie
endif

test: build
	$(PHP) -d extension=$(CURDIR)/$(EXT) -d display_errors=1 tests/fuzz.php

bench: build
	$(PHP) -d extension=$(CURDIR)/$(EXT) -d display_errors=1 bench/bench.php

cover:
	sh scripts/cover.sh

# The version comes from git describe, so the file goes stale when a tag moves
# and not only when this script changes. Regenerate on every build, but rewrite
# only on a change so the compiler does not see a new timestamp.
src/rapidfuzz_version.h: FORCE
	@v="$$(sh scripts/ext-version.sh)"; \
	if ! printf '#define PHP_RAPIDFUZZ_VERSION "%s"\n' "$$v" | cmp -s - $@; then \
		printf '#define PHP_RAPIDFUZZ_VERSION "%s"\n' "$$v" > $@; \
	fi

FORCE:

clean:
	rm -f src/rapidfuzz_version.h pie/.build-stamp default.profraw pie/default.profraw
	rm -f src/*.lo src/*.loT src/*.dep
	rm -rf src/.libs
	cd pie && if test -f configure; then $(PHPIZE) --clean; fi
	find pie -mindepth 1 -maxdepth 1 \( -name 'conf*' -o -name 'conftest*' -o -name 'libconftest*' \) \
		! -name 'config.m4' ! -name 'config.w32' -exec rm -rf {} +
