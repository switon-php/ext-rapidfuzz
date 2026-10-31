#ifndef PHP_RAPIDFUZZ_H
#define PHP_RAPIDFUZZ_H

#ifndef PHP_RAPIDFUZZ_VERSION
#define PHP_RAPIDFUZZ_VERSION "1.0.0"
#endif
#define PHP_RAPIDFUZZ_LIBRARY "3.3.4"

extern zend_module_entry rapidfuzz_module_entry;
#define phpext_rapidfuzz_ptr &rapidfuzz_module_entry

#endif
