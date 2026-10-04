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
#include "Zend/zend_interfaces.h"

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
	const zend_op *opline_before = EG(opline_before_exception);
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
		/* zend_clear_exception() above clobbered the unwinder's op
		 * bookmark; restore it so the caller's catch tables resolve. */
		EG(opline_before_exception) = opline_before;
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

/* ---- native AOP interception (second capability) ----
 *
 * The framework registers advised class methods once at boot; a
 * zend_execute_ex override then replays the exact proxy driver protocol in C
 * (registry lookup, aspectBegin, stop-check, original-or-skip,
 * aspectCommit/aspectFailed) by delegating each phase to the framework's
 * NativeAopDriver PHP class. Aspect semantics stay entirely in PHP.
 *
 * Lifetime rules: the advice map is request-bound (cleared at RSHUTDOWN) and
 * holds raw function/class pointers that are stable within a boot generation
 * (same assumption the eval proxies bake in via class_exists). Entries also
 * record scope+name so a recycled op_array address can never match a
 * different method. Per-interception PHP objects live in C locals (never in
 * shared maps), so unwinding needs no sweep: PHP exceptions propagate
 * through C code without longjmp, and fatal paths are freed with the heap.
 */

typedef struct _wb_advice {
	zend_function *func;
	zend_class_entry *scope;
	zend_string *method_name;
	zend_class_entry *bean_ce;
} wb_advice;

static void (*wb_orig_execute_ex)(zend_execute_data *ex);

#define WB_AOP_DRIVER_BEGIN "dev\\winterframework\\core\\aop\\NativeAopDriver::begin"
#define WB_AOP_DRIVER_FINISH "dev\\winterframework\\core\\aop\\NativeAopDriver::finish"

static HashTable *wb_advice_map(void)
{
	return (HashTable *) WB_G(advice_map);
}

static void wb_advice_map_set(HashTable *ht)
{
	WB_G(advice_map) = (void *) ht;
}

static void wb_advice_map_free(void)
{
	HashTable *ht = wb_advice_map();
	wb_advice_map_set(NULL);
	if (ht == NULL) {
		return;
	}
	{
		wb_advice *ad;
		ZEND_HASH_FOREACH_PTR(ht, ad) {
			zend_string_release(ad->method_name);
			efree(ad);
		} ZEND_HASH_FOREACH_END();
	}
	zend_hash_destroy(ht);
	FREE_HASHTABLE(ht);
}

static bool wb_name_is(const char *name, size_t len, const char *lit)
{
	size_t i = 0;
	while (lit[i] != '\0') {
		char c;
		if (i >= len) {
			return false;
		}
		c = name[i];
		if (c >= 'A' && c <= 'Z') {
			c += 32;
		}
		if (c != lit[i]) {
			return false;
		}
		i++;
	}
	return i == len;
}

/* Resolve (class, method) to a userland method, or NULL without throwing. */
static zend_function *wb_resolve_method(const char *cls, size_t cls_len, const char *mth, size_t mth_len, zend_class_entry **scope_out)
{
	zend_string *cls_str;
	zend_class_entry *ce;
	zend_function *func;

	cls_str = zend_string_init(cls, cls_len, 0);
	ce = zend_lookup_class(cls_str);
	zend_string_release(cls_str);
	if (ce == NULL) {
		return NULL;
	}
	func = (zend_function *) zend_hash_str_find_ptr_lc(&ce->function_table, mth, mth_len);
	if (func == NULL || func->type != ZEND_USER_FUNCTION) {
		return NULL;
	}
	if (scope_out != NULL) {
		*scope_out = ce;
	}
	return func;
}

ZEND_BEGIN_ARG_INFO_EX(arginfo_winter_boot_advise, 0, 0, 2)
	ZEND_ARG_TYPE_INFO(0, class, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, method, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_winter_boot_is_advised, 0, 0, 2)
	ZEND_ARG_TYPE_INFO(0, class, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, method, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_winter_boot_exec_inline, 0, 0, 2)
	ZEND_ARG_TYPE_INFO(0, code, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, vars, IS_ARRAY, 0)
ZEND_END_ARG_INFO()

/* Register an advised method. Rejects anything the interception cannot serve
 * soundly (mirroring the proxy generator's rejections, except final and
 * private/protected methods, which native interception newly supports). */
