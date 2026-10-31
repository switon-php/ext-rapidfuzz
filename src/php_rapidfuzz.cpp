/* windows.h defines min and max as macros. RapidFuzz calls std::min and
   std::numeric_limits<T>::max, which MSVC then rejects. Define this before
   php.h pulls windows.h in. */
#ifdef _WIN32
#ifndef NOMINMAX
#define NOMINMAX
#endif
#endif

extern "C" {
#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "zend_exceptions.h"
#include "zend_enum.h"
#include "ext/standard/info.h"
#if defined(__has_include)
# if __has_include("rapidfuzz_version.h")
#  include "rapidfuzz_version.h"
# endif
#endif
#include "php_rapidfuzz.h"
}

#include <cstddef>
#include <cstdint>
#include <new>
#include <string>

#include <rapidfuzz/distance/DamerauLevenshtein.hpp>
#include <rapidfuzz/distance/Hamming.hpp>
#include <rapidfuzz/distance/Indel.hpp>
#include <rapidfuzz/distance/Jaro.hpp>
#include <rapidfuzz/distance/JaroWinkler.hpp>
#include <rapidfuzz/distance/LCSseq.hpp>
#include <rapidfuzz/distance/Levenshtein.hpp>
#include <rapidfuzz/distance/OSA.hpp>
#include <rapidfuzz/distance/Postfix.hpp>
#include <rapidfuzz/distance/Prefix.hpp>
#include <rapidfuzz/fuzz.hpp>

static zend_class_entry *rapidfuzz_fuzz_exception_ce;
static zend_class_entry *rapidfuzz_utf8_exception_ce;
static zend_class_entry *rapidfuzz_ratio_ce;

/* The four case objects, resolved once in MINIT. A case is a singleton, so the
   call compares pointers instead of decoding the backing value. */
static zend_object *rapidfuzz_ratio_standard;
static zend_object *rapidfuzz_ratio_partial;
static zend_object *rapidfuzz_ratio_token_sort;
static zend_object *rapidfuzz_ratio_token_set;
static zend_object *rapidfuzz_ratio_partial_token_sort;
static zend_object *rapidfuzz_ratio_partial_token_set;
static zend_object *rapidfuzz_ratio_token;
static zend_object *rapidfuzz_ratio_partial_token;
static zend_object *rapidfuzz_ratio_weighted;
static zend_object *rapidfuzz_ratio_quick;

static bool rapidfuzz_utf32(const char *bytes, size_t length, std::u32string *out)
{
    out->clear();
    out->reserve(length);
    size_t i = 0;

    while (i < length) {
        unsigned char lead = static_cast<unsigned char>(bytes[i]);
        char32_t codepoint;
        size_t width;

        if (lead < 0x80) {
            codepoint = lead;
            width = 1;
        } else if ((lead & 0xE0) == 0xC0) {
            codepoint = lead & 0x1F;
            width = 2;
        } else if ((lead & 0xF0) == 0xE0) {
            codepoint = lead & 0x0F;
            width = 3;
        } else if ((lead & 0xF8) == 0xF0 && lead <= 0xF4) {
            codepoint = lead & 0x07;
            width = 4;
        } else {
            return false;
        }

        if (i + width > length) {
            return false;
        }
        for (size_t extra = 1; extra < width; extra++) {
            unsigned char cont = static_cast<unsigned char>(bytes[i + extra]);
            if ((cont & 0xC0) != 0x80) {
                return false;
            }
            codepoint = (codepoint << 6) | (cont & 0x3F);
        }
        if (width == 2 && codepoint < 0x80) {
            return false;
        }
        if (width == 3 && codepoint < 0x800) {
            return false;
        }
        if (width == 4 && codepoint < 0x10000) {
            return false;
        }
        if (codepoint > 0x10FFFF || (codepoint >= 0xD800 && codepoint <= 0xDFFF)) {
            return false;
        }
        out->push_back(codepoint);
        i += width;
    }
    return true;
}

/* The message names the argument, so a caller passing two strings learns which
   one is invalid. */
static bool rapidfuzz_decode(zend_string *value, const char *argument, std::u32string *out)
{
    if (rapidfuzz_utf32(ZSTR_VAL(value), ZSTR_LEN(value), out)) {
        return true;
    }
    zend_throw_exception_ex(rapidfuzz_utf8_exception_ce, 0, "%s must be valid UTF-8", argument);
    return false;
}

