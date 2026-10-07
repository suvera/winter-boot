PHP_ARG_ENABLE([winter_boot],
  [whether to enable winter_boot support],
  [AS_HELP_STRING([--enable-winter_boot],
    [Enable winter_boot extension (native AOP and template evaluation)])])

if test "$PHP_WINTER_BOOT" != "no"; then
  PHP_NEW_EXTENSION(winter_boot, winter_boot.c, $ext_shared)

  dnl Extension version = framework version, read from the repo-root VERSION.txt
  dnl (single source of truth, see the release checklist in AGENTS.md).
  AC_MSG_CHECKING([for winter_boot version])
  WINTER_BOOT_VERSION_FILE="$ext_srcdir/../VERSION.txt"
  if test ! -r "$WINTER_BOOT_VERSION_FILE"; then
    AC_MSG_ERROR([cannot read $WINTER_BOOT_VERSION_FILE; build php-ext/ from a full Winter Boot checkout])
  fi
  WINTER_BOOT_VERSION=`tr -d ' \t\r\n' < "$WINTER_BOOT_VERSION_FILE"`
  if ! echo "$WINTER_BOOT_VERSION" | grep -Eq '^[[0-9]]+\.[[0-9]]+\.[[0-9]]+([[-+]][[0-9A-Za-z.-]]+)?$'; then
    AC_MSG_ERROR([invalid version '$WINTER_BOOT_VERSION' in $WINTER_BOOT_VERSION_FILE])
  fi
  AC_MSG_RESULT([$WINTER_BOOT_VERSION])
  AC_DEFINE_UNQUOTED([PHP_WINTER_BOOT_VERSION], ["$WINTER_BOOT_VERSION"], [winter_boot extension version (from VERSION.txt)])
fi
