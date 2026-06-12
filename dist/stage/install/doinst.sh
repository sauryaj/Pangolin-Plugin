# Slackware post-install hook for unraid-newt-utils.
# Runs once at upgradepkg time, with the package root as $PWD.

# Make sure the rc.d script is executable.
chmod 0755 usr/local/etc/rc.d/rc.newt

# Symlink it into /etc/rc.d only when that's a distinct directory. On stock
# Unraid /etc/rc.d is itself a symlink to /usr/local/etc/rc.d, so the payload
# is already reachable as /etc/rc.d/rc.newt and adding a link would just
# point the file at itself (a symlink loop that breaks the daemon).
if [ ! -e etc/rc.d/rc.newt ]; then
    ( cd etc/rc.d && ln -sf /usr/local/etc/rc.d/rc.newt rc.newt )
fi

# Make all *.sh executable inside the page tree
find usr/local/emhttp/plugins/newt -name "*.sh" -exec chmod 0755 {} \;