template <typename Score>
static void rapidfuzz_score_strings(zend_string *left, zend_string *right, zval *return_value, Score score)
{
    try {
        std::u32string a;
        std::u32string b;
        if (!rapidfuzz_decode(left, "Argument #1 ($left)", &a)
            || !rapidfuzz_decode(right, "Argument #2 ($right)", &b)) {
            return;
        }
        RETVAL_DOUBLE(score(a, b));
        // LCOV_EXCL_START
    } catch (const std::bad_alloc&) {
        zend_throw_exception(rapidfuzz_fuzz_exception_ce, "out of memory", 0);
    } catch (const std::exception& error) {
        zend_throw_exception(rapidfuzz_fuzz_exception_ce, error.what(), 0);
    }
    // LCOV_EXCL_STOP
}

/* Every PHP-side similarity is 0 to 1. rapidfuzz::fuzz reports 0 to 100, so
   the cutoff goes in scaled up and the score comes back scaled down. Keeping
   the conversion here means the callers pass the library call untouched. */
template <typename Score>
static void rapidfuzz_score_percent(zend_string *left, zend_string *right, double score_cutoff, zval *return_value, Score score)
{
    rapidfuzz_score_strings(left, right, return_value, [score_cutoff, score](const std::u32string& a, const std::u32string& b) {
        return score(a, b, score_cutoff * 100.0) / 100.0;
    });
}

static void rapidfuzz_add_ratio_case(zend_class_entry *ce, const char *name, const char *value)
{
    zval backing;

    ZVAL_INTERNED_STR(&backing, zend_string_init_interned(value, strlen(value), 1));
    zend_enum_add_case_cstr(ce, name, &backing);
}

/* Case objects are permanent, but looking them up during MINIT crashes.
   The first request resolves each singleton. */
static zend_object *rapidfuzz_ratio_case(const char *name)
{
    zend_object *case_object = zend_enum_get_case_cstr(rapidfuzz_ratio_ce, name);

    if (case_object == NULL) {
        // LCOV_EXCL_START
        zend_error_noreturn(E_ERROR, "rapidfuzz Ratio case %s is missing", name);
    } // LCOV_EXCL_STOP
    return case_object;
}

PHP_RINIT_FUNCTION(rapidfuzz)
{
    /* Each pointer is guarded on its own. A second thread that enters RINIT
       while the first is still filling the four would otherwise see the first
       one set, skip the block, and dispatch against a NULL sibling. The lookups
       are read-only, so a duplicate write is harmless. */
    if (rapidfuzz_ratio_standard == NULL) {
        rapidfuzz_ratio_standard = rapidfuzz_ratio_case("Standard");
    }
    if (rapidfuzz_ratio_partial == NULL) {
        rapidfuzz_ratio_partial = rapidfuzz_ratio_case("Partial");
    }
    if (rapidfuzz_ratio_token_sort == NULL) {
        rapidfuzz_ratio_token_sort = rapidfuzz_ratio_case("TokenSort");
    }
    if (rapidfuzz_ratio_token_set == NULL) {
        rapidfuzz_ratio_token_set = rapidfuzz_ratio_case("TokenSet");
    }
    if (rapidfuzz_ratio_partial_token_sort == NULL) {
        rapidfuzz_ratio_partial_token_sort = rapidfuzz_ratio_case("PartialTokenSort");
    }
    if (rapidfuzz_ratio_partial_token_set == NULL) {
        rapidfuzz_ratio_partial_token_set = rapidfuzz_ratio_case("PartialTokenSet");
    }
    if (rapidfuzz_ratio_token == NULL) {
        rapidfuzz_ratio_token = rapidfuzz_ratio_case("Token");
    }
    if (rapidfuzz_ratio_partial_token == NULL) {
        rapidfuzz_ratio_partial_token = rapidfuzz_ratio_case("PartialToken");
    }
    if (rapidfuzz_ratio_weighted == NULL) {
        rapidfuzz_ratio_weighted = rapidfuzz_ratio_case("Weighted");
    }
    if (rapidfuzz_ratio_quick == NULL) {
        rapidfuzz_ratio_quick = rapidfuzz_ratio_case("Quick");
    }
    return SUCCESS;
}