PHP_FUNCTION(winter_boot_advise)
{
	char *cls, *mth;
	size_t cls_len, mth_len;
	zend_class_entry *ce = NULL;
	zend_function *func;
	wb_advice *ad;
	HashTable *map;

	ZEND_PARSE_PARAMETERS_START(2, 2)
		Z_PARAM_STRING(cls, cls_len)
		Z_PARAM_STRING(mth, mth_len)
	ZEND_PARSE_PARAMETERS_END();

	func = wb_resolve_method(cls, cls_len, mth, mth_len, &ce);
	if (func == NULL) {
		zend_throw_error(NULL, "winter_boot_advise(): unknown method %s::%s", cls, mth);
		RETURN_THROWS();
	}
	if ((func->common.fn_flags & ZEND_ACC_ABSTRACT) != 0) {
		zend_throw_error(NULL, "winter_boot_advise(): cannot advise abstract method %s::%s", cls, mth);
		RETURN_THROWS();
	}
	if (wb_name_is(mth, mth_len, "__construct")) {
		zend_throw_error(NULL, "winter_boot_advise(): cannot advise constructor %s::%s", cls, mth);
		RETURN_THROWS();
	}
	if (wb_name_is(mth, mth_len, "__destruct")) {
		zend_throw_error(NULL, "winter_boot_advise(): cannot advise destructor %s::%s", cls, mth);
		RETURN_THROWS();
	}

	map = wb_advice_map();
	if (map == NULL) {
		ALLOC_HASHTABLE(map);
		zend_hash_init(map, 64, NULL, NULL, 0);
		wb_advice_map_set(map);
	}
	ad = (wb_advice *) emalloc(sizeof(wb_advice));
	ad->func = func;
	ad->scope = func->common.scope;
	ad->method_name = zend_string_copy(func->common.function_name);
	ad->bean_ce = ce;
	{
		wb_advice *old = (wb_advice *) zend_hash_index_find_ptr(
			map, (zend_ulong)(uintptr_t) func);
		if (old != NULL) {
			zend_string_release(old->method_name);
			efree(old);
		}
		zend_hash_index_update_ptr(map, (zend_ulong)(uintptr_t) func, ad);
	}
}

PHP_FUNCTION(winter_boot_is_advised)
{
	char *cls, *mth;
	size_t cls_len, mth_len;
	zend_function *func;

	ZEND_PARSE_PARAMETERS_START(2, 2)
		Z_PARAM_STRING(cls, cls_len)
		Z_PARAM_STRING(mth, mth_len)
	ZEND_PARSE_PARAMETERS_END();

	func = wb_resolve_method(cls, cls_len, mth, mth_len, NULL);
	if (func == NULL) {
		RETURN_FALSE;
	}
	{
		HashTable *map = wb_advice_map();
		wb_advice *ad = (map != NULL)
			? (wb_advice *) zend_hash_index_find_ptr(map, (zend_ulong)(uintptr_t) func)
			: NULL;
		if (ad != NULL && ad->func == func) {
			RETURN_TRUE;
		}
	}
	RETURN_FALSE;
}

/* Valid PHP variable names for inline-code binding (fail closed: anything
 * else can never be referenced by the evaluated code anyway). */
