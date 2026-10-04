#ifndef PHP_WINTER_BOOT_H
#define PHP_WINTER_BOOT_H

extern zend_module_entry winter_boot_module_entry;
#define phpext_winter_boot_ptr &winter_boot_module_entry

#define PHP_WINTER_BOOT_VERSION "0.1.0"

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
	void *frames; /* wb_defer_frame* head; void* keeps the public header free of internals */
ZEND_END_MODULE_GLOBALS(winter_boot)

#ifdef ZTS
#define WB_G(v) ZEND_TSRMG(winter_boot_globals_id, zend_winter_boot_globals *, v)
#else
#define WB_G(v) ZEND_MODULE_GLOBALS_ACCESSOR(winter_boot, v)
#endif

#endif /* PHP_WINTER_BOOT_H */