ZEND_BEGIN_ARG_INFO_EX(arginfo_rapidfuzz_construct, 0, 0, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_rapidfuzz_ratio, 0, 2, IS_DOUBLE, 0)
    ZEND_ARG_TYPE_INFO(0, left, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, right, IS_STRING, 0)
    ZEND_ARG_OBJ_INFO_WITH_DEFAULT_VALUE(0, kind, Switon\\RapidFuzz\\Ratio, 0, "Switon\\RapidFuzz\\Ratio::Standard")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, scoreCutoff, IS_DOUBLE, 0, "0.0")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_rapidfuzz_jaro_winkler, 0, 2, IS_DOUBLE, 0)
    ZEND_ARG_TYPE_INFO(0, left, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, right, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, prefixWeight, IS_DOUBLE, 0, "0.1")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, scoreCutoff, IS_DOUBLE, 0, "0.0")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_rapidfuzz_distance, 0, 2, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, left, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, right, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, insertionCost, IS_LONG, 0, "1")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, replacementCost, IS_LONG, 0, "1")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, deletionCost, IS_LONG, 0, "1")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, scoreCutoff, IS_LONG, 0, "PHP_INT_MAX")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, scoreHint, IS_LONG, 0, "PHP_INT_MAX")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_rapidfuzz_edit, 0, 2, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, left, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, right, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, scoreCutoff, IS_LONG, 0, "PHP_INT_MAX")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_rapidfuzz_hamming, 0, 2, IS_LONG, 0)
    ZEND_ARG_TYPE_INFO(0, left, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, right, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, pad, _IS_BOOL, 0, "true")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, scoreCutoff, IS_LONG, 0, "PHP_INT_MAX")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_rapidfuzz_jaro, 0, 2, IS_DOUBLE, 0)
    ZEND_ARG_TYPE_INFO(0, left, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, right, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, scoreCutoff, IS_DOUBLE, 0, "0.0")
ZEND_END_ARG_INFO()

PHP_METHOD(RapidfuzzFuzz, __construct)
{
    ZEND_PARSE_PARAMETERS_NONE();
}

