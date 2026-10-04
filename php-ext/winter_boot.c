/*
 * winter_boot extension — native capabilities for PHP.
 *
 * First capability: deferred() registers callable callbacks against the
 * owning PHP function's execution scope via the Zend Observer API and runs
 * them LIFO when that scope exits (normal return, early return, or
 * exception unwinding). The extension is structured so further native
 * capabilities can be added alongside it.
 */

#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "php_ini.h"
#include "ext/standard/info.h"

#include "php_winter_boot.h"

#include "Zend/zend_observer.h"
#include "Zend/zend_exceptions.h"

ZEND_DECLARE_MODULE_GLOBALS(winter_boot);

/* One callback registered by deferred(). Stacked LIFO via `next`. */
typedef struct _wb_defer_entry {
	zval cb;
	struct _wb_defer_entry *next;
} wb_defer_entry;

/* All pending callbacks for one owning zend_execute_data frame. */
typedef struct _wb_defer_frame {
	zend_execute_data *ex;
	wb_defer_entry *head;
	size_t count;
	struct _wb_defer_frame *next;
} wb_defer_frame;

static wb_defer_frame *wb_frames(void)
{
	return (wb_defer_frame *) WB_G(frames);
}

static void wb_frames_set(wb_defer_frame *head)
{
	WB_G(frames) = (void *) head;
}

/* Detach (and return) the frame owning `ex`, or NULL. Detaching before
 * running callbacks guarantees exactly-once execution even if a callback
 * throws, bails out, or registers further deferred callbacks. */
static wb_defer_frame *wb_frame_detach(zend_execute_data *ex)
{
	wb_defer_frame **link;
	wb_defer_frame *head = wb_frames();

	link = &head;
	while (*link != NULL) {
		if ((*link)->ex == ex) {
			wb_defer_frame *found = *link;
			*link = found->next;
			wb_frames_set(head);
			found->next = NULL;
			return found;
		}
		link = &(*link)->next;
	}
	return NULL;
}

static wb_defer_frame *wb_frame_find_or_create(zend_execute_data *ex)
{
	wb_defer_frame *f = wb_frames();
	while (f != NULL) {
		if (f->ex == ex) {
			return f;
		}
		f = f->next;
	}
	f = (wb_defer_frame *) emalloc(sizeof(wb_defer_frame));
	f->ex = ex;
	f->head = NULL;
	f->count = 0;
	f->next = wb_frames();
	wb_frames_set(f);
	return f;
}

/* Free a whole frame list without executing anything. Used at request
 * shutdown for scopes that never exited (fatal error paths). */
static void wb_frames_free_all(void)
{
	wb_defer_frame *f = wb_frames();
	wb_frames_set(NULL);
	while (f != NULL) {
		wb_defer_frame *next_f = f->next;
		wb_defer_entry *e = f->head;
		while (e != NULL) {
			wb_defer_entry *next_e = e->next;
			zval_ptr_dtor(&e->cb);
			efree(e);
			e = next_e;
		}
		efree(f);
		f = next_f;
	}
}

