PHP_ARG_ENABLE([winter_boot],
  [whether to enable winter_boot support],
  [AS_HELP_STRING([--enable-winter_boot],
    [Enable winter_boot extension (native capabilities, incl. deferred() support)])])

if test "$PHP_WINTER_BOOT" != "no"; then
  PHP_NEW_EXTENSION(winter_boot, winter_boot.c, $ext_shared)
fi