PHP_METHOD(RapidfuzzFuzz, ratio)
{
    zend_string *left;
    zend_string *right;
    zval *kind = NULL;
    double score_cutoff = 0.0;
    zend_object *selected;

    ZEND_PARSE_PARAMETERS_START(2, 4)
        Z_PARAM_STR(left)
        Z_PARAM_STR(right)
        Z_PARAM_OPTIONAL
        Z_PARAM_OBJECT_OF_CLASS(kind, rapidfuzz_ratio_ce)
        Z_PARAM_DOUBLE(score_cutoff)
    ZEND_PARSE_PARAMETERS_END();

    /* An omitted argument keeps kind at NULL. The arginfo default is for
       reflection only, so the fallback is here. */
    selected = kind != NULL ? Z_OBJ_P(kind) : rapidfuzz_ratio_standard;
    if (selected == rapidfuzz_ratio_standard) {
        rapidfuzz_score_percent(left, right, score_cutoff, return_value, [](const std::u32string& a, const std::u32string& b, double cutoff) {
            return rapidfuzz::fuzz::ratio(a, b, cutoff);
        });
        return;
    }
    if (selected == rapidfuzz_ratio_partial) {
        rapidfuzz_score_percent(left, right, score_cutoff, return_value, [](const std::u32string& a, const std::u32string& b, double cutoff) {
            return rapidfuzz::fuzz::partial_ratio(a, b, cutoff);
        });
        return;
    }
    if (selected == rapidfuzz_ratio_token_sort) {
        rapidfuzz_score_percent(left, right, score_cutoff, return_value, [](const std::u32string& a, const std::u32string& b, double cutoff) {
            return rapidfuzz::fuzz::token_sort_ratio(a, b, cutoff);
        });
        return;
    }
    if (selected == rapidfuzz_ratio_token_set) {
        rapidfuzz_score_percent(left, right, score_cutoff, return_value, [](const std::u32string& a, const std::u32string& b, double cutoff) {
            return rapidfuzz::fuzz::token_set_ratio(a, b, cutoff);
        });
        return;
    }
    if (selected == rapidfuzz_ratio_partial_token_sort) {
        rapidfuzz_score_percent(left, right, score_cutoff, return_value, [](const std::u32string& a, const std::u32string& b, double cutoff) {
            return rapidfuzz::fuzz::partial_token_sort_ratio(a, b, cutoff);
        });
        return;
    }
    if (selected == rapidfuzz_ratio_partial_token_set) {
        rapidfuzz_score_percent(left, right, score_cutoff, return_value, [](const std::u32string& a, const std::u32string& b, double cutoff) {
            return rapidfuzz::fuzz::partial_token_set_ratio(a, b, cutoff);
        });
        return;
    }
    if (selected == rapidfuzz_ratio_token) {
        rapidfuzz_score_percent(left, right, score_cutoff, return_value, [](const std::u32string& a, const std::u32string& b, double cutoff) {
            return rapidfuzz::fuzz::token_ratio(a, b, cutoff);
        });
        return;
    }
    if (selected == rapidfuzz_ratio_partial_token) {
        rapidfuzz_score_percent(left, right, score_cutoff, return_value, [](const std::u32string& a, const std::u32string& b, double cutoff) {
            return rapidfuzz::fuzz::partial_token_ratio(a, b, cutoff);
        });
        return;
    }
    if (selected == rapidfuzz_ratio_weighted) {
        rapidfuzz_score_percent(left, right, score_cutoff, return_value, [](const std::u32string& a, const std::u32string& b, double cutoff) {
            return rapidfuzz::fuzz::WRatio(a, b, cutoff);
        });
        return;
    }
    if (selected == rapidfuzz_ratio_quick) {
        rapidfuzz_score_percent(left, right, score_cutoff, return_value, [](const std::u32string& a, const std::u32string& b, double cutoff) {
            return rapidfuzz::fuzz::QRatio(a, b, cutoff);
        });
        return;
    }
    /* Unreachable while Ratio has these ten cases: the parameter type rejects
       every other value. Kept so a case added without a branch here fails loudly. */
    // LCOV_EXCL_START
    zend_throw_exception(rapidfuzz_fuzz_exception_ce, "unsupported Ratio case", 0);
} // LCOV_EXCL_STOP

PHP_METHOD(RapidfuzzFuzz, jaroWinkler)
{
    zend_string *left;
    zend_string *right;
    double prefix_weight = 0.1;
    double score_cutoff = 0.0;

    ZEND_PARSE_PARAMETERS_START(2, 4)
        Z_PARAM_STR(left)
        Z_PARAM_STR(right)
        Z_PARAM_OPTIONAL
        Z_PARAM_DOUBLE(prefix_weight)
        Z_PARAM_DOUBLE(score_cutoff)
    ZEND_PARSE_PARAMETERS_END();

    rapidfuzz_score_strings(left, right, return_value, [prefix_weight, score_cutoff](const std::u32string& a, const std::u32string& b) {
        return rapidfuzz::jaro_winkler_similarity(a, b, prefix_weight, score_cutoff);
    });
}

/* A negative weight or cutoff would wrap when cast to size_t. Zero is the
   smallest value the library accepts, and a zero cutoff means an exact match
   is the only accepted result. */
static size_t rapidfuzz_weight(zend_long value)
{
    return value < 0 ? 0 : static_cast<size_t>(value);
}

PHP_METHOD(RapidfuzzDistance, __construct)
{
    ZEND_PARSE_PARAMETERS_NONE();
}