static void wb_run_frame(wb_defer_frame *f)
{
	zend_object *orig = EG(exception);
	zend_object *orig_prev = EG(prev_exception);
	zend_object *last_pe = NULL;
	zend_object **cb_ex = NULL;
	size_t cb_len = 0;
	wb_defer_entry *e;

	if (orig != NULL) {
		GC_ADDREF(orig);
	}
	if (orig_prev != NULL) {
		GC_ADDREF(orig_prev);
	}
	if (orig != NULL || orig_prev != NULL) {
		zend_clear_exception();
	}

	if (f->count > 0) {
		cb_ex = (zend_object **) emalloc(sizeof(zend_object *) * f->count);
	}

	e = f->head;
	while (e != NULL) {
		wb_defer_entry *next = e->next;
		zval retval_tmp;
		zend_fcall_info fci;
		zend_fcall_info_cache fcc;
		char *error = NULL;

		ZVAL_UNDEF(&retval_tmp);
		memset(&fci, 0, sizeof(fci));
		fci.size = sizeof(fci);
		memset(&fcc, 0, sizeof(fcc));

		if (zend_fcall_info_init(&e->cb, 0, &fci, &fcc, NULL, &error) == SUCCESS) {
			fci.retval = &retval_tmp;
			fci.params = NULL;
			fci.param_count = 0;
			zend_call_function(&fci, &fcc);
			if (!Z_ISUNDEF(retval_tmp)) {
				zval_ptr_dtor(&retval_tmp);
			}
		} else {
			zend_throw_error(NULL, "deferred(): callback is no longer callable");
		}
		if (error != NULL) {
			efree(error);
		}

		if (EG(exception) != NULL) {
			zend_object *thrown = EG(exception);
			zend_object *thrown_pe = EG(prev_exception);
			GC_ADDREF(thrown);
			if (thrown_pe != NULL) {
				GC_ADDREF(thrown_pe);
			}
			zend_clear_exception();
			if (cb_len < f->count) {
				cb_ex[cb_len++] = thrown;
			} else {
				OBJ_RELEASE(thrown);
			}
			if (thrown_pe != NULL) {
				if (last_pe != NULL) {
					OBJ_RELEASE(last_pe);
				}
				last_pe = thrown_pe;
			}
		}

		zval_ptr_dtor(&e->cb);
		efree(e);
		e = next;
	}
	efree(f);

	/* Rebuild one active exception, preserving everything via the
	 * `previous` chain: original exception innermost, then each deferred
	 * failure in execution order, so the last failure executed (earliest
	 * registered) is outermost. This mirrors `finally` semantics where a
	 * cleanup failure supersedes — but never silently drops — the
	 * original, and no remaining callback is ever skipped. */
	{
		zend_object *chain = orig;
		size_t i;

		for (i = 0; i < cb_len; i++) {
			if (chain != NULL) {
				/* set_previous() appends `chain` to the tail of
				 * cb_ex[i]'s `previous` chain and consumes our
				 * reference to `chain` on every return path. */
				zend_exception_set_previous(cb_ex[i], chain);
			}
			chain = cb_ex[i];
		}
		if (cb_ex != NULL) {
			efree(cb_ex);
		}

		if (chain != NULL) {
			EG(exception) = chain;
			if (last_pe != NULL) {
				EG(prev_exception) = last_pe;
				if (orig_prev != NULL) {
					OBJ_RELEASE(orig_prev);
				}
			} else {
				EG(prev_exception) = orig_prev;
			}
		} else {
			EG(exception) = NULL;
			EG(prev_exception) = orig_prev;
		}
	}
}

/* Observer end handler: runs (and frees) the deferred-callback stack of the
 * exiting scope. `retval` is left untouched — deferred callbacks cannot
 * alter the owner's return value. */
static void wb_observer_end(zend_execute_data *execute_data, zval *retval)
{
	wb_defer_frame *f;

	(void) retval;

	if (execute_data == NULL) {
		return;
	}
	f = wb_frame_detach(execute_data);
	if (f == NULL) {
		return;
	}
	wb_run_frame(f);
}

/* Observer init: observe every user-code call so any scope that later calls
 * deferred() already carries our end handler. Internal functions can never
 * own a deferred-callback scope (deferred() rejects them as owners), so they
 * are skipped. */
static zend_observer_fcall_handlers wb_observer_init(zend_execute_data *execute_data)
{
	zend_observer_fcall_handlers handlers = {NULL, NULL};

	if (execute_data != NULL && execute_data->func != NULL
		&& ZEND_USER_CODE(execute_data->func->type)) {
		handlers.end = wb_observer_end;
	}
	return handlers;
}

ZEND_BEGIN_ARG_INFO_EX(arginfo_deferred, 0, 0, 1)
	ZEND_ARG_CALLABLE_INFO(0, callback, 0)
ZEND_END_ARG_INFO()

/* Shared implementation behind deferred() and its defered() alias, so both
 * names run byte-identical logic. `func_name` is only used for error
 * messages so failures name the spelling the caller actually used. */
