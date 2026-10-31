#!/bin/sh
# Line coverage of src/php_rapidfuzz.cpp. Requires llvm-cov (Xcode or LLVM).
set -eu

root=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
cd "$root"

if [ ! -f pie/Makefile ]; then
    make build
fi

restore_build() {
    rm -f pie/src/php_rapidfuzz.lo pie/src/php_rapidfuzz.o pie/src/.libs/php_rapidfuzz.o
    make -C pie >/dev/null
}

if command -v xcrun >/dev/null 2>&1; then
    llvm_profdata=$(xcrun --find llvm-profdata)
    llvm_cov=$(xcrun --find llvm-cov)
else
    llvm_profdata=$(command -v llvm-profdata)
    llvm_cov=$(command -v llvm-cov)
fi

rm -f pie/src/php_rapidfuzz.lo pie/src/php_rapidfuzz.o pie/src/.libs/php_rapidfuzz.o
make -C pie \
    EXTRA_CXXFLAGS="-fprofile-instr-generate -fcoverage-mapping -O0" \
    EXTRA_LDFLAGS="-fprofile-instr-generate" >/dev/null

prof=$(mktemp -d)
trap 'rm -rf "$prof"; restore_build' EXIT
export LLVM_PROFILE_FILE="$prof/rapidfuzz-%p.profraw"

php -d "extension=$root/pie/modules/rapidfuzz.so" -d display_errors=1 tests/fuzz.php >/dev/null

"$llvm_profdata" merge -sparse "$prof"/rapidfuzz-*.profraw -o "$prof/rapidfuzz.profdata"
"$llvm_cov" show "$root/pie/.libs/rapidfuzz.so" \
    -instr-profile="$prof/rapidfuzz.profdata" \
    "$root/pie/src/php_rapidfuzz.cpp" >"$prof/show.txt"

python3 - "$prof/show.txt" <<'PY'
import re
import sys

lines = open(sys.argv[1]).read().splitlines()
start = 0
for index, line in enumerate(lines):
    if line.endswith("php_rapidfuzz.cpp:"):
        start = index + 1
        break
excluded = False
covered = 0
total = 0
missed = []
for line in lines[start + 1 :]:
    if line.startswith("/") and line.endswith(":"):
        break
    match = re.match(r"^ *(\d+)\| *([^|]*)\|(.*)$", line)
    if not match:
        continue
    number, count, source = match.group(1), match.group(2).strip(), match.group(3)
    if "LCOV_EXCL_START" in source:
        excluded = True
    if excluded or "LCOV_EXCL_LINE" in source:
        if "LCOV_EXCL_STOP" in source:
            excluded = False
        continue
    if "LCOV_EXCL_STOP" in source:
        excluded = False
        continue
    if count == "":
        continue
    total += 1
    if count != "0":
        covered += 1
    else:
        missed.append(f"{number}:{source}")

if total == 0:
    sys.exit("php_rapidfuzz.cpp has no instrumented lines")
percent = 100.0 * covered / total
print(f"src/php_rapidfuzz.cpp: {covered}/{total} lines = {percent:.2f}%")
if missed:
    print("\n".join(missed))
if percent < 95:
    sys.exit(f"line coverage {percent:.2f}% is below 95%")
PY