PHP_METHOD(RapidfuzzDistance, levenshtein)
{
    zend_string *left;
    zend_string *right;
    zend_long insertion_cost = 1;
    zend_long replacement_cost = 1;
    zend_long deletion_cost = 1;
    zend_long score_cutoff = ZEND_LONG_MAX;
    zend_long score_hint = ZEND_LONG_MAX;

    ZEND_PARSE_PARAMETERS_START(2, 7)
        Z_PARAM_STR(left)
        Z_PARAM_STR(right)
        Z_PARAM_OPTIONAL
        Z_PARAM_LONG(insertion_cost)
        Z_PARAM_LONG(replacement_cost)
        Z_PARAM_LONG(deletion_cost)
        Z_PARAM_LONG(score_cutoff)
        Z_PARAM_LONG(score_hint)
    ZEND_PARSE_PARAMETERS_END();

    try {
        std::u32string a;
        std::u32string b;
        size_t distance;
        size_t cutoff = rapidfuzz_weight(score_cutoff);

        if (!rapidfuzz_decode(left, "Argument #1 ($left)", &a)
            || !rapidfuzz_decode(right, "Argument #2 ($right)", &b)) {
            return;
        }
        /* The field order is insert, delete, replace. The PHP order is
           insertion, replacement, deletion, which matches levenshtein(). */
        rapidfuzz::LevenshteinWeightTable weights{
            rapidfuzz_weight(insertion_cost),
            rapidfuzz_weight(deletion_cost),
            rapidfuzz_weight(replacement_cost),
        };
        distance = rapidfuzz::levenshtein_distance(a, b, weights, cutoff, rapidfuzz_weight(score_hint));
        /* Above the cutoff the library returns cutoff + 1, which is not the
           real distance. Keep that value so the caller can tell the two apart. */
        if (distance > static_cast<size_t>(ZEND_LONG_MAX)) {
            // LCOV_EXCL_START
            zend_throw_exception(rapidfuzz_fuzz_exception_ce, "levenshtein distance does not fit a PHP int", 0);
            return;
        } // LCOV_EXCL_STOP
        RETVAL_LONG(static_cast<zend_long>(distance));
        // LCOV_EXCL_START
    } catch (const std::bad_alloc&) {
        zend_throw_exception(rapidfuzz_fuzz_exception_ce, "out of memory", 0);
    } catch (const std::exception& error) {
        zend_throw_exception(rapidfuzz_fuzz_exception_ce, error.what(), 0);
    }
    // LCOV_EXCL_STOP
}

template <typename Distance>
static void rapidfuzz_edit_count(zend_string *left, zend_string *right, zend_long score_cutoff, zval *return_value, Distance distance)
{
    try {
        std::u32string a;
        std::u32string b;
        size_t count;

        if (!rapidfuzz_decode(left, "Argument #1 ($left)", &a)
            || !rapidfuzz_decode(right, "Argument #2 ($right)", &b)) {
            return;
        }
        count = distance(a, b, rapidfuzz_weight(score_cutoff));
        if (count > static_cast<size_t>(ZEND_LONG_MAX)) {
            // LCOV_EXCL_START
            zend_throw_exception(rapidfuzz_fuzz_exception_ce, "edit count does not fit a PHP int", 0);
            return;
        } // LCOV_EXCL_STOP
        RETVAL_LONG(static_cast<zend_long>(count));
        // LCOV_EXCL_START
    } catch (const std::bad_alloc&) {
        zend_throw_exception(rapidfuzz_fuzz_exception_ce, "out of memory", 0);
    } catch (const std::exception& error) {
        zend_throw_exception(rapidfuzz_fuzz_exception_ce, error.what(), 0);
    }
    // LCOV_EXCL_STOP
}

#define RAPIDFUZZ_PARSE_EDIT(left, right, score_cutoff) \
    ZEND_PARSE_PARAMETERS_START(2, 3) \
        Z_PARAM_STR(left) \
        Z_PARAM_STR(right) \
        Z_PARAM_OPTIONAL \
        Z_PARAM_LONG(score_cutoff) \
    ZEND_PARSE_PARAMETERS_END()

PHP_METHOD(RapidfuzzDistance, damerauLevenshtein)
{
    zend_string *left;
    zend_string *right;
    zend_long score_cutoff = ZEND_LONG_MAX;

    RAPIDFUZZ_PARSE_EDIT(left, right, score_cutoff);
    rapidfuzz_edit_count(left, right, score_cutoff, return_value, [](const std::u32string& a, const std::u32string& b, size_t cutoff) {
        return rapidfuzz::experimental::damerau_levenshtein_distance(a, b, cutoff);
    });
}

PHP_METHOD(RapidfuzzDistance, hamming)
{
    zend_string *left;
    zend_string *right;
    bool pad = true;
    zend_long score_cutoff = ZEND_LONG_MAX;

    ZEND_PARSE_PARAMETERS_START(2, 4)
        Z_PARAM_STR(left)
        Z_PARAM_STR(right)
        Z_PARAM_OPTIONAL
        Z_PARAM_BOOL(pad)
        Z_PARAM_LONG(score_cutoff)
    ZEND_PARSE_PARAMETERS_END();

    rapidfuzz_edit_count(left, right, score_cutoff, return_value, [pad](const std::u32string& a, const std::u32string& b, size_t cutoff) {
        return rapidfuzz::hamming_distance(a, b, pad, cutoff);
    });
}