static void wb_deferred_impl(INTERNAL_FUNCTION_PARAMETERS, const char *func_name)
{
	zval *cb;
	zend_execute_data *owner;
	zend_string *callable_name = NULL;
	bool is_callable;
	wb_defer_frame *f;
	wb_defer_entry *entry;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_ZVAL(cb)
	ZEND_PARSE_PARAMETERS_END();

	is_callable = zend_is_callable(cb, 0, &callable_name);
	if (callable_name != NULL) {
		zend_string_release(callable_name);
	}
	if (!is_callable) {
		zend_type_error("%s(): argument #1 ($callback) must be a valid callable", func_name);
		RETURN_THROWS();
	}

	/* Bind to the nearest enclosing *user* scope. Skipping internal
	 * frames keeps deferred()/defered() usable through dispatchers such
	 * as call_user_func(); the first user frame is still "the current PHP
	 * function" from the caller's point of view. */
	owner = execute_data->prev_execute_data;
	while (owner != NULL && owner->func != NULL && !ZEND_USER_CODE(owner->func->type)) {
		owner = owner->prev_execute_data;
	}

	if (owner == NULL || owner->func == NULL || !ZEND_USER_CODE(owner->func->type)) {
		zend_throw_error(NULL, "%s(): must be called inside a PHP function; global scope is not supported", func_name);
		RETURN_THROWS();
	}
	if (owner->func->common.function_name == NULL) {
		zend_throw_error(NULL, "%s(): cannot be used in global/file scope; call it inside a function", func_name);
		RETURN_THROWS();
	}
	if ((owner->func->common.fn_flags & ZEND_ACC_GENERATOR) != 0) {
		zend_throw_error(NULL, "%s(): is not supported inside generator functions (yield suspends scope exit); use try/finally instead", func_name);
		RETURN_THROWS();
	}

	f = wb_frame_find_or_create(owner);
	entry = (wb_defer_entry *) emalloc(sizeof(wb_defer_entry));
	ZVAL_COPY(&entry->cb, cb);
	entry->next = f->head;
	f->head = entry;
	f->count++;
}

PHP_FUNCTION(deferred)
{
	wb_deferred_impl(INTERNAL_FUNCTION_PARAM_PASSTHRU, "deferred");
}

PHP_FUNCTION(defered)
{
	wb_deferred_impl(INTERNAL_FUNCTION_PARAM_PASSTHRU, "defered");
}

static PHP_GINIT_FUNCTION(winter_boot)
{
#if defined(COMPILE_DL_WINTER_BOOT) && defined(ZTS)
	ZEND_TSRMLS_CACHE_UPDATE();
#endif
	winter_boot_globals->frames = NULL;
}

static PHP_MINIT_FUNCTION(winter_boot)
{
	zend_observer_fcall_register(wb_observer_init);
	return SUCCESS;
}

static PHP_MSHUTDOWN_FUNCTION(winter_boot)
{
	return SUCCESS;
}

static PHP_RINIT_FUNCTION(winter_boot)
{
	wb_frames_set(NULL);
	return SUCCESS;
}

static PHP_RSHUTDOWN_FUNCTION(winter_boot)
{
	/* Scopes that never exited (fatal error paths) are freed without
	 * executing: after a fatal there is no safe engine state left to
	 * run user callbacks in. */
	wb_frames_free_all();
	return SUCCESS;
}

static PHP_MINFO_FUNCTION(winter_boot)
{
	php_info_print_table_start();
	php_info_print_table_header(2, "winter_boot support", "enabled");
	php_info_print_table_row(2, "Version", PHP_WINTER_BOOT_VERSION);
	php_info_print_table_row(2, "deferred() semantics", "LIFO on owning-function exit; runs on return and exception unwind");
	php_info_print_table_row(2, "defered()", "alias of deferred()");
	php_info_print_table_end();
}

static const zend_function_entry winter_boot_functions[] = {
	PHP_FE(deferred, arginfo_deferred)
	PHP_FE(defered, arginfo_deferred)
	PHP_FE_END
};

zend_module_entry winter_boot_module_entry = {
	STANDARD_MODULE_HEADER,
	"winter_boot",
	winter_boot_functions,
	PHP_MINIT(winter_boot),
	PHP_MSHUTDOWN(winter_boot),
	PHP_RINIT(winter_boot),
	PHP_RSHUTDOWN(winter_boot),
	PHP_MINFO(winter_boot),
	PHP_WINTER_BOOT_VERSION,
	PHP_MODULE_GLOBALS(winter_boot),
	PHP_GINIT(winter_boot),
	NULL,
	NULL,
	STANDARD_MODULE_PROPERTIES_EX
};

#ifdef COMPILE_DL_WINTER_BOOT
#ifdef ZTS
ZEND_TSRMLS_CACHE_DEFINE()
#endif
ZEND_GET_MODULE(winter_boot)
#endif
