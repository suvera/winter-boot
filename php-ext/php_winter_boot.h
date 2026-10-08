#ifndef PHP_WINTER_BOOT_H
#define PHP_WINTER_BOOT_H

extern zend_module_entry winter_boot_module_entry;
#define phpext_winter_boot_ptr &winter_boot_module_entry

/* Defined by config.m4 from the repo-root VERSION.txt (via config.h). */
#ifndef PHP_WINTER_BOOT_VERSION
# error "PHP_WINTER_BOOT_VERSION is undefined: build with phpize/configure so config.h is generated"
#endif

#ifdef PHP_WIN32
# define PHP_WINTER_BOOT_API __declspec(dllexport)
#elif defined(__GNUC__) && __GNUC__ >= 4
# define PHP_WINTER_BOOT_API __attribute__ ((visibility("default")))
#else
# define PHP_WINTER_BOOT_API
#endif

#ifdef ZTS
#include "TSRM.h"
#endif

ZEND_BEGIN_MODULE_GLOBALS(winter_boot)
	void *advice_map; /* HashTable* of wb_advice*, keyed by function pointer */
	void *code_cache; /* wb_code_entry* list of cached inline-code compilations */
ZEND_END_MODULE_GLOBALS(winter_boot)

#ifdef ZTS
#define WB_G(v) ZEND_TSRMG(winter_boot_globals_id, zend_winter_boot_globals *, v)
#else
#define WB_G(v) ZEND_MODULE_GLOBALS_ACCESSOR(winter_boot, v)
#endif

#endif /* PHP_WINTER_BOOT_H */
