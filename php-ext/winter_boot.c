/*
 * winter_boot extension — native capabilities for PHP.
 *
 * Capabilities: native AOP method interception via
 * winter_boot_advise()/winter_boot_is_advised(); `#{...}` template-code
 * evaluation via winter_boot_exec_inline() (with a compilation cache); and
 * single-call template substitution via winter_boot_expand_template().
 * The extension is structured so further native capabilities can be added
 * alongside these.
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
#include "Zend/zend_closures.h"

ZEND_DECLARE_MODULE_GLOBALS(winter_boot);

/* ---- native AOP interception ----
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
	/* Every bean class advised on this op_array. An inherited method shares
	 * its parent's op_array, so sibling beans register into one entry. */
	zend_class_entry **bean_ces;
	uint32_t bean_count;
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
			efree(ad->bean_ces);
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

ZEND_BEGIN_ARG_INFO_EX(arginfo_winter_boot_expand_template, 0, 0, 2)
	ZEND_ARG_TYPE_INFO(0, template, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, pairs, IS_ARRAY, 0)
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
	ad = (wb_advice *) zend_hash_index_find_ptr(map, (zend_ulong)(uintptr_t) func);
	if (ad != NULL && (ad->func != func || ad->scope != func->common.scope
		|| !zend_string_equals(ad->method_name, func->common.function_name))) {
		/* Stale entry for a recycled address: start over. */
		zend_string_release(ad->method_name);
		efree(ad->bean_ces);
		efree(ad);
		zend_hash_index_del(map, (zend_ulong)(uintptr_t) func);
		ad = NULL;
	}
	if (ad == NULL) {
		ad = (wb_advice *) emalloc(sizeof(wb_advice));
		ad->func = func;
		ad->scope = func->common.scope;
		ad->method_name = zend_string_copy(func->common.function_name);
		ad->bean_ces = NULL;
		ad->bean_count = 0;
		zend_hash_index_update_ptr(map, (zend_ulong)(uintptr_t) func, ad);
	}
	{
		/* Idempotent: re-advising the same bean class adds nothing. */
		uint32_t i;
		for (i = 0; i < ad->bean_count; i++) {
			if (ad->bean_ces[i] == ce) {
				return;
			}
		}
		ad->bean_ces = (zend_class_entry **) erealloc(
			ad->bean_ces, sizeof(zend_class_entry *) * (ad->bean_count + 1));
		ad->bean_ces[ad->bean_count++] = ce;
	}
}

/* The advised bean class a call belongs to: an exact match first, then the
 * first registered ancestor of the runtime class, or NULL when the class is
 * outside every advised bean family. */