PHP_METHOD(RapidfuzzDistance, indel)
{
    zend_string *left;
    zend_string *right;
    zend_long score_cutoff = ZEND_LONG_MAX;

    RAPIDFUZZ_PARSE_EDIT(left, right, score_cutoff);
    rapidfuzz_edit_count(left, right, score_cutoff, return_value, [](const std::u32string& a, const std::u32string& b, size_t cutoff) {
        return rapidfuzz::indel_distance(a, b, cutoff);
    });
}

PHP_METHOD(RapidfuzzFuzz, jaro)
{
    zend_string *left;
    zend_string *right;
    double score_cutoff = 0.0;

    ZEND_PARSE_PARAMETERS_START(2, 3)
        Z_PARAM_STR(left)
        Z_PARAM_STR(right)
        Z_PARAM_OPTIONAL
        Z_PARAM_DOUBLE(score_cutoff)
    ZEND_PARSE_PARAMETERS_END();

    rapidfuzz_score_strings(left, right, return_value, [score_cutoff](const std::u32string& a, const std::u32string& b) {
        return rapidfuzz::jaro_similarity(a, b, score_cutoff);
    });
}

PHP_METHOD(RapidfuzzDistance, lcs)
{
    zend_string *left;
    zend_string *right;
    zend_long score_cutoff = ZEND_LONG_MAX;

    RAPIDFUZZ_PARSE_EDIT(left, right, score_cutoff);
    rapidfuzz_edit_count(left, right, score_cutoff, return_value, [](const std::u32string& a, const std::u32string& b, size_t cutoff) {
        return rapidfuzz::lcs_seq_distance(a, b, cutoff);
    });
}

PHP_METHOD(RapidfuzzDistance, osa)
{
    zend_string *left;
    zend_string *right;
    zend_long score_cutoff = ZEND_LONG_MAX;

    RAPIDFUZZ_PARSE_EDIT(left, right, score_cutoff);
    rapidfuzz_edit_count(left, right, score_cutoff, return_value, [](const std::u32string& a, const std::u32string& b, size_t cutoff) {
        return rapidfuzz::osa_distance(a, b, cutoff);
    });
}

PHP_METHOD(RapidfuzzDistance, prefix)
{
    zend_string *left;
    zend_string *right;
    zend_long score_cutoff = ZEND_LONG_MAX;

    RAPIDFUZZ_PARSE_EDIT(left, right, score_cutoff);
    rapidfuzz_edit_count(left, right, score_cutoff, return_value, [](const std::u32string& a, const std::u32string& b, size_t cutoff) {
        return rapidfuzz::prefix_distance(a, b, cutoff);
    });
}

PHP_METHOD(RapidfuzzDistance, postfix)
{
    zend_string *left;
    zend_string *right;
    zend_long score_cutoff = ZEND_LONG_MAX;

    RAPIDFUZZ_PARSE_EDIT(left, right, score_cutoff);
    rapidfuzz_edit_count(left, right, score_cutoff, return_value, [](const std::u32string& a, const std::u32string& b, size_t cutoff) {
        return rapidfuzz::postfix_distance(a, b, cutoff);
    });
}

