#!/bin/sh
# UX.15 closure: launch Playwright's WebKit (WPE MiniBrowser) with locally extracted libraries (no system install)
# and a clean environment (the VS Code snap otherwise leaks its GIO/GTK/locale paths into WebKit's helper processes).
MYDIR="${PLAYWRIGHT_WEBKIT_DIR:-$HOME/.cache/ms-playwright/webkit-2336/minibrowser-wpe}"
EXTRA="${WEBKIT_EXTRA_LIBS:?set to the directory of the unpacked libraries}"
exec env -i HOME="$HOME" PATH=/usr/local/bin:/usr/bin:/bin LANG=C.UTF-8 XDG_RUNTIME_DIR="${XDG_RUNTIME_DIR:-/tmp}" \
  WEBKIT_EXEC_PATH="${MYDIR}/bin" WEBKIT_INJECTED_BUNDLE_PATH="${MYDIR}/lib" WEBKIT_INSPECTOR_RESOURCES_PATH="${MYDIR}/share" \
  LD_LIBRARY_PATH="${MYDIR}/lib:${MYDIR}/sys/lib:${EXTRA}" WEBKIT_FORCE_COMPLEX_TEXT=1 \
  "${MYDIR}/bin/MiniBrowser" "$@"