static zend_class_entry *wb_advice_bean_for(wb_advice *ad, zend_class_entry *runtime_ce)
{
	uint32_t i;
	if (runtime_ce == NULL) {
		return NULL;
	}
	for (i = 0; i < ad->bean_count; i++) {
		if (ad->bean_ces[i] == runtime_ce) {
			return runtime_ce;
		}
	}
	for (i = 0; i < ad->bean_count; i++) {
		if (instanceof_function(runtime_ce, ad->bean_ces[i])) {
			return ad->bean_ces[i];
		}
	}
	return NULL;
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

/* Compilation cache for inline code.
 *
 * Cache keys (code, caller scope, compile-time namespace) exist because all
 * three shape the compiled op_array: the scope is installed on the op_array
 * for private-member access, and unqualified names (notably `::class`)
 * resolve against the compile-time namespace. A stored scope pointer is
 * never trusted blindly: on a hit it must still be the live class-table
 * entry for its name, otherwise the entry is treated as stale and replaced.
 * Entries whose op_array owns static vars are never cached — that storage
 * lives on the op_array, so sharing it would leak state across calls
 * (today every call compiles fresh). The list is bounded and fails open:
 * once full, new code simply compiles every time. The cache lives in
 * module globals, so ZTS threads each keep their own and no locking is
 * needed; entries are freed at request shutdown. */
typedef struct _wb_code_entry {
	zend_string *code;
	zend_string *scope_name; /* owned copy of scope->name, or NULL */
	zend_class_entry *scope; /* scope at insert; verified live on hit */
	zend_string *ns_name; /* owned copy of the compile-time namespace, or NULL */
	zend_op_array *op_array;
	struct _wb_code_entry *next;
} wb_code_entry;

#define WB_CODE_CACHE_MAX 256

static wb_code_entry *wb_code_cache(void)
{
	return (wb_code_entry *) WB_G(code_cache);
}

static void wb_code_cache_set(wb_code_entry *head)
{
	WB_G(code_cache) = (void *) head;
}

/* True when `ce` is still the live class-table entry for `name`. The
 * stored pointer itself is only compared, never dereferenced, until this
 * check passes. */
static bool wb_scope_is_live(zend_class_entry *ce, zend_string *name)
{
	zend_string *lower;
	zend_class_entry *live;
	bool ok;
	if (ce == NULL || name == NULL) {
		return ce == NULL && name == NULL;
	}
	lower = zend_string_tolower(name);
	live = (zend_class_entry *) zend_hash_find_ptr(CG(class_table), lower);
	zend_string_release(lower);
	ok = (live == ce);
	if (ok) {
		ok = (ce->name != NULL && zend_string_equals(ce->name, name));
	}
	return ok;
}

static bool wb_ns_matches(zend_string *a, zend_string *b)
{
	if (a == NULL || b == NULL) {
		return a == b;
	}
	return zend_string_equals(a, b) != 0;
}

static void wb_code_entry_free(wb_code_entry *e)
{
	if (e->code != NULL) {
		zend_string_release(e->code);
	}
	if (e->scope_name != NULL) {
		zend_string_release(e->scope_name);
	}
	if (e->ns_name != NULL) {
		zend_string_release(e->ns_name);
	}
	if (e->op_array != NULL) {
		destroy_op_array(e->op_array);
		efree_size(e->op_array, sizeof(zend_op_array));
	}
	efree(e);
}

static void wb_code_cache_free_all(void)
{
	wb_code_entry *e = wb_code_cache();
	wb_code_cache_set(NULL);
	while (e != NULL) {
		wb_code_entry *next = e->next;
		wb_code_entry_free(e);
		e = next;
	}
}

static wb_code_entry *wb_code_cache_find(
	const char *code, size_t code_len,
	zend_class_entry *scope, zend_string *scope_name, zend_string *ns)
{
	wb_code_entry *e = wb_code_cache();
	while (e != NULL) {
		if (ZSTR_LEN(e->code) == code_len
			&& memcmp(ZSTR_VAL(e->code), code, code_len) == 0
			&& wb_ns_matches(e->ns_name, ns)
			&& ((e->scope == NULL && scope == NULL)
				|| (e->scope != NULL && scope != NULL
					&& zend_string_equals(e->scope_name, scope_name)))) {
			if (wb_scope_is_live(e->scope, e->scope_name)) {
				return e;
			}
			return NULL;
		}
		e = e->next;
	}
	return NULL;
}

/* Store a freshly compiled op_array. A stale entry for the same key (scope
 * pointer recycled after a class redefinition) is replaced; when the list
 * is full the op_array is left for the caller to execute-and-discard. */
static void wb_code_cache_store(
	const char *code, size_t code_len,
	zend_class_entry *scope, zend_string *scope_name, zend_string *ns,
	zend_op_array *op_array)
{
	wb_code_entry *head = wb_code_cache();
	wb_code_entry *e = head;
	wb_code_entry **link = &head;
	size_t count = 0;
	while (e != NULL) {
		count++;
		if (ZSTR_LEN(e->code) == code_len
			&& memcmp(ZSTR_VAL(e->code), code, code_len) == 0
			&& wb_ns_matches(e->ns_name, ns)
			&& ((e->scope_name == NULL && scope_name == NULL)
				|| (e->scope_name != NULL && scope_name != NULL
					&& zend_string_equals(e->scope_name, scope_name)))) {
			*link = e->next;
			wb_code_entry_free(e);
			count--;
			break;
		}
		link = &e->next;
		e = e->next;
	}
	if (count >= WB_CODE_CACHE_MAX) {
		wb_code_cache_set(head);
		return;
	}
	e = (wb_code_entry *) emalloc(sizeof(wb_code_entry));
	e->code = zend_string_init(code, code_len, 0);
	e->scope_name = (scope_name != NULL) ? zend_string_copy(scope_name) : NULL;
	e->scope = scope;
	e->ns_name = (ns != NULL) ? zend_string_copy(ns) : NULL;
	e->op_array = op_array;
	e->next = head;
	wb_code_cache_set(e);
}

/* Compile inline code exactly as before (caller shapes `CG(compiler_options)`
 * handling is the caller's job). Returns NULL with the engine exception set
 * on failure, mirroring eval(). */
static zend_op_array *wb_compile_inline(const char *code, size_t code_len)
{
	uint32_t original_compiler_options = CG(compiler_options);
	zend_string *code_str = zend_string_init(code, code_len, 0);
	zend_op_array *op_array;
	CG(compiler_options) = ZEND_COMPILE_DEFAULT_FOR_EVAL;
	op_array = zend_compile_string(code_str, "AOP inline code",
		ZEND_COMPILE_POSITION_AFTER_OPEN_TAG);
	CG(compiler_options) = original_compiler_options;
	zend_string_release(code_str);
	return op_array;
}

/* Execute a compiled inline op_array under the caller's scope. Shared by the
 * cached and freshly-compiled paths; the op_array is never consumed here. */
static void wb_execute_inline_op(zend_op_array *op_array, zval *retval)
{
	zval local_retval;
	EG(no_extensions) = 1;
	zend_try {
		ZVAL_UNDEF(&local_retval);
		zend_execute(op_array, &local_retval);
	} zend_catch {
		EG(no_extensions) = 0;
		zend_bailout();
	} zend_end_try();
	if (!Z_ISUNDEF(local_retval)) {
		ZVAL_COPY_VALUE(retval, &local_retval);
	}
	EG(no_extensions) = 0;
}

/* Evaluate AOP `#{...}` inline code with the given variables bound.
 *
 * Native counterpart of the former eval()-based template expansion, so no
 * PHP-level eval (and no variable-variables) remain in the framework. The
 * variables are installed into the *caller* frame's symbol table — exactly
 * where the old `$$name` injection put them, and the table the executed
 * code frame shares. Integer keys and invalid names are ignored; the two
 * reserved names that shadowed the old wrapper's own locals are skipped.
 * Compilations are cached (see above), so repeated template evaluations
 * skip recompilation with identical results. Compile/runtime failures
 * propagate to the caller unchanged (the framework wraps them, as before).
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
	 * in a second return and fail to compile. Repeat compilations come
	 * from the cache above; anything uncacheable executes exactly as
	 * before (fresh op_array, destroyed after use). */
	ZVAL_UNDEF(&retval);
	{
		zend_class_entry *caller_scope = caller->func->common.scope;
		zend_string *caller_ns = CG(file_context).current_namespace;
		wb_code_entry *hit = wb_code_cache_find(
			code, code_len, caller_scope,
			(caller_scope != NULL) ? caller_scope->name : NULL, caller_ns);
		if (hit != NULL) {
			wb_execute_inline_op(hit->op_array, &retval);
		} else {
			zend_op_array *op_array = wb_compile_inline(code, code_len);
			if (op_array != NULL) {
				op_array->scope = caller_scope;
				wb_execute_inline_op(op_array, &retval);
				if (op_array->static_variables == NULL) {
					wb_code_cache_store(code, code_len, caller_scope,
						(caller_scope != NULL) ? caller_scope->name : NULL,
						caller_ns, op_array);
				} else {
					zend_destroy_static_vars(op_array);
					destroy_op_array(op_array);
					efree_size(op_array, sizeof(zend_op_array));
				}
			}
		}
	}
	if (!Z_ISUNDEF(retval)) {
		ZVAL_COPY_VALUE(return_value, &retval);
	} else if (EG(exception) == NULL) {
		ZVAL_NULL(return_value);
	}
}