static const zend_function_entry rapidfuzz_fuzz_methods[] = {
    PHP_ME(RapidfuzzFuzz, __construct, arginfo_rapidfuzz_construct, ZEND_ACC_PROTECTED)
    PHP_ME(RapidfuzzFuzz, ratio, arginfo_rapidfuzz_ratio, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    PHP_ME(RapidfuzzFuzz, jaro, arginfo_rapidfuzz_jaro, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    PHP_ME(RapidfuzzFuzz, jaroWinkler, arginfo_rapidfuzz_jaro_winkler, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    PHP_FE_END
};

static const zend_function_entry rapidfuzz_distance_methods[] = {
    PHP_ME(RapidfuzzDistance, __construct, arginfo_rapidfuzz_construct, ZEND_ACC_PROTECTED)
    PHP_ME(RapidfuzzDistance, levenshtein, arginfo_rapidfuzz_distance, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    PHP_ME(RapidfuzzDistance, damerauLevenshtein, arginfo_rapidfuzz_edit, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    PHP_ME(RapidfuzzDistance, hamming, arginfo_rapidfuzz_hamming, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    PHP_ME(RapidfuzzDistance, indel, arginfo_rapidfuzz_edit, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    PHP_ME(RapidfuzzDistance, lcs, arginfo_rapidfuzz_edit, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    PHP_ME(RapidfuzzDistance, osa, arginfo_rapidfuzz_edit, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    PHP_ME(RapidfuzzDistance, prefix, arginfo_rapidfuzz_edit, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    PHP_ME(RapidfuzzDistance, postfix, arginfo_rapidfuzz_edit, ZEND_ACC_PUBLIC | ZEND_ACC_STATIC)
    PHP_FE_END
};

PHP_MINIT_FUNCTION(rapidfuzz)
{
    zend_class_entry ce;

    INIT_NS_CLASS_ENTRY(ce, "Switon\\RapidFuzz", "FuzzException", NULL);
    rapidfuzz_fuzz_exception_ce = zend_register_internal_class_ex(&ce, zend_ce_exception);

    INIT_NS_CLASS_ENTRY(ce, "Switon\\RapidFuzz", "Utf8Exception", NULL);
    rapidfuzz_utf8_exception_ce = zend_register_internal_class_ex(&ce, rapidfuzz_fuzz_exception_ce);

    INIT_NS_CLASS_ENTRY(ce, "Switon\\RapidFuzz", "Fuzz", rapidfuzz_fuzz_methods);
    zend_register_internal_class(&ce);

    INIT_NS_CLASS_ENTRY(ce, "Switon\\RapidFuzz", "Distance", rapidfuzz_distance_methods);
    zend_register_internal_class(&ce);

    rapidfuzz_ratio_ce = zend_register_internal_enum("Switon\\RapidFuzz\\Ratio", IS_STRING, NULL);
    rapidfuzz_add_ratio_case(rapidfuzz_ratio_ce, "Standard", "ratio");
    rapidfuzz_add_ratio_case(rapidfuzz_ratio_ce, "Partial", "partial");
    rapidfuzz_add_ratio_case(rapidfuzz_ratio_ce, "TokenSort", "tokenSort");
    rapidfuzz_add_ratio_case(rapidfuzz_ratio_ce, "TokenSet", "tokenSet");
    rapidfuzz_add_ratio_case(rapidfuzz_ratio_ce, "PartialTokenSort", "partialTokenSort");
    rapidfuzz_add_ratio_case(rapidfuzz_ratio_ce, "PartialTokenSet", "partialTokenSet");
    rapidfuzz_add_ratio_case(rapidfuzz_ratio_ce, "Token", "token");
    rapidfuzz_add_ratio_case(rapidfuzz_ratio_ce, "PartialToken", "partialToken");
    rapidfuzz_add_ratio_case(rapidfuzz_ratio_ce, "Weighted", "weighted");
    rapidfuzz_add_ratio_case(rapidfuzz_ratio_ce, "Quick", "quick");

    return SUCCESS;
}

PHP_MINFO_FUNCTION(rapidfuzz)
{
    php_info_print_table_start();
    php_info_print_table_row(2, "Vendor", "Switon");
    php_info_print_table_row(2, "URL", "https://github.com/switon-php/ext-rapidfuzz");
    php_info_print_table_row(2, "Summary", "Fuzzy string scores for UTF-8 text");
    php_info_print_table_row(2, "Version", PHP_RAPIDFUZZ_VERSION);
    php_info_print_table_row(2, "RapidFuzz", PHP_RAPIDFUZZ_LIBRARY);
    php_info_print_table_end();
}

extern "C" {
zend_module_entry rapidfuzz_module_entry = {
    STANDARD_MODULE_HEADER,
    "rapidfuzz",
    NULL,
    PHP_MINIT(rapidfuzz),
    NULL,
    PHP_RINIT(rapidfuzz),
    NULL,
    PHP_MINFO(rapidfuzz),
    PHP_RAPIDFUZZ_VERSION,
    STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_RAPIDFUZZ
ZEND_GET_MODULE(rapidfuzz)
#endif
}
