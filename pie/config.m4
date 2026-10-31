PHP_ARG_ENABLE([rapidfuzz],
  [whether to enable rapidfuzz],
  [AS_HELP_STRING([--enable-rapidfuzz], [Enable the RapidFuzz string matching extension])],
  [yes])

if test "$PHP_RAPIDFUZZ" != "no"; then
  AC_DEFINE([HAVE_RAPIDFUZZ], [1], [Have the RapidFuzz extension])
  PHP_REQUIRE_CXX()
  ln -sfn ../src "$srcdir/src"
  ln -sfn ../third_party "$srcdir/third_party"
  ln -sfn ../tests "$srcdir/tests"
  RAPIDFUZZ_EXT_VERSION=`sh "$srcdir/../scripts/ext-version.sh"`
  printf '#define PHP_RAPIDFUZZ_VERSION "%s"\n' "$RAPIDFUZZ_EXT_VERSION" > "$srcdir/src/rapidfuzz_version.h"
  PHP_NEW_EXTENSION([rapidfuzz], [src/php_rapidfuzz.cpp], [$ext_shared], , [-std=c++17], [yes])
  PHP_ADD_INCLUDE([$ext_srcdir/third_party/rapidfuzz/include])
  PHP_ADD_BUILD_DIR([$ext_builddir/src])
fi