/* Single-literal replace-all used by winter_boot_expand_template().
 * Advances past inserted text (no rescan), mirroring one str_replace()
 * pass for a single search element. */
static zend_string *wb_replace_all(
	zend_string *subject, zend_string *search, zend_string *replace)
{
	const char *s = ZSTR_VAL(subject);
	size_t s_len = ZSTR_LEN(subject);
	const char *p = ZSTR_VAL(search);
	size_t p_len = ZSTR_LEN(search);
	const char *r = ZSTR_VAL(replace);
	size_t r_len = ZSTR_LEN(replace);
	size_t count = 0;
	size_t off = 0;
	zend_string *out;
	char *dst;
	if (p_len == 0 || p_len > s_len) {
		return zend_string_copy(subject);
	}
	while (off + p_len <= s_len) {
		const char *at = (const char *) memchr(s + off, p[0], s_len - off - p_len + 1);
		if (at == NULL) {
			break;
		}
		if (p_len == 1 || memcmp(at, p, p_len) == 0) {
			count++;
			off = (size_t) (at - s) + p_len;
		} else {
			off = (size_t) (at - s) + 1;
		}
	}
	if (count == 0) {
		return zend_string_copy(subject);
	}
	{
		size_t new_len;
		if (r_len >= p_len) {
			new_len = s_len + count * (r_len - p_len);
		} else {
			new_len = s_len - count * (p_len - r_len);
		}
		out = zend_string_alloc(new_len, 0);
	}
	dst = ZSTR_VAL(out);
	off = 0;
	while (off < s_len) {
		const char *at = NULL;
		if (off + p_len <= s_len) {
			const char *cand = (const char *) memchr(s + off, p[0], s_len - off - p_len + 1);
			if (cand != NULL && (p_len == 1 || memcmp(cand, p, p_len) == 0)) {
				at = cand;
			} else if (cand != NULL) {
				/* First-char hit that is not a full match: copy through
				 * the false start and keep scanning from there. */
				size_t keep = (size_t) (cand - (s + off)) + 1;
				memcpy(dst, s + off, keep);
				dst += keep;
				off += keep;
				continue;
			}
		}
		if (at == NULL) {
			memcpy(dst, s + off, s_len - off);
			dst += s_len - off;
			break;
		}
		{
			size_t keep = (size_t) (at - (s + off));
			memcpy(dst, s + off, keep);
			dst += keep;
			memcpy(dst, r, r_len);
			dst += r_len;
			off += keep + p_len;
		}
	}
	*dst = '\0';
	return out;
}