static bool wb_is_valid_var_name(zend_string *name)
{
	const char *p = ZSTR_VAL(name);
	size_t len = ZSTR_LEN(name);
	size_t i;
	unsigned char c;
	if (len == 0) {
		return false;
	}
	c = (unsigned char) p[0];
	if (!((c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z') || c == '_' || c >= 0x80)) {
		return false;
	}
	for (i = 1; i < len; i++) {
		c = (unsigned char) p[i];
		if (!((c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z')
				|| (c >= '0' && c <= '9') || c == '_' || c >= 0x80)) {
			return false;
		}
	}
	return true;
}

/* Evaluate AOP `#{...}` inline code with the given variables bound.
 *
 * Native counterpart of the former eval()-based template expansion, so no
 * PHP-level eval (and no variable-variables) remain in the framework. The
 * variables are installed into the *caller* frame's symbol table — exactly
 * where the old `$$name` injection put them, and the table the executed
 * code frame shares. Integer keys and invalid names are ignored; the two
 * reserved names that shadowed the old wrapper's own locals are skipped.
 * Compile/runtime failures propagate to the caller unchanged (the framework
 * wraps them, as before).
 */
PHP_FUNCTION(winter_boot_exec_inline)
{
	char *code;
	size_t code_len;
	HashTable *vars;
	zend_execute_data *mine;
	zend_execute_data *caller;
	HashTable *ctable;
	zval retval;
	zend_string *key;
	zval *val;

	ZEND_PARSE_PARAMETERS_START(2, 2)
		Z_PARAM_STRING(code, code_len)
		Z_PARAM_ARRAY_HT(vars)
	ZEND_PARSE_PARAMETERS_END();

	mine = EG(current_execute_data);
	caller = (mine != NULL) ? mine->prev_execute_data : NULL;
	if (caller == NULL || caller->func == NULL
		|| caller->func->type != ZEND_USER_FUNCTION) {
		zend_throw_error(NULL,
			"winter_boot_exec_inline(): requires a userland caller scope");
		RETURN_THROWS();
	}
	EG(current_execute_data) = caller;
	ctable = zend_rebuild_symbol_table();
	EG(current_execute_data) = mine;
	{
		zend_ulong idx;
		ZEND_HASH_FOREACH_KEY_VAL(vars, idx, key, val) {
			(void) idx;
			if (key == NULL) {
				continue;
			}
			if (zend_string_equals_literal(key, "__c_o_d_e")
				|| zend_string_equals_literal(key, "__namedArgs")) {
				continue;
			}
			if (!wb_is_valid_var_name(key)) {
				continue;
			}
			Z_TRY_ADDREF_P(val);
			zend_hash_update(ctable, key, val);
		} ZEND_HASH_FOREACH_END();
	}

	/* Same shape as eval(): compile the code as-is (it carries its own
	 * top-level return) and execute it. zend_eval_stringl() would wrap it
	 * in a second return and fail to compile. */
	ZVAL_UNDEF(&retval);
	{
		uint32_t original_compiler_options = CG(compiler_options);
		zend_string *code_str = zend_string_init(code, code_len, 0);
		zend_op_array *op_array;
		CG(compiler_options) = ZEND_COMPILE_DEFAULT_FOR_EVAL;
		op_array = zend_compile_string(code_str, "AOP inline code",
			ZEND_COMPILE_POSITION_AFTER_OPEN_TAG);
		CG(compiler_options) = original_compiler_options;
		zend_string_release(code_str);
		if (op_array != NULL) {
			zval local_retval;
			EG(no_extensions) = 1;
			op_array->scope = caller->func->common.scope;
			zend_try {
				ZVAL_UNDEF(&local_retval);
				zend_execute(op_array, &local_retval);
			} zend_catch {
				destroy_op_array(op_array);
				efree_size(op_array, sizeof(zend_op_array));
				zend_bailout();
			} zend_end_try();
			if (!Z_ISUNDEF(local_retval)) {
				ZVAL_COPY_VALUE(&retval, &local_retval);
			}
			EG(no_extensions) = 0;
			zend_destroy_static_vars(op_array);
			destroy_op_array(op_array);
			efree_size(op_array, sizeof(zend_op_array));
		}
	}
	if (!Z_ISUNDEF(retval)) {
		ZVAL_COPY_VALUE(return_value, &retval);
	} else if (EG(exception) == NULL) {
		ZVAL_NULL(return_value);
	}
}

/* Strict return-type verification for values the extension delivers on the
 * skip path (the engine never sees them, so it cannot verify). Intentionally
 * strict: where the proxy path would coerce a sloppy value under a coercive
 * caller, native interception throws TypeError instead (fail closed). The
 * coercion-compatible cases (identical types, int->float widening,
 * nullable null, subclass instances, matching union members) all pass.
 */
static bool wb_match_mask(zval *v, uint32_t mask)
{
	switch (Z_TYPE_P(v)) {
		case IS_NULL: return (mask & MAY_BE_NULL) != 0;
		case IS_FALSE: return (mask & (MAY_BE_FALSE | MAY_BE_BOOL)) != 0;
		case IS_TRUE: return (mask & (MAY_BE_TRUE | MAY_BE_BOOL)) != 0;
		case IS_LONG: return (mask & MAY_BE_LONG) != 0;
		case IS_DOUBLE: return (mask & MAY_BE_DOUBLE) != 0;
		case IS_STRING: return (mask & MAY_BE_STRING) != 0;
		case IS_ARRAY: return (mask & MAY_BE_ARRAY) != 0;
		case IS_OBJECT: return (mask & MAY_BE_OBJECT) != 0;
		case IS_RESOURCE: return (mask & MAY_BE_RESOURCE) != 0;
		case IS_REFERENCE: return wb_match_mask(Z_REFVAL_P(v), mask);
		default: return false;
	}
}

static zend_class_entry *wb_lookup_no_autoload(const char *name, size_t len)
{
	zend_string *lower;
	zend_class_entry *ce;
	char *buf = (char *) emalloc(len + 1);
	size_t i;
	for (i = 0; i < len; i++) {
		char c = name[i];
		buf[i] = (c >= 'A' && c <= 'Z') ? (char) (c + 32) : c;
	}
	buf[len] = '\0';
	lower = zend_string_init(buf, len, 0);
	efree(buf);
	/* Direct table hit: no autoloader runs, so verification can never
	 * re-enter user code. Unresolvable names fail closed below. */
	ce = (zend_class_entry *) zend_hash_str_find_ptr(CG(class_table), ZSTR_VAL(lower), ZSTR_LEN(lower));
	zend_string_release(lower);
	return ce;
}

static bool wb_match_named(zval *v, const char *name, size_t len, zend_function *func)
{
	zend_class_entry *ce;
	if (Z_TYPE_P(v) == IS_REFERENCE) {
		v = Z_REFVAL_P(v);
	}
	if (wb_name_is(name, len, "callable")) {
		return zend_is_callable(v, 0, NULL);
	}
	if (wb_name_is(name, len, "iterable")) {
		return Z_TYPE_P(v) == IS_ARRAY
			|| (Z_TYPE_P(v) == IS_OBJECT && instanceof_function(Z_OBJCE_P(v), zend_ce_traversable));
	}
	if (wb_name_is(name, len, "self")) {
		ce = func->common.scope;
	} else if (wb_name_is(name, len, "parent")) {
		ce = (func->common.scope != NULL) ? func->common.scope->parent : NULL;
	} else if (wb_name_is(name, len, "static")) {
		/* Approximation: the defining scope, not the late-static callee.
		 * Documented; satisfies every non-contrived shape. */
		ce = func->common.scope;
	} else {
		ce = wb_lookup_no_autoload(name, len);
	}
	if (ce == NULL || Z_TYPE_P(v) != IS_OBJECT) {
		return false;
	}
	return instanceof_function(Z_OBJCE_P(v), ce);
}

static bool wb_match_one(zval *v, const zend_type *t, zend_function *func);

static bool wb_match_type(zval *v, zend_type t, zend_function *func)
{
	if (Z_TYPE_P(v) == IS_NULL && ZEND_TYPE_ALLOW_NULL(t)) {
		return true;
	}
	if (ZEND_TYPE_HAS_LIST(t)) {
		zend_type_list *list = ZEND_TYPE_LIST(t);
		if (ZEND_TYPE_IS_INTERSECTION(t)) {
			const zend_type *member = NULL;
			ZEND_TYPE_LIST_FOREACH(list, member) {
				if (!wb_match_one(v, member, func)) {
					return false;
				}
			} ZEND_TYPE_LIST_FOREACH_END();
			return true;
		}
		{
			const zend_type *member = NULL;
			ZEND_TYPE_LIST_FOREACH(list, member) {
				if (wb_match_one(v, member, func)) {
					return true;
				}
			} ZEND_TYPE_LIST_FOREACH_END();
		}
		return false;
	}
	return wb_match_one(v, &t, func);
}

static bool wb_match_one(zval *v, const zend_type *t, zend_function *func)
{
	uint32_t mask = ZEND_TYPE_PURE_MASK(*t);
	if (mask != 0 && !wb_match_mask(v, mask)) {
		/* int->float widening is allowed in both coercive and strict mode. */
		if (!(Z_TYPE_P(v) == IS_LONG && (mask & MAY_BE_DOUBLE) != 0)) {
			return false;
		}
	}
	if (ZEND_TYPE_HAS_NAME(*t)) {
		zend_string *name = ZEND_TYPE_NAME(*t);
		if (!wb_match_named(v, ZSTR_VAL(name), ZSTR_LEN(name), func)) {
			return false;
		}
	} else if (ZEND_TYPE_HAS_LITERAL_NAME(*t)) {
		const char *name = ZEND_TYPE_LITERAL_NAME(*t);
		if (!wb_match_named(v, name, strlen(name), func)) {
			return false;
		}
	}
	return true;
}

static void wb_verify_skip_value(zend_function *func, zval *v)
{
	zend_arg_info *ret;
	zend_type t;
	uint32_t mask;
	const char *cls, *mth;

	if ((func->common.fn_flags & ZEND_ACC_HAS_RETURN_TYPE) == 0
		|| func->common.arg_info == NULL) {
		return;
	}
	ret = func->common.arg_info - 1;
	t = ret->type;
	if (!ZEND_TYPE_IS_SET(t)) {
		return;
	}
	cls = (func->common.scope != NULL && func->common.scope->name != NULL)
		? ZSTR_VAL(func->common.scope->name) : "{unknown}";
	mth = (func->common.function_name != NULL) ? ZSTR_VAL(func->common.function_name) : "{unknown}";
	mask = ZEND_TYPE_FULL_MASK(t);
	if ((mask & MAY_BE_NEVER) != 0) {
		zend_throw_error(zend_ce_type_error,
			"Return value of %s::%s() must never return a value", cls, mth);
		return;
	}
	if ((mask & MAY_BE_VOID) != 0) {
		if (Z_TYPE_P(v) != IS_NULL) {
			zend_throw_error(zend_ce_type_error,
				"Return value of %s::%s() must be of type void, %s given",
				cls, mth, zend_zval_value_name(v));
		}
		return;
	}
	if (!wb_match_type(v, t, func)) {
		zend_throw_error(zend_ce_type_error,
			"Return value of %s::%s() failed strict return-type verification, %s given",
			cls, mth, zend_zval_value_name(v));
	}
}

/* Call NativeAopDriver::{begin,finish}. On FAILURE throws Error fail-closed. */
static zend_result wb_driver_call(const char *fn, zval *params, uint32_t nparams, zval *retval)
{
	zval callable;
	zend_fcall_info fci;
	zend_fcall_info_cache fcc;
	char *error = NULL;
	zend_result res;

	ZVAL_STRING(&callable, fn);
	memset(&fci, 0, sizeof(fci));
	fci.size = sizeof(fci);
	memset(&fcc, 0, sizeof(fcc));
	ZVAL_UNDEF(retval);
	if (zend_fcall_info_init(&callable, 0, &fci, &fcc, NULL, &error) == SUCCESS) {
		fci.retval = retval;
		fci.params = params;
		fci.param_count = nparams;
		res = zend_call_function(&fci, &fcc);
		if (res == FAILURE && EG(exception) == NULL) {
			zend_throw_error(NULL, "winter_boot AOP driver call %s failed", fn);
		}
	} else {
		zend_throw_error(NULL, "winter_boot AOP driver unavailable: %s() is not callable", fn);
		res = FAILURE;
	}
	if (error != NULL) {
		efree(error);
	}
	zval_ptr_dtor(&callable);
	return res;
}

/* Snapshot call arguments the way func_get_args() would report them.
 *
 * Subtle engine fact this exists for: at zend_execute_ex entry the frame is
 * not initialized yet. Fixed parameters are pre-filled by the caller, but
 * variadic extras still sit unpacked in the overflow area past the CV and
 * temporary slots (packing is the ZEND_RECV_VARIADIC opcode's job, which has
 * not run). So fixed slots come from ZEND_CALL_ARG while extras come from
 * last_var + T. Verified empirically slot by slot; locked by 020 tests.
 *
 * References are separated (deref-copy), exactly like func_get_args():
 * aspects see a snapshot, and later body mutations never leak back.
 */
static void wb_arg_slot_copy(zval *dst, zval *src)
{
	if (Z_TYPE_P(src) == IS_REFERENCE) {
		ZVAL_COPY(dst, Z_REFVAL_P(src));
	} else {
		ZVAL_COPY(dst, src);
	}
}

static void wb_build_arg_array(zend_execute_data *ex, zval *out)
{
	uint32_t passed = ZEND_CALL_NUM_ARGS(ex);
	uint32_t fixed = 0;
	uint32_t base = 0;
	bool variadic = false;
	uint32_t i;
	if (ex->func->type == ZEND_USER_FUNCTION
		&& (ex->func->common.fn_flags & ZEND_ACC_VARIADIC) != 0) {
		/* op_array.num_args counts the non-pack parameters; extras sit
		 * past CVs and temporaries (packing is RECV_VARIADIC's job). */
		variadic = true;
		fixed = ex->func->common.num_args;
		base = (uint32_t) ex->func->op_array.last_var + ex->func->op_array.T;
	}
	array_init_size(out, passed);
	/* Single append pass: keeps the array packed exactly like the
	 * engine's own func_get_args() result. */
	for (i = 0; i < passed; i++) {
		zval tmp;
		if (variadic && i >= fixed) {
			wb_arg_slot_copy(&tmp, ZEND_CALL_VAR_NUM(ex, base + (i - fixed)));
		} else {
			wb_arg_slot_copy(&tmp, ZEND_CALL_ARG(ex, i + 1));
		}
		zend_hash_next_index_insert(Z_ARRVAL_P(out), &tmp);
	}
}

/* Balance the Zend Observer BEGIN the VM issued before our override ran.
 *
 * A frame that never executes never reaches ZEND_RETURN (the only
 * non-unwind site that fires OBSERVER_END) and is never unwound either —
 * the VM just frees it after we return. Without this call
 * EG(current_observed_frame) dangles at the freed frame; a second skipped
 * call links through reused stack memory and the shutdown walk spins or
 * crashes. The inline guard makes this a no-op unless our frame is still
 * the observed top (on the proceed path the body's own RETURN already
 * popped it, so only non-proceed paths call this). Our own end handler
 * tolerates the call: a skipped scope owns no defer frame. */
static void wb_close_skipped_frame(zend_execute_data *ex)
{
	/* Same convention as the VM's own post-call END: no value when an
	 * exception is in flight. */
	zend_observer_fcall_end(ex, EG(exception) ? NULL : ex->return_value);
}

/* Full interception of one advised call. Ownership: every zval taken here is
 * released on every path; the only references that escape are the engine's
 * own (return slot, EG(exception)) via the proven transfer idiom. */
static void wb_intercept(zend_execute_data *ex, wb_advice *ad)
{
	zval params[4], retval, exctx, interceptor, args;
	zval *zv;
	bool proceed;

	ZVAL_UNDEF(&retval);
	ZVAL_UNDEF(&exctx);
	ZVAL_UNDEF(&interceptor);
	array_init(&args);

	if (Z_TYPE(ex->This) == IS_OBJECT) {
		ZVAL_COPY(&params[0], &ex->This);
	} else {
		/* Static call: driver receives null (see plan: accepted delta vs the
		 * proxy's static-$this Error). */
		ZVAL_NULL(&params[0]);
	}
	if (ad->scope != NULL && ad->scope->name != NULL) {
		ZVAL_STR_COPY(&params[1], ad->scope->name);
	} else {
		ZVAL_EMPTY_STRING(&params[1]);
	}
	ZVAL_STR_COPY(&params[2], ad->func->common.function_name);
	wb_build_arg_array(ex, &args);
	ZVAL_COPY_VALUE(&params[3], &args);

	wb_driver_call(WB_AOP_DRIVER_BEGIN, params, 4, &retval);
	zval_ptr_dtor(&params[0]);
	zval_ptr_dtor(&params[1]);
	zval_ptr_dtor(&params[2]);
	zval_ptr_dtor(&args);
	if (EG(exception) != NULL) {
		/* aspectBegin threw: the driver's own frame unwound cleanly, but
		 * ours never ran — close it before propagating. */
		if (!Z_ISUNDEF(retval)) {
			zval_ptr_dtor(&retval);
		}
		wb_close_skipped_frame(ex);
		return;
	}
	if (Z_TYPE(retval) != IS_ARRAY) {
		zend_throw_error(NULL, "winter_boot AOP driver protocol violation: begin() must return an array");
		zval_ptr_dtor(&retval);
		wb_close_skipped_frame(ex);
		return;
	}
	zv = zend_hash_str_find(Z_ARRVAL(retval), "proceed", sizeof("proceed") - 1);
	proceed = (zv != NULL && zend_is_true(zv));
	if (!proceed) {
		zval *value = zend_hash_str_find(Z_ARRVAL(retval), "value", sizeof("value") - 1);
		if (value == NULL) {
			zend_throw_error(NULL, "winter_boot AOP driver protocol violation: skip needs a value");
			zval_ptr_dtor(&retval);
			wb_close_skipped_frame(ex);
			return;
		}
		wb_verify_skip_value(ad->func, value);
		if (EG(exception) == NULL && ex->return_value != NULL) {
			ZVAL_COPY(ex->return_value, value);
		}
		zval_ptr_dtor(&retval);
		wb_close_skipped_frame(ex);
		return;
	}
	zv = zend_hash_str_find(Z_ARRVAL(retval), "exCtx", sizeof("exCtx") - 1);
	if (zv == NULL || Z_TYPE_P(zv) != IS_OBJECT) {
		zend_throw_error(NULL, "winter_boot AOP driver protocol violation: proceed needs exCtx");
		zval_ptr_dtor(&retval);
		wb_close_skipped_frame(ex);
		return;
	}
	ZVAL_COPY(&exctx, zv);
	zv = zend_hash_str_find(Z_ARRVAL(retval), "interceptor", sizeof("interceptor") - 1);
	if (zv == NULL || Z_TYPE_P(zv) != IS_OBJECT) {
		zend_throw_error(NULL, "winter_boot AOP driver protocol violation: proceed needs interceptor");
		zval_ptr_dtor(&exctx);
		zval_ptr_dtor(&retval);
		wb_close_skipped_frame(ex);
		return;
	}
	ZVAL_COPY(&interceptor, zv);
	zval_ptr_dtor(&retval);

	wb_orig_execute_ex(ex);

	if (EG(exception) != NULL) {
		zend_object *thrown = EG(exception);
		zend_object *thrown_pe = EG(prev_exception);
		zval fin_params[4], fin_ret;
		/* Snapshot before zend_clear_exception(): it releases the
		 * exception AND rewinds EG(current_execute_data)->opline (the
		 * caller) to the throw-site bookmark. The caller's real opline
		 * must be restored or zend_rethrow_exception() derives a bogus
		 * bookmark from it and the caller's catch tables miss. */
		const zend_op *fresh_bookmark = EG(opline_before_exception);
		const zend_op *caller_opline = (EG(current_execute_data) != NULL)
			? EG(current_execute_data)->opline : NULL;
		GC_ADDREF(thrown);
		if (thrown_pe != NULL) {
			GC_ADDREF(thrown_pe);
		}
		zend_clear_exception();
		if (EG(current_execute_data) != NULL) {
			EG(current_execute_data)->opline = caller_opline;
		}
		ZVAL_UNDEF(&fin_ret);
		ZVAL_COPY_VALUE(&fin_params[0], &exctx);
		ZVAL_COPY_VALUE(&fin_params[1], &interceptor);
		ZVAL_STRING(&fin_params[2], "threw");
		ZVAL_OBJ_COPY(&fin_params[3], thrown);
		wb_driver_call(WB_AOP_DRIVER_FINISH, fin_params, 4, &fin_ret);
		zval_ptr_dtor(&fin_params[2]);
		zval_ptr_dtor(&fin_params[3]);
		if (!Z_ISUNDEF(fin_ret)) {
			zval_ptr_dtor(&fin_ret);
		}
		if (EG(exception) != NULL) {
			/* Driver malfunctioned while handling failure: loud wins over
			 * the original; release the superseded refs. */
			if (thrown_pe != NULL) {
				OBJ_RELEASE(thrown_pe);
			}
			OBJ_RELEASE(thrown);
		} else {
			/* Transfer our refs to the engine (proven transfer idiom). */
			EG(exception) = thrown;
			EG(prev_exception) = thrown_pe;
		}
		/* Belt and braces: the caller opline above is what
		 * zend_rethrow_exception() reads, but restore the fresh
		 * throw-site bookmark too in case any path consults it first. */
		EG(opline_before_exception) = fresh_bookmark;
	} else {
		zval slot_copy, fin_params[4], fin_ret;
		ZVAL_UNDEF(&fin_ret);
		if (ex->return_value != NULL && Z_TYPE_P(ex->return_value) != IS_UNDEF) {
			if (Z_TYPE_P(ex->return_value) == IS_REFERENCE) {
				ZVAL_COPY(&slot_copy, Z_REFVAL_P(ex->return_value));
			} else {
				ZVAL_COPY(&slot_copy, ex->return_value);
			}
		} else {
			ZVAL_NULL(&slot_copy);
		}
		ZVAL_COPY_VALUE(&fin_params[0], &exctx);
		ZVAL_COPY_VALUE(&fin_params[1], &interceptor);
		ZVAL_STRING(&fin_params[2], "returned");
		ZVAL_COPY_VALUE(&fin_params[3], &slot_copy);
		wb_driver_call(WB_AOP_DRIVER_FINISH, fin_params, 4, &fin_ret);
		zval_ptr_dtor(&fin_params[2]);
		zval_ptr_dtor(&slot_copy);
		if (!Z_ISUNDEF(fin_ret)) {
			zval_ptr_dtor(&fin_ret);
		}
		/* The original frame already delivered the verified return slot;
		 * commit cannot alter results (protocol), so nothing is written. */
	}
	zval_ptr_dtor(&exctx);
	zval_ptr_dtor(&interceptor);
}

static void wb_aop_execute_ex(zend_execute_data *ex)
{
	HashTable *map = wb_advice_map();
	wb_advice *ad = NULL;

	if (map != NULL && ex != NULL && ex->func != NULL
		&& ZEND_USER_CODE(ex->func->type)) {
		ad = (wb_advice *) zend_hash_index_find_ptr(map, (zend_ulong)(uintptr_t) ex->func);
		if (ad != NULL && (ad->func != ex->func
			|| ad->scope != ex->func->common.scope
			|| ex->func->common.function_name == NULL
			|| !zend_string_equals(ad->method_name, ex->func->common.function_name))) {
			/* Recycled address or stale entry: never advise the wrong method. */
			ad = NULL;
		} else if (ad != NULL && Z_TYPE(ex->This) == IS_OBJECT
			&& !instanceof_function(Z_OBJCE(ex->This), ad->bean_ce)) {
			/* Inherited op_array shared with the parent: only the advised
			 * bean family intercepts (see plan). */
			ad = NULL;
		}
	}
	if (ad == NULL) {
		wb_orig_execute_ex(ex);
		return;
	}
	wb_intercept(ex, ad);
}

static PHP_GINIT_FUNCTION(winter_boot)
{
#if defined(COMPILE_DL_WINTER_BOOT) && defined(ZTS)
	ZEND_TSRMLS_CACHE_UPDATE();
#endif
	winter_boot_globals->frames = NULL;
	winter_boot_globals->advice_map = NULL;
}

static PHP_MINIT_FUNCTION(winter_boot)
{
	zend_observer_fcall_register(wb_observer_init);
	wb_orig_execute_ex = zend_execute_ex;
	zend_execute_ex = wb_aop_execute_ex;
	return SUCCESS;
}

static PHP_MSHUTDOWN_FUNCTION(winter_boot)
{
	zend_execute_ex = wb_orig_execute_ex;
	return SUCCESS;
}

static PHP_RINIT_FUNCTION(winter_boot)
{
	wb_frames_set(NULL);
	wb_advice_map_set(NULL);
	return SUCCESS;
}

static PHP_RSHUTDOWN_FUNCTION(winter_boot)
{
	/* Scopes that never exited (fatal error paths) are freed without
	 * executing: after a fatal there is no safe engine state left to
	 * run user callbacks in. */
	wb_frames_free_all();
	/* Advice entries hold no owned engine references, so freeing the map
	 * is safe at any shutdown point. */
	wb_advice_map_free();
	return SUCCESS;
}

static PHP_MINFO_FUNCTION(winter_boot)
{
	php_info_print_table_start();
	php_info_print_table_header(2, "winter_boot support", "enabled");
	php_info_print_table_row(2, "Version", PHP_WINTER_BOOT_VERSION);
	php_info_print_table_row(2, "deferred() semantics", "LIFO on owning-function exit; runs on return and exception unwind");
	php_info_print_table_row(2, "defered()", "alias of deferred()");
	php_info_print_table_row(2, "native AOP", "zend_execute_ex interception; register via winter_boot_advise()");
	php_info_print_table_end();
}

static const zend_function_entry winter_boot_functions[] = {
	PHP_FE(deferred, arginfo_deferred)
	PHP_FE(defered, arginfo_deferred)
	PHP_FE(winter_boot_advise, arginfo_winter_boot_advise)
	PHP_FE(winter_boot_is_advised, arginfo_winter_boot_is_advised)
	PHP_FE(winter_boot_exec_inline, arginfo_winter_boot_exec_inline)
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