/* Expand `#{...}` / `${...}` template placeholders in one call.
 *
 * Native counterpart of the str_replace($search, $replace, $template) tail
 * of the framework's template expansion: pairs map literal placeholder
 * text to its already-evaluated value. Substitution is sequential in pair
 * order with all occurrences replaced per pair and no rescan of inserted
 * text within a pair — exactly one str_replace() pass per element, so a
 * replacement that itself contains a later placeholder re-expands, as
 * before. Values coerce via zval_get_string (the same conversion
 * str_replace() applies: scalars, null, objects with __toString; Error on
 * unconvertible objects) and are all converted upfront in order, so a bad
 * value throws before any substitution, as before. Integer keys stringify
 * like str_replace() search elements. */
PHP_FUNCTION(winter_boot_expand_template)
{
	char *tpl;
	size_t tpl_len;
	HashTable *pairs;
	zend_string *result;
	zend_string *key;
	zval *val;
	zend_ulong idx;
	uint32_t n;

	ZEND_PARSE_PARAMETERS_START(2, 2)
		Z_PARAM_STRING(tpl, tpl_len)
		Z_PARAM_ARRAY_HT(pairs)
	ZEND_PARSE_PARAMETERS_END();

	n = zend_hash_num_elements(pairs);
	if (tpl_len == 0 || n == 0) {
		RETURN_STRINGL(tpl, tpl_len);
	}

	result = zend_string_init(tpl, tpl_len, 0);
	{
		zend_string **searches = (zend_string **) safe_emalloc(n, sizeof(zend_string *), 0);
		zend_string **replaces = (zend_string **) safe_emalloc(n, sizeof(zend_string *), 0);
		uint32_t i = 0;
		bool failed = false;
		ZEND_HASH_FOREACH_KEY_VAL(pairs, idx, key, val) {
			zval *v = val;
			if (Z_TYPE_P(v) == IS_REFERENCE) {
				v = Z_REFVAL_P(v);
			}
			if (key != NULL) {
				searches[i] = zend_string_copy(key);
			} else {
				char buf[32];
				int blen = snprintf(buf, sizeof(buf), ZEND_LONG_FMT, (zend_long) idx);
				searches[i] = zend_string_init(buf, (size_t) blen, 0);
			}
			replaces[i] = zval_get_string(v);
			if (EG(exception) != NULL || replaces[i] == NULL) {
				if (replaces[i] != NULL) {
					zend_string_release(replaces[i]);
				}
				zend_string_release(searches[i]);
				failed = true;
				break;
			}
			i++;
		} ZEND_HASH_FOREACH_END();
		if (failed) {
			while (i > 0) {
				i--;
				zend_string_release(searches[i]);
				zend_string_release(replaces[i]);
			}
			efree(searches);
			efree(replaces);
			zend_string_release(result);
			RETURN_THROWS();
		}
		{
			uint32_t k;
			for (k = 0; k < n; k++) {
				zend_string *next = wb_replace_all(result, searches[k], replaces[k]);
				zend_string_release(result);
				zend_string_release(searches[k]);
				zend_string_release(replaces[k]);
				result = next;
			}
		}
		efree(searches);
		efree(replaces);
	}
	RETURN_STR(result);
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
		/* MAY_BE_BOOL is MAY_BE_FALSE|MAY_BE_TRUE: test the exact bit, or
		 * string|false would accept true. */
		case IS_FALSE: return (mask & MAY_BE_FALSE) != 0;
		case IS_TRUE: return (mask & MAY_BE_TRUE) != 0;
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

static bool wb_match_named(zval *v, const char *name, size_t len, zend_function *func, zend_class_entry *called)
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
		ce = (called != NULL) ? called : func->common.scope;
	} else {
		ce = wb_lookup_no_autoload(name, len);
	}
	if (ce == NULL || Z_TYPE_P(v) != IS_OBJECT) {
		return false;
	}
	return instanceof_function(Z_OBJCE_P(v), ce);
}

/* Builtin part of a type: the pure mask, plus the pseudo-types that live in
 * it as bits (callable, static) and int->float widening, which is allowed in
 * both coercive and strict mode. */
static bool wb_match_builtin(zval *v, uint32_t mask, zend_function *func, zend_class_entry *called)
{
	if (mask == 0) {
		return false;
	}
	if (wb_match_mask(v, mask)) {
		return true;
	}
	if (Z_TYPE_P(v) == IS_LONG && (mask & MAY_BE_DOUBLE) != 0) {
		return true;
	}
	if ((mask & MAY_BE_CALLABLE) != 0 && zend_is_callable(v, 0, NULL)) {
		return true;
	}
	if ((mask & MAY_BE_STATIC) != 0) {
		zend_class_entry *ce = (called != NULL) ? called : func->common.scope;
		return ce != NULL && Z_TYPE_P(v) == IS_OBJECT && instanceof_function(Z_OBJCE_P(v), ce);
	}
	return false;
}

/* A type matches when ANY of its parts does: the builtin mask, the single
 * class name, or the type list. A union list matches when any member does
 * (a DNF member is itself an intersection list, so this recurses); an
 * intersection list only when every member does. */
static bool wb_match_type(zval *v, const zend_type *t, zend_function *func, zend_class_entry *called)
{
	if (Z_TYPE_P(v) == IS_REFERENCE) {
		v = Z_REFVAL_P(v);
	}
	if (wb_match_builtin(v, ZEND_TYPE_PURE_MASK(*t), func, called)) {
		return true;
	}
	if (ZEND_TYPE_HAS_LIST(*t)) {
		const zend_type *member = NULL;
		if (ZEND_TYPE_IS_INTERSECTION(*t)) {
			ZEND_TYPE_LIST_FOREACH(ZEND_TYPE_LIST(*t), member) {
				if (!wb_match_type(v, member, func, called)) {
					return false;
				}
			} ZEND_TYPE_LIST_FOREACH_END();
			return true;
		}
		ZEND_TYPE_LIST_FOREACH(ZEND_TYPE_LIST(*t), member) {
			if (wb_match_type(v, member, func, called)) {
				return true;
			}
		} ZEND_TYPE_LIST_FOREACH_END();
		return false;
	}
	if (ZEND_TYPE_HAS_NAME(*t)) {
		zend_string *name = ZEND_TYPE_NAME(*t);
		return wb_match_named(v, ZSTR_VAL(name), ZSTR_LEN(name), func, called);
	}
	if (ZEND_TYPE_HAS_LITERAL_NAME(*t)) {
		const char *name = ZEND_TYPE_LITERAL_NAME(*t);
		return wb_match_named(v, name, strlen(name), func, called);
	}
	return false;
}

static void wb_verify_skip_value(zend_function *func, zend_class_entry *called, zval *v)
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
	if (!wb_match_type(v, &t, func, called)) {
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

/* Leave a frame whose body never ran (skip value, begin() threw, protocol
 * violation). The callee owns this work, not the caller: for a frame entered
 * through an overridden zend_execute_ex the VM marks it ZEND_CALL_TOP, and
 * its caller (DO_FCALL / zend_call_function) only releases $this and pops
 * the frame. So this mirrors the TOP branch of zend_leave_helper.
 *
 * - With an exception pending the return slot is set UNDEF, as the engine's
 *   own uncaught-exception path does: the caller's HANDLE_EXCEPTION destroys
 *   the DO_FCALL result slot, which otherwise still holds a stale temporary.
 * - The Zend Observer BEGIN the VM issued before our override ran is
 *   balanced (a skipped frame never reaches ZEND_RETURN). This extension
 *   registers no observer itself; it matters when another one does
 *   (OpenTelemetry, Xdebug, profilers). The END is a no-op unless our frame
 *   is still the observed top.
 * - Argument CVs, extra positional args and extra named params are released,
 *   and EG(current_execute_data) is restored to the caller; leaving it on
 *   this frame sends the caller's next exception to a dead frame.
 */
static void wb_leave_skipped_frame(zend_execute_data *ex)
{
	uint32_t call_info;

	if (EG(exception) != NULL && ex->return_value != NULL) {
		ZVAL_UNDEF(ex->return_value);
	}
	zend_observer_fcall_end(ex, EG(exception) ? NULL : ex->return_value);

	EG(current_execute_data) = ex->prev_execute_data;
	zend_free_compiled_variables(ex);
	/* Re-read: destructors run by the line above may change the flags. */
	call_info = ZEND_CALL_INFO(ex);
	if (call_info & ZEND_CALL_HAS_SYMBOL_TABLE) {
		zend_clean_and_cache_symbol_table(ex->symbol_table);
	}
	zend_vm_stack_free_extra_args_ex(call_info, ex);
	if (call_info & ZEND_CALL_HAS_EXTRA_NAMED_PARAMS) {
		zend_free_extra_named_params(ex->extra_named_params);
	}
	if (call_info & ZEND_CALL_CLOSURE) {
		OBJ_RELEASE(ZEND_CLOSURE_OBJECT(ex->func));
	}
}

/* Full interception of one advised call. Ownership: every zval taken here is
 * released on every path; the only references that escape are the engine's
 * own (return slot, EG(exception)) via the proven transfer idiom. */
static void wb_intercept(zend_execute_data *ex, wb_advice *ad, zend_class_entry *bean_ce)
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
	/* The bean class, not the declaring one: interceptors and async jobs
	 * are keyed by the bean (an inherited method declares on its parent). */
	if (bean_ce != NULL && bean_ce->name != NULL) {
		ZVAL_STR_COPY(&params[1], bean_ce->name);
	} else if (ad->scope != NULL && ad->scope->name != NULL) {
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
		 * ours never ran — leave it before propagating. */
		if (!Z_ISUNDEF(retval)) {
			zval_ptr_dtor(&retval);
		}
		wb_leave_skipped_frame(ex);
		return;
	}
	if (Z_TYPE(retval) != IS_ARRAY) {
		zend_throw_error(NULL, "winter_boot AOP driver protocol violation: begin() must return an array");
		zval_ptr_dtor(&retval);
		wb_leave_skipped_frame(ex);
		return;
	}
	zv = zend_hash_str_find(Z_ARRVAL(retval), "proceed", sizeof("proceed") - 1);
	proceed = (zv != NULL && zend_is_true(zv));
	if (!proceed) {
		zval *value = zend_hash_str_find(Z_ARRVAL(retval), "value", sizeof("value") - 1);
		if (value == NULL) {
			zend_throw_error(NULL, "winter_boot AOP driver protocol violation: skip needs a value");
			zval_ptr_dtor(&retval);
			wb_leave_skipped_frame(ex);
			return;
		}
		wb_verify_skip_value(ad->func,
			(Z_TYPE(ex->This) == IS_OBJECT) ? Z_OBJCE(ex->This) : Z_CE(ex->This), value);
		if (EG(exception) == NULL && ex->return_value != NULL) {
			ZVAL_COPY(ex->return_value, value);
		}
		zval_ptr_dtor(&retval);
		wb_leave_skipped_frame(ex);
		return;
	}
	zv = zend_hash_str_find(Z_ARRVAL(retval), "exCtx", sizeof("exCtx") - 1);
	if (zv == NULL || Z_TYPE_P(zv) != IS_OBJECT) {
		zend_throw_error(NULL, "winter_boot AOP driver protocol violation: proceed needs exCtx");
		zval_ptr_dtor(&retval);
		wb_leave_skipped_frame(ex);
		return;
	}
	ZVAL_COPY(&exctx, zv);
	zv = zend_hash_str_find(Z_ARRVAL(retval), "interceptor", sizeof("interceptor") - 1);
	if (zv == NULL || Z_TYPE_P(zv) != IS_OBJECT) {
		zend_throw_error(NULL, "winter_boot AOP driver protocol violation: proceed needs interceptor");
		zval_ptr_dtor(&exctx);
		zval_ptr_dtor(&retval);
		wb_leave_skipped_frame(ex);
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
	zend_class_entry *bean_ce = NULL;

	if (map != NULL && ex != NULL && ex->func != NULL
		&& ZEND_USER_CODE(ex->func->type)) {
		ad = (wb_advice *) zend_hash_index_find_ptr(map, (zend_ulong)(uintptr_t) ex->func);
		if (ad != NULL && (ad->func != ex->func
			|| ad->scope != ex->func->common.scope
			|| ex->func->common.function_name == NULL
			|| !zend_string_equals(ad->method_name, ex->func->common.function_name))) {
			/* Recycled address or stale entry: never advise the wrong method. */
			ad = NULL;
		} else if (ad != NULL && Z_TYPE(ex->This) == IS_OBJECT) {
			/* Inherited op_array shared with the parent: only the advised
			 * bean families intercept (see plan). */
			bean_ce = wb_advice_bean_for(ad, Z_OBJCE(ex->This));
			if (bean_ce == NULL) {
				ad = NULL;
			}
		} else if (ad != NULL) {
			/* Static call: name the called bean when it is one; otherwise
			 * the declaring class, as before. */
			bean_ce = wb_advice_bean_for(ad, Z_CE(ex->This));
		}
	}
	if (ad == NULL) {
		wb_orig_execute_ex(ex);
		return;
	}
	wb_intercept(ex, ad, bean_ce);
}

static PHP_GINIT_FUNCTION(winter_boot)
{
#if defined(COMPILE_DL_WINTER_BOOT) && defined(ZTS)
	ZEND_TSRMLS_CACHE_UPDATE();
#endif
	winter_boot_globals->advice_map = NULL;
	winter_boot_globals->code_cache = NULL;
}

static PHP_MINIT_FUNCTION(winter_boot)
{
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
	wb_advice_map_set(NULL);
	wb_code_cache_set(NULL);
	return SUCCESS;
}

static PHP_RSHUTDOWN_FUNCTION(winter_boot)
{
	/* Advice entries hold no owned engine references, so freeing the map
	 * is safe at any shutdown point. */
	wb_advice_map_free();
	/* Cached op_arrays own no request state (variables bind per call), so
	 * freeing them here is safe; entries simply recompile next request. */
	wb_code_cache_free_all();
	return SUCCESS;
}

static PHP_MINFO_FUNCTION(winter_boot)
{
	php_info_print_table_start();
	php_info_print_table_header(2, "winter_boot support", "enabled");
	php_info_print_table_row(2, "Version", PHP_WINTER_BOOT_VERSION);
	php_info_print_table_row(2, "native AOP", "zend_execute_ex interception; register via winter_boot_advise()");
	php_info_print_table_row(2, "inline code", "winter_boot_exec_inline() with compilation cache");
	php_info_print_table_row(2, "template expansion", "winter_boot_expand_template() sequential substitution");
	php_info_print_table_end();
}

static const zend_function_entry winter_boot_functions[] = {
	PHP_FE(winter_boot_advise, arginfo_winter_boot_advise)
	PHP_FE(winter_boot_is_advised, arginfo_winter_boot_is_advised)
	PHP_FE(winter_boot_exec_inline, arginfo_winter_boot_exec_inline)
	PHP_FE(winter_boot_expand_template, arginfo_winter_boot_expand_template)
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
